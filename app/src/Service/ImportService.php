<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\CompanyRepository;
use App\Repository\ImportRepository;
use App\Repository\TransactionRepository;
use App\Repository\TransactionTypeRepository;
use App\Repository\TransferRepository;
use App\Support\Archive;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ImportService
{
    private const FORMAT_NAME = 'compte-import';
    private const FORMAT_VERSION = 1;
    private const REQUIRED_ENTRIES = ['manifest.json', 'accounts.ndjson', 'categories.ndjson', 'transactions.ndjson'];
    private const MAX_LINE_LENGTH = 1000000;
    private const MAX_RECORDS = 200000;

    public function __construct(
        private ?PDO $pdo,
        private ?ImportRepository $importRepository,
        private ?AccountRepository $accountRepository,
        private ?CategoryRepository $categoryRepository,
        private ?TransactionRepository $transactionRepository,
        private ?TransactionTypeRepository $transactionTypeRepository,
        private ?CompanyRepository $companyRepository,
        private string $storagePath
    ) {
    }

    /**
     * Read an archive and report what it contains without touching the database.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $archivePath): array
    {
        $entries = $this->readArchive($archivePath);
        $manifest = $this->parseManifest($entries, false);

        $accounts = $this->parseNdjson($entries['accounts.ndjson'] ?? '', 'accounts', []);
        $categories = $this->parseNdjson($entries['categories.ndjson'] ?? '', 'categories', []);
        $transactionTypes = $this->parseNdjson($entries['transaction_types.ndjson'] ?? '', 'transaction_types', []);
        $transactions = $this->parseNdjson($entries['transactions.ndjson'] ?? '', 'transactions', []);

        $warnings = [];
        if (isset($entries['report.json'])) {
            $report = json_decode($entries['report.json'], true);
            if (is_array($report) && isset($report['warnings']) && is_array($report['warnings'])) {
                foreach ($report['warnings'] as $warning) {
                    $warnings[] = is_string($warning) ? $warning : json_encode($warning);
                }
            }
        }

        return [
            'path' => $archivePath,
            'sha256' => hash_file('sha256', $archivePath),
            'manifest' => $manifest,
            'counts' => [
                'accounts' => count($accounts),
                'categories' => count($categories),
                'transaction_types' => count($transactionTypes),
                'transactions' => count($transactions),
            ],
            'sample_accounts' => array_slice($accounts, 0, 5),
            'sample_categories' => array_slice($categories, 0, 5),
            'sample_transactions' => array_slice($transactions, 0, 5),
            'warnings' => $warnings,
        ];
    }

    /**
     * Import an archive into a company.
     *
     * @return array<string, mixed>
     */
    public function run(string $archivePath, int $companyId, ?int $userId = null, bool $force = false): array
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('A database connection is required for imports.');
        }
        $this->assertReadableFile($archivePath);
        $sha256 = hash_file('sha256', $archivePath);

        $existing = $this->importRepository->findBySha256($sha256, $companyId);
        if (!$force && $existing !== null && (string) $existing['status'] !== 'failed') {
            return [
                'skipped' => true,
                'message' => 'This archive was already imported.',
                'import_id' => (int) $existing['id'],
                'status' => (string) $existing['status'],
            ];
        }

        $errors = [];
        $warnings = [];
        $attempt = [
            'company_id' => $companyId,
            'user_id' => $userId,
            'source_filename' => basename($archivePath),
            'source_sha256' => $sha256,
            'converter_version' => '',
            'import_format_version' => self::FORMAT_VERSION,
            'status' => 'processing',
        ];
        if ($existing !== null) {
            $importId = (int) $existing['id'];
            $this->importRepository->restart($importId, $attempt);
        } else {
            $importId = $this->importRepository->create($attempt);
        }

        $result = [
            'skipped' => false,
            'import_id' => $importId,
            'status' => 'failed',
            'accounts_created' => 0,
            'accounts_skipped' => 0,
            'categories_created' => 0,
            'categories_skipped' => 0,
            'transaction_types_created' => 0,
            'transaction_types_skipped' => 0,
            'transactions_created' => 0,
            'transactions_skipped' => 0,
            'warnings' => $warnings,
            'errors' => $errors,
        ];

        try {
            $entries = $this->readArchive($archivePath);
            $manifest = $this->parseManifest($entries, true);
            $accounts = $this->parseNdjson($entries['accounts.ndjson'] ?? '', 'accounts', []);
            $categories = $this->parseNdjson($entries['categories.ndjson'] ?? '', 'categories', []);
            $transactionTypes = $this->parseNdjson($entries['transaction_types.ndjson'] ?? '', 'transaction_types', []);
            $transactions = $this->parseNdjson($entries['transactions.ndjson'] ?? '', 'transactions', []);

            if (isset($entries['report.json'])) {
                $report = json_decode($entries['report.json'], true);
                if (is_array($report) && isset($report['warnings']) && is_array($report['warnings'])) {
                    foreach ($report['warnings'] as $warning) {
                        if (is_string($warning)) {
                            $warnings[] = $warning;
                        }
                    }
                }
            }

            $sourceName = (string) ($manifest['source']['filename'] ?? basename($archivePath));
            $this->importRepository->updateMetadata($importId, [
                'source_filename' => $sourceName,
                'converter_version' => (string) ($manifest['converter']['version'] ?? ''),
                'import_format_version' => (int) ($manifest['version'] ?? self::FORMAT_VERSION),
            ]);

            $this->pdo->beginTransaction();
            $result['accounts_created'] = $this->importAccounts($accounts, $companyId, $sourceName, $warnings);
            $result['categories_created'] = $this->importCategories($categories, $companyId, $sourceName, $warnings);
            $result['transaction_types_created'] = $this->importTransactionTypes($transactionTypes, $companyId, $sourceName, $warnings);
            $result['transactions_created'] = $this->importTransactions($transactions, $companyId, $sourceName, $errors, $this->knownTypeCodes($companyId, $sourceName, $transactionTypes), $warnings);
            $this->restoreTransferLinks($transactions, $companyId, $sourceName);

            $this->recordIssues($importId, $warnings, $errors);

            $this->importRepository->updateCounters($importId, [
                'accounts_count' => $result['accounts_created'],
                'transactions_count' => $result['transactions_created'],
                'categories_count' => $result['categories_created'],
                'warnings_count' => count($warnings),
                'errors_count' => count($errors),
            ]);

            $status = count($errors) > 0 ? 'completed_with_errors' : 'completed';
            $this->importRepository->complete($importId, $status);
            $this->companyRepository->updateCounters($companyId);

            $this->pdo->commit();

            $result['status'] = $status;
            $result['warnings'] = $warnings;
            $result['errors'] = $errors;

            return $result;
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            try {
                $this->importRepository->fail($importId);
                $this->importRepository->addError($importId, [
                    'entity_type' => 'import',
                    'severity' => 'error',
                    'message' => $throwable->getMessage(),
                ]);
            } catch (Throwable) {
                // Preserve the domain failure even if recording the failed attempt also fails.
            }

            throw $throwable;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function importAccounts(array $records, int $companyId, string $sourceName, array &$warnings): int
    {
        $created = 0;

        foreach ($records as $index => $record) {
            $legacyId = $this->stringField($record, 'legacy_id');
            if ($legacyId === null || $legacyId === '') {
                $warnings[] = 'accounts[' . $index . ']: missing legacy_id, record skipped.';
                continue;
            }

            $name = trim((string) ($record['name'] ?? ''));
            if ($name === '') {
                $warnings[] = 'accounts[' . $index . '] (legacy_id=' . $legacyId . '): missing name, record skipped.';
                continue;
            }

            if ($this->accountRepository->findByLegacy($companyId, $sourceName, $legacyId) !== null) {
                $warnings[] = 'accounts[' . $index . '] (legacy_id=' . $legacyId . '): already present, skipped.';
                continue;
            }

            $currency = strtoupper(substr(trim((string) ($record['currency'] ?? 'EUR')), 0, 3));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'EUR';
            }

            $this->accountRepository->create([
                'company_id' => $companyId,
                'user_id' => null,
                'name' => $name,
                'bank_name' => trim((string) ($record['bank_name'] ?? '')),
                'account_number' => trim((string) ($record['account_number'] ?? '')),
                'description' => trim((string) ($record['description'] ?? '')),
                'currency' => $currency,
                'initial_balance' => $this->decimalField($record, 'initial_balance', '0.00'),
                'is_archived' => !empty($record['is_archived']) ? 1 : 0,
                'legacy_source' => $sourceName,
                'legacy_id' => $legacyId,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function importCategories(array $records, int $companyId, string $sourceName, array &$warnings): int
    {
        $created = 0;
        $idMap = [];

        foreach ($records as $index => $record) {
            $legacyId = $this->stringField($record, 'legacy_id');
            if ($legacyId === null || $legacyId === '') {
                $warnings[] = 'categories[' . $index . ']: missing legacy_id, record skipped.';
                continue;
            }

            $name = trim((string) ($record['name'] ?? ''));
            if ($name === '') {
                $warnings[] = 'categories[' . $index . '] (legacy_id=' . $legacyId . '): missing name, record skipped.';
                continue;
            }

            if ($this->categoryRepository->findByLegacy($companyId, $sourceName, $legacyId) !== null) {
                $warnings[] = 'categories[' . $index . '] (legacy_id=' . $legacyId . '): already present, skipped.';
                continue;
            }

            $id = $this->categoryRepository->create([
                'company_id' => $companyId,
                'parent_id' => '',
                'name' => $name,
                'emoji' => trim((string) ($record['emoji'] ?? '')),
                'category_type' => trim((string) ($record['category_type'] ?? '')),
                'is_active' => (bool) ($record['is_active'] ?? true) ? 1 : 0,
                'sort_order' => (int) ($record['sort_order'] ?? 0),
                'legacy_source' => $sourceName,
                'legacy_id' => $legacyId,
            ]);
            $idMap[$legacyId] = $id;
            $created++;
        }

        foreach ($records as $index => $record) {
            $legacyId = $this->stringField($record, 'legacy_id');
            $parentLegacyId = $this->stringField($record, 'parent_legacy_id');
            if ($legacyId === null || $parentLegacyId === null || !isset($idMap[$legacyId])) {
                continue;
            }

            $parentId = $idMap[$parentLegacyId] ?? null;
            if ($parentId === null) {
                $warnings[] = 'categories[' . $index . '] (legacy_id=' . $legacyId . '): parent legacy_id ' . $parentLegacyId . ' not found, left without parent.';
            }
            $this->categoryRepository->updateParent($idMap[$legacyId], $companyId, $parentId);
        }

        return $created;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function importTransactions(array $records, int $companyId, string $sourceName, array &$errors, array $knownTypes = [], array &$warnings = []): int
    {
        $created = 0;
        $known = [];
        foreach ($knownTypes as $knownType) {
            $known[strtoupper(trim((string) $knownType))] = true;
        }

        foreach ($records as $index => $record) {
            $legacyId = $this->stringField($record, 'legacy_id');
            $legacyTable = strtoupper(trim((string) ($record['legacy_table'] ?? 'ECRITURE')));
            if ($legacyId === null || $legacyId === '') {
                $errors[] = 'transactions[' . $index . ']: missing legacy_id, record skipped.';
                continue;
            }

            $accountLegacyId = $this->stringField($record, 'account_legacy_id');
            $account = $accountLegacyId !== null ? $this->accountRepository->findByLegacy($companyId, $sourceName, $accountLegacyId) : null;
            if ($account === null) {
                $errors[] = 'transactions[' . $index . '] (legacy_id=' . $legacyId . '): account_legacy_id ' . ($accountLegacyId ?? '?') . ' not found, record skipped.';
                continue;
            }

            $transactionDate = trim((string) ($record['transaction_date'] ?? ''));
            if (!$this->isIsoDate($transactionDate)) {
                $errors[] = 'transactions[' . $index . '] (legacy_id=' . $legacyId . '): invalid or missing transaction_date, record skipped.';
                continue;
            }

            $label = trim((string) ($record['label'] ?? ''));
            if ($label === '') {
                $errors[] = 'transactions[' . $index . '] (legacy_id=' . $legacyId . '): missing label, record skipped.';
                continue;
            }

            $amount = $this->decimalField($record, 'amount', '');
            if ($amount === '' || (float) $amount === 0.0) {
                $errors[] = 'transactions[' . $index . '] (legacy_id=' . $legacyId . '): invalid or zero amount, record skipped.';
                continue;
            }

            if ($this->transactionRepository->findByLegacy($companyId, $sourceName, $legacyTable, $legacyId) !== null) {
                $errors[] = 'transactions[' . $index . '] (legacy_id=' . $legacyId . '): already present, skipped.';
                continue;
            }

            $category = null;
            $categoryLegacyId = $this->stringField($record, 'category_legacy_id');
            if ($categoryLegacyId !== null) {
                $category = $this->categoryRepository->findByLegacy($companyId, $sourceName, $categoryLegacyId);
            }

            $valueDate = trim((string) ($record['value_date'] ?? ''));
            if ($valueDate !== '' && !$this->isIsoDate($valueDate)) {
                $valueDate = '';
            }

            $type = isset($record['type']) ? trim((string) $record['type']) : '';
            if ($type !== '' && !isset($known[strtoupper($type)]) && $type !== '') {
                $warnings[] = 'transactions[' . $index . '] (legacy_id=' . $legacyId . '): type "' . $type . '" is not in the transaction types reference, kept as free value.';
            }

            $this->transactionRepository->create([
                'company_id' => $companyId,
                'account_id' => (int) $account['id'],
                'category_id' => $category !== null ? (int) $category['id'] : '',
                'transaction_date' => $transactionDate,
                'value_date' => $valueDate,
                'label' => $label,
                'description' => trim((string) ($record['description'] ?? '')),
                'amount' => $amount,
                'is_reconciled' => !empty($record['is_reconciled']) ? 1 : 0,
                'is_archived' => !empty($record['is_archived']) ? 1 : 0,
                'type' => $type,
                'legacy_source' => $sourceName,
                'legacy_table' => $legacyTable,
                'legacy_id' => $legacyId,
                'legacy_payload' => is_array($record) ? $record : null,
            ]);
            $created++;
        }

        return $created;
    }

    /** @param list<array<string, mixed>> $records */
    private function restoreTransferLinks(array $records, int $companyId, string $sourceName): void
    {
        $groups = [];
        foreach ($records as $record) {
            $transferKey = $this->stringField($record, 'transfer_legacy_id');
            if ($transferKey === null || $transferKey === '') {
                continue; // Older archives have no transfer links.
            }
            $legacyId = $this->stringField($record, 'legacy_id');
            $found = $legacyId !== null ? $this->transactionRepository->findByLegacy(
                $companyId, $sourceName, strtoupper(trim((string) ($record['legacy_table'] ?? 'ECRITURE'))), $legacyId
            ) : null;
            if ($found === null) {
                throw new InvalidArgumentException('Cannot restore an incomplete transfer: ' . $transferKey);
            }
            $groups[$transferKey][] = $this->transactionRepository->findInCompany((int) $found['id'], $companyId);
        }

        $transfers = new TransferRepository($this->pdo);
        foreach ($groups as $key => $entries) {
            if (count($entries) !== 2 || $entries[0] === null || $entries[1] === null) {
                throw new InvalidArgumentException('A transfer must contain exactly two entries: ' . $key);
            }
            usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['amount'], (string) $b['amount']));
            [$source, $destination] = $entries;
            if (!str_starts_with((string) $source['amount'], '-')
                || str_starts_with((string) $destination['amount'], '-')
                || ltrim((string) $source['amount'], '-') !== (string) $destination['amount']
                || (int) $source['account_id'] === (int) $destination['account_id']
                || $source['transaction_date'] !== $destination['transaction_date']
                || (int) $source['is_archived'] !== (int) $destination['is_archived']) {
                throw new InvalidArgumentException('Cannot restore an inconsistent transfer: ' . $key);
            }
            if (!empty($source['transfer_id']) || !empty($destination['transfer_id'])) {
                if ($source['transfer_id'] !== $destination['transfer_id']) {
                    throw new InvalidArgumentException('Conflicting transfer links: ' . $key);
                }
                continue;
            }
            $transferId = $transfers->create([
                'company_id' => $companyId,
                'source_account_id' => (int) $source['account_id'],
                'destination_account_id' => (int) $destination['account_id'],
                'amount' => (string) $destination['amount'],
                'transaction_date' => (string) $source['transaction_date'],
                'label' => (string) $source['label'],
            ]);
            $statement = $this->pdo->prepare('UPDATE transactions SET transfer_id = :transfer_id WHERE id = :id AND company_id = :company_id');
            foreach ($entries as $entry) {
                $statement->execute(['transfer_id' => $transferId, 'id' => (int) $entry['id'], 'company_id' => $companyId]);
            }
        }
    }

    /** @return array<string, string> */
    private function knownTypeCodes(int $companyId, string $sourceName, array $transactionTypes): array
    {
        $codes = [];
        foreach ($transactionTypes as $type) {
            $code = strtoupper(trim((string) ($type['code'] ?? '')));
            if ($code !== '') {
                $codes[$code] = $code;
            }
        }

        foreach ($this->transactionTypeRepository->allInCompany($companyId) as $existing) {
            $code = strtoupper(trim((string) ($existing['code'] ?? '')));
            if ($code !== '') {
                $codes[$code] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function importTransactionTypes(array $records, int $companyId, string $sourceName, array &$warnings): int
    {
        $created = 0;

        foreach ($records as $index => $record) {
            $code = strtoupper(trim((string) ($record['code'] ?? '')));
            if ($code === '') {
                $warnings[] = 'transaction_types[' . $index . ']: missing code, record skipped.';
                continue;
            }

            if ($this->transactionTypeRepository->findByLegacy($companyId, $sourceName, $code) !== null) {
                $warnings[] = 'transaction_types[' . $index . '] (code=' . $code . '): already present, skipped.';
                continue;
            }

            if ($this->transactionTypeRepository->findByCode($companyId, $code) !== null) {
                $warnings[] = 'transaction_types[' . $index . '] (code=' . $code . '): already present in another import, skipped.';
                continue;
            }

            $this->transactionTypeRepository->create([
                'company_id' => $companyId,
                'code' => $code,
                'label' => trim((string) ($record['label'] ?? '')) ?: $code,
                'sort_order' => (int) ($record['sort_order'] ?? 0),
                'is_active' => (bool) ($record['is_active'] ?? true) ? 1 : 0,
                'legacy_source' => $sourceName,
                'legacy_id' => $code,
            ]);
            $created++;
        }

        return $created;
    }

    private function recordIssues(int $importId, array $warnings, array $errors): void
    {
        foreach ($errors as $error) {
            $this->importRepository->addError($importId, [
                'entity_type' => 'transaction',
                'severity' => 'error',
                'message' => $error,
            ]);
        }
        foreach ($warnings as $warning) {
            $this->importRepository->addError($importId, [
                'entity_type' => 'import',
                'severity' => 'warning',
                'message' => $warning,
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function readArchive(string $archivePath): array
    {
        $this->assertReadableFile($archivePath);

        try {
            $entries = Archive::read($archivePath);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException('Could not read archive: ' . $exception->getMessage());
        }

        foreach (self::REQUIRED_ENTRIES as $entry) {
            if (!isset($entries[$entry])) {
                throw new InvalidArgumentException('Invalid archive: missing required entry "' . $entry . '".');
            }
        }

        return $entries;
    }

    /**
     * @param array<string, string> $entries
     * @return array<string, mixed>
     */
    private function parseManifest(array $entries, bool $strict): array
    {
        $decoded = json_decode($entries['manifest.json'], true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid archive: manifest.json is not valid JSON.');
        }

        $format = (string) ($decoded['format'] ?? '');
        if (!in_array($format, [self::FORMAT_NAME, 'compte-export'], true)) {
            throw new InvalidArgumentException('Invalid archive: unexpected format "' . $format . '".');
        }

        $version = (int) ($decoded['version'] ?? 0);
        if ($version > self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Invalid archive: format version ' . $version . ' is newer than supported version ' . self::FORMAT_VERSION . '.');
        }
        if ($strict && $version < 1) {
            throw new InvalidArgumentException('Invalid archive: format version is missing.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $fieldHints
     * @return array<int, array<string, mixed>>
     */
    private function parseNdjson(string $content, string $entityType, array $fieldHints): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $records = [];
        $seenIds = [];
        $lineNumber = 0;
        foreach (preg_split('/\r\n|\r|\n/', $content, self::MAX_RECORDS + 1) as $line) {
            $lineNumber++;
            if ($lineNumber > self::MAX_RECORDS) {
                throw new InvalidArgumentException('Archive record count exceeds the allowed limit.');
            }
            if ($line === '') {
                continue;
            }
            if (strlen($line) > self::MAX_LINE_LENGTH) {
                throw new InvalidArgumentException('Invalid archive: ' . $entityType . '.ndjson line ' . $lineNumber . ' is too long.');
            }

            $record = json_decode($line, true);
            if (!is_array($record)) {
                throw new InvalidArgumentException('Invalid archive: ' . $entityType . '.ndjson line ' . $lineNumber . ' is not valid JSON.');
            }

            $legacyId = $this->stringField($record, 'legacy_id');
            if ($legacyId !== null && $legacyId !== '' && in_array($entityType, ['accounts', 'categories', 'transactions'], true)) {
                $table = $entityType === 'transactions' ? strtoupper(trim((string) ($record['legacy_table'] ?? 'ECRITURE'))) : '';
                $key = json_encode([$table, $legacyId], JSON_THROW_ON_ERROR);
                if (isset($seenIds[$key])) {
                    throw new InvalidArgumentException('Invalid archive: duplicate ' . $entityType . ' legacy_id "' . $legacyId . '" at line ' . $lineNumber . '.');
                }
                $seenIds[$key] = true;
            }

            $record['_line'] = $lineNumber;
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function stringField(array $record, string $key): ?string
    {
        if (!isset($record[$key])) {
            return null;
        }

        $value = $record[$key];
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return trim($value);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function decimalField(array $record, string $key, string $default): string
    {
        $value = $this->stringField($record, $key);
        if ($value === null || $value === '') {
            return $default;
        }

        $value = str_replace([',', ' '], ['.', ''], $value);
        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return $default;
        }

        return $value;
    }

    private function assertReadableFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Archive file is not readable: ' . $path);
        }
        $maxSize = (int) ($_ENV['IMPORT_MAX_SIZE'] ?? getenv('IMPORT_MAX_SIZE') ?: 10_485_760);
        $fileSize = filesize($path);
        if ($maxSize < 1 || $fileSize === false || $fileSize > $maxSize) {
            throw new InvalidArgumentException('Archive file exceeds the allowed size.');
        }
    }

    private function isIsoDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
