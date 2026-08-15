<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Archive;
use InvalidArgumentException;
use RuntimeException;

/**
 * Converts the CSV exports produced by the legacy Windows "Compte" tool
 * into a `compte-import` archive that ImportService can consume.
 *
 * One CSV file is expected per legacy table, with the exact column headers
 * used by the legacy application:
 *
 *   BANQUE.csv, CASH.csv, ECRITURE.csv, ARCHIVE.csv,
 *   THEME1.csv, THEME2.csv, TYPE.csv, VIREMENT.csv
 *
 * Mapping decisions (validated against the FINANCIERE SG export):
 *
 *   - BANQUE      -> accounts, amount is EUR, initial_balance = 0.00
 *   - THEME1      -> parent categories
 *   - THEME2      -> child categories (parent_legacy_id = THEME1)
 *   - ECRITURE    -> active transactions (is_archived = 0)
 *   - ARCHIVE     -> archived transactions (is_archived = 1)
 *   - ECRITURE rows with the ARCHIVE flag set (year-end carry-forward
 *     "Archivage" entries) are skipped because their amount duplicates the
 *     total of the ARCHIVE table.
 *   - CASH, TYPE, VIREMENT -> informational only, not imported (VIREMENT
 *     becomes recurring rules in a later phase).
 */
final class LegacyCsvConverter
{
    private const FORMAT_NAME = 'compte-import';
    private const FORMAT_VERSION = 1;
    private const CONVERTER_NAME = 'compte-csv-converter';
    private const CONVERTER_VERSION = '1.0.0';

    /**
     * Convert legacy CSVs into a compte-import ZIP.
     *
     * Accepts either a directory containing the CSV files or a ZIP archive
     * that contains them (flat entries named like BANQUE.csv, ARCHIVE.csv ...).
     *
     * @return array<string, mixed> summary with keys: path, counts, warnings
     */
    public function convert(string $source, string $outputPath): array
    {
        $directory = $this->resolveSource($source);
        $warnings = [];

        try {
            return $this->convertDirectory($directory, $outputPath, $this->sourceName($source));
        } finally {
            if ($directory !== $source) {
                $this->removeTree($directory);
            }
        }
    }

    /**
     * @return array<string, mixed> summary with keys: path, counts, warnings
     */
    private function convertDirectory(string $directory, string $outputPath, string $sourceName = ''): array
    {
        $files = $this->locateFiles($directory);
        $warnings = [];

        $banque = $this->readCsv($files['BANQUE']);
        $ecriture = $this->readCsv($files['ECRITURE']);
        $archive = $this->readCsv($files['ARCHIVE']);
        $theme1 = $this->readCsv($files['THEME1']);
        $theme2 = $this->readCsv($files['THEME2']);
        $types = isset($files['TYPE']) ? $this->readCsv($files['TYPE']) : [];

        $accounts = $this->mapAccounts($banque, $warnings);
        $categories = $this->mapCategories($theme1, $theme2, $warnings);
        $transactionTypes = $this->mapTransactionTypes($types, $warnings);

        $parentCategories = $this->categoryLookup($categories);
        $transactions = $this->mapTransactions($ecriture, $archive, $parentCategories, $warnings);

        $sourceName = $sourceName !== '' ? $sourceName : basename(rtrim($directory, '/\\'));
        $counts = [
            'accounts' => count($accounts),
            'categories' => count($categories),
            'transaction_types' => count($transactionTypes),
            'transactions' => count($transactions),
        ];

        $entries = [
            'manifest.json' => $this->json([
                'format' => self::FORMAT_NAME,
                'version' => self::FORMAT_VERSION,
                'generated_at' => date('c'),
                'converter' => [
                    'name' => self::CONVERTER_NAME,
                    'version' => self::CONVERTER_VERSION,
                ],
                'source' => [
                    'filename' => $sourceName,
                ],
                'counts' => $counts,
            ]),
            'accounts.ndjson' => $this->ndjson($accounts),
            'categories.ndjson' => $this->ndjson($categories),
            'transaction_types.ndjson' => $this->ndjson($transactionTypes),
            'transactions.ndjson' => $this->ndjson($transactions),
            'report.json' => $this->json([
                'format' => 'compte-import-report',
                'version' => 1,
                'warnings' => $warnings,
            ]),
        ];

        Archive::write($entries, $outputPath);

        return [
            'path' => $outputPath,
            'source' => $sourceName,
            'counts' => $counts,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function locateFiles(string $directory): array
    {
        $expected = ['BANQUE', 'CASH', 'ECRITURE', 'ARCHIVE', 'THEME1', 'THEME2', 'TYPE', 'VIREMENT'];
        $found = [];

        foreach (glob(rtrim($directory, '/\\') . '/*.csv') ?: [] as $path) {
            $key = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            if (in_array($key, $expected, true)) {
                $found[$key] = $path;
            }
        }

        foreach (['BANQUE', 'ECRITURE', 'ARCHIVE', 'THEME1', 'THEME2'] as $required) {
            if (!isset($found[$required])) {
                throw new InvalidArgumentException('Missing required CSV file: ' . $required . '.csv');
            }
        }

        return $found;
    }

    /**
     * Read a CSV file into an array of associative rows using its header.
     *
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open CSV file: ' . $path);
        }

        $rows = [];
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false || $header === null) {
                throw new RuntimeException('CSV file has no header row: ' . $path);
            }
            $header = array_map(static fn (string $name): string => trim($name), $header);

            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($fields === [null]) {
                    continue;
                }
                $row = [];
                foreach ($header as $index => $name) {
                    $row[$name] = isset($fields[$index]) ? trim((string) $fields[$index]) : '';
                }
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $banque
     * @param array<int, string> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function mapAccounts(array $banque, array &$warnings): array
    {
        $accounts = [];
        $seen = [];

        foreach ($banque as $index => $row) {
            $legacyId = $this->legacyId($row, 'NUMERO');
            if ($legacyId === '') {
                $warnings[] = 'BANQUE[' . $index . ']: missing NUMERO, record skipped.';
                continue;
            }
            if (isset($seen[$legacyId])) {
                $warnings[] = 'BANQUE[' . $index . ']: duplicate NUMERO ' . $legacyId . ', record skipped.';
                continue;
            }
            $seen[$legacyId] = true;

            $name = trim((string) ($row['LIBELLE'] ?? ''));
            if ($name === '') {
                $name = 'Compte ' . $legacyId;
            }

            $holders = array_values(array_filter([
                $this->field($row, 'TITULAIRE1'),
                $this->field($row, 'TITULAIRE2'),
                $this->field($row, 'TITULAIRE3'),
                $this->field($row, 'TITULAIRE4'),
            ], static fn (string $value): bool => $value !== ''));

            $accounts[] = [
                'legacy_id' => $legacyId,
                'name' => $name,
                'bank_name' => $this->field($row, 'NOMBANQUE'),
                'account_number' => $this->field($row, 'COMPTE'),
                'description' => implode(', ', $holders),
                'currency' => 'EUR',
                'initial_balance' => '0.00',
                'is_archived' => false,
            ];
        }

        if ($accounts === []) {
            $warnings[] = 'BANQUE: no account rows found, archive would be empty.';
        }

        return $accounts;
    }

    /**
     * @param array<int, array<string, string>> $theme1
     * @param array<int, array<string, string>> $theme2
     * @param array<int, string> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function mapCategories(array $theme1, array $theme2, array &$warnings): array
    {
        $categories = [];
        $seen = [];
        $parentKeys = [];
        $sort = 0;

        foreach ($theme1 as $index => $row) {
            $name = trim((string) ($row['THEME1'] ?? ''));
            if ($name === '') {
                $warnings[] = 'THEME1[' . $index . ']: empty theme name, record skipped.';
                continue;
            }
            $name = $this->normalizeName($name);
            $key = $this->lookupKey($name);
            if (isset($seen[$key])) {
                $warnings[] = 'THEME1[' . $index . ']: duplicate theme "' . $name . '", record skipped.';
                continue;
            }
            $seen[$key] = true;
            $parentKeys[$key] = $name;

            $categories[] = [
                'legacy_id' => $name,
                'parent_legacy_id' => null,
                'name' => $name,
                'category_type' => '',
                'is_active' => true,
                'sort_order' => $sort++,
            ];
        }

        foreach ($theme2 as $index => $row) {
            $parent = $this->normalizeName((string) ($row['THEME1'] ?? ''));
            $child = $this->normalizeName((string) ($row['THEME2'] ?? ''));
            if ($parent === '' || $child === '') {
                $warnings[] = 'THEME2[' . $index . ']: incomplete pair, record skipped.';
                continue;
            }
            if (!isset($parentKeys[$this->lookupKey($parent)])) {
                $warnings[] = 'THEME2[' . $index . ']: parent "' . $parent . '" not found in THEME1, record skipped.';
                continue;
            }

            $legacyId = $parent . '|' . $child;
            $key = $this->lookupKey($legacyId);
            if (isset($seen[$key])) {
                $warnings[] = 'THEME2[' . $index . ']: duplicate pair "' . $legacyId . '", record skipped.';
                continue;
            }
            $seen[$key] = true;

            $categories[] = [
                'legacy_id' => $legacyId,
                'parent_legacy_id' => $parent,
                'name' => $child,
                'category_type' => '',
                'is_active' => true,
                'sort_order' => $sort++,
            ];
        }

        return $categories;
    }

    /**
     * Map the legacy TYPE reference table into transaction type records.
     *
     * @param array<int, array<string, string>> $rows
     * @param array<int, string> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function mapTransactionTypes(array $rows, array &$warnings): array
    {
        $types = [];
        $seen = [];
        $sort = 1;

        foreach ($rows as $index => $row) {
            $code = strtoupper(trim((string) ($row['TYPE'] ?? '')));
            if ($code === '') {
                $warnings[] = 'TYPE[' . $index . ']: empty code, record skipped.';
                continue;
            }
            if (isset($seen[$code])) {
                $warnings[] = 'TYPE[' . $index . ']: duplicate code "' . $code . '", record skipped.';
                continue;
            }
            $seen[$code] = true;

            $types[] = [
                'legacy_id' => $code,
                'code' => $code,
                'label' => $code,
                'sort_order' => $sort++,
                'is_active' => true,
            ];
        }

        return $types;
    }

    /**
     * Build a case-insensitive lookup from a normalized theme reference to a
     * category legacy_id ("parent" or "parent|child").
     *
     * @param array<int, array<string, mixed>> $categories
     * @return array<string, string>
     */
    private function categoryLookup(array $categories): array
    {
        $lookup = [];
        foreach ($categories as $category) {
            $lookup[$this->lookupKey((string) $category['legacy_id'])] = (string) $category['legacy_id'];
        }

        return $lookup;
    }

    /**
     * @param array<int, array<string, string>> $ecriture
     * @param array<int, array<string, string>> $archive
     * @param array<string, string> $categoryLookup
     * @param array<int, string> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function mapTransactions(array $ecriture, array $archive, array $categoryLookup, array &$warnings): array
    {
        $transactions = [];
        $line = 0;

        foreach ($ecriture as $index => $row) {
            if ($this->flag($row, 'ARCHIVE')) {
                $warnings[] = 'ECRITURE[' . $index . ']: skipped year-end "Archivage" carry-forward row.';
                continue;
            }
            $line++;
            $transaction = $this->mapTransaction($row, 'ECRITURE', 'E' . $line, $categoryLookup, $warnings);
            if ($transaction !== null) {
                $transactions[] = $transaction;
            }
        }

        foreach ($archive as $index => $row) {
            $line++;
            $transaction = $this->mapTransaction($row, 'ARCHIVE', 'A' . $line, $categoryLookup, $warnings, true);
            if ($transaction !== null) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $categoryLookup
     * @param array<int, string> $warnings
     * @return array<string, mixed>|null
     */
    private function mapTransaction(array $row, string $legacyTable, string $legacyId, array $categoryLookup, array &$warnings, bool $archived = false): ?array
    {
        $accountLegacyId = $this->legacyId($row, 'NUM_COMPTE');
        if ($accountLegacyId === '') {
            $warnings[] = $legacyTable . '[' . $legacyId . ']: missing NUM_COMPTE, record skipped.';
            return null;
        }

        $amount = $this->euroAmount($row, $warnings, $legacyTable, $legacyId);
        if ($amount === null || (float) $amount === 0.0) {
            $warnings[] = $legacyTable . '[' . $legacyId . ']: missing or zero amount, record skipped.';
            return null;
        }

        $date = $this->datePart((string) ($row['DT_ECRITURE'] ?? ''));
        if ($date === '') {
            $warnings[] = $legacyTable . '[' . $legacyId . ']: invalid date, record skipped.';
            return null;
        }

        $label = $this->label((string) ($row['COMMENT'] ?? ''), (string) ($row['THEME1'] ?? ''), $archived);
        $categoryLegacyId = $this->categoryLegacyId($row, $categoryLookup);

        return [
            'legacy_id' => $legacyId,
            'legacy_table' => $legacyTable,
            'account_legacy_id' => $accountLegacyId,
            'category_legacy_id' => $categoryLegacyId,
            'transaction_date' => $date,
            'value_date' => '',
            'label' => $label,
            'description' => $this->field($row, 'PIECE'),
            'amount' => $amount,
            'is_reconciled' => $this->flag($row, 'COCHE'),
            'is_archived' => $archived,
            'type' => $this->field($row, 'TYPE'),
            'legacy_payload' => [
                'type' => $this->field($row, 'TYPE'),
                'montant_frf' => $this->field($row, 'MONTANT'),
                'saisie_euro' => $this->field($row, 'SAISIE_EURO'),
                'theme1' => $this->field($row, 'THEME1'),
                'theme2' => $this->field($row, 'THEME2'),
                'comment' => $this->field($row, 'COMMENT'),
                'piece' => $this->field($row, 'PIECE'),
                'coche' => $this->field($row, 'COCHE'),
            ],
        ];
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $categoryLookup
     */
    private function categoryLegacyId(array $row, array $categoryLookup): ?string
    {
        $theme1 = $this->normalizeName((string) ($row['THEME1'] ?? ''));
        if ($theme1 === '') {
            return null;
        }

        $theme2 = $this->normalizeName((string) ($row['THEME2'] ?? ''));
        $candidate = $theme2 !== '' ? $theme1 . '|' . $theme2 : $theme1;

        return $categoryLookup[$this->lookupKey($candidate)] ?? null;
    }

    /**
     * @param array<string, string> $row
     * @param array<int, string> $warnings
     */
    private function euroAmount(array $row, array &$warnings, string $table, string $legacyId): ?string
    {
        $raw = trim((string) ($row['MONTANT_EURO'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $value = (float) str_replace([',', ' '], ['.', ''], $raw);
        if (!is_finite($value)) {
            $warnings[] = $table . '[' . $legacyId . ']: amount is not numeric, record skipped.';
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    private function label(string $comment, string $theme1, bool $archived): string
    {
        $comment = trim($comment);
        if ($comment !== '') {
            return $comment;
        }

        $theme1 = trim($theme1);
        if ($theme1 !== '') {
            return $theme1;
        }

        return $archived ? 'Archivage' : 'Sans libellé';
    }

    private function datePart(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    /**
     * @param array<string, string> $row
     */
    private function field(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    /**
     * @param array<string, string> $row
     */
    private function legacyId(array $row, string $key): string
    {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value === '') {
            return '';
        }

        return (string) ((int) $value);
    }

    /**
     * @param array<string, string> $row
     */
    private function flag(array $row, string $key): bool
    {
        $value = trim((string) ($row[$key] ?? ''));
        return $value === '1' || strtolower($value) === 'true';
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return $name;
    }

    private function lookupKey(string $value): string
    {
        return strtolower($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function ndjson(array $records): string
    {
        $lines = [];
        foreach ($records as $record) {
            $lines[] = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    private function sourceName(string $source): string
    {
        if (is_file($source)) {
            return pathinfo($source, PATHINFO_FILENAME);
        }

        return basename(rtrim($source, '/\\'));
    }

    /**
     * Return a directory containing the CSV files. When the source is a ZIP
     * archive the entries are extracted into a temporary directory.
     */
    private function resolveSource(string $source): string
    {
        if (is_dir($source) && is_readable($source)) {
            return $source;
        }

        if (is_file($source) && is_readable($source) && strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'zip') {
            $directory = sys_get_temp_dir() . '/compte-csv-' . bin2hex(random_bytes(6));
            if (!mkdir($directory, 0755, true)) {
                throw new RuntimeException('Could not create temporary directory for the CSV archive.');
            }

            try {
                $entries = Archive::read($source);
            } catch (RuntimeException $exception) {
                $this->removeTree($directory);
                throw new InvalidArgumentException('Could not read CSV archive: ' . $exception->getMessage());
            }

            foreach ($entries as $name => $content) {
                $basename = basename($name);
                if ($basename === '' || $basename !== $name || str_contains($basename, '\\')) {
                    $this->removeTree($directory);
                    throw new InvalidArgumentException('CSV archive contains an unexpected entry: ' . $name);
                }
                if (file_put_contents($directory . '/' . $basename, $content) === false) {
                    $this->removeTree($directory);
                    throw new RuntimeException('Could not extract CSV archive entry: ' . $basename);
                }
            }

            return $directory;
        }

        throw new InvalidArgumentException('Source is not a readable directory or ZIP file: ' . $source);
    }

    private function removeTree(string $directory): void
    {
        foreach (glob(rtrim($directory, '/\\') . '/*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
