<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\CompanyRepository;
use App\Repository\TransactionRepository;
use App\Repository\TransactionTypeRepository;
use App\Support\Archive;
use InvalidArgumentException;
use PDO;

final class ExportService
{
    private const FORMAT_NAME = 'compte-export';
    private const FORMAT_VERSION = 1;

    public function __construct(
        private PDO $pdo,
        private CompanyRepository $companyRepository,
        private AccountRepository $accountRepository,
        private CategoryRepository $categoryRepository,
        private TransactionRepository $transactionRepository,
        private TransactionTypeRepository $transactionTypeRepository,
        private string $storagePath
    ) {
    }

    /**
     * Build an export archive on disk.
     *
     * @return array<string, string> keys: filename, path
     */
    public function exportCompany(int $companyId): array
    {
        $company = $this->companyRepository->find($companyId);
        if ($company === null) {
            throw new InvalidArgumentException('Company not found.');
        }

        $timestamp = date('Ymd-His');
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($company->name())) ?: 'company';
        $slug = trim($slug, '-') ?: 'company';
        $filename = $slug . '-' . $companyId . '-' . $timestamp . '-' . bin2hex(random_bytes(4)) . '.compte.zip';
        $path = rtrim($this->storagePath, '/') . '/exports/' . $filename;

        $accounts = $this->allAccounts($companyId);
        $categories = $this->allCategories($companyId);
        $transactionTypes = $this->allTransactionTypes($companyId);
        $transactions = $this->allTransactions($companyId);

        $entries = [
            'manifest.json' => $this->json([
                'format' => self::FORMAT_NAME,
                'version' => self::FORMAT_VERSION,
                'generated_at' => date('c'),
                'company' => [
                    'id' => $companyId,
                    'name' => $company->name(),
                ],
                'counts' => [
                    'accounts' => count($accounts),
                    'categories' => count($categories),
                    'transaction_types' => count($transactionTypes),
                    'transactions' => count($transactions),
                ],
            ]),
            'accounts.ndjson' => $this->ndjson($accounts),
            'categories.ndjson' => $this->ndjson($categories),
            'transaction_types.ndjson' => $this->ndjson($transactionTypes),
            'transactions.ndjson' => $this->ndjson($transactions),
            'report.json' => $this->json([
                'format' => 'compte-export-report',
                'version' => 1,
                'warnings' => [],
            ]),
        ];

        Archive::write($entries, $path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allAccounts(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, bank_name, account_number, description, currency, initial_balance, is_archived, legacy_source, legacy_id
             FROM accounts
             WHERE company_id = :company_id
             ORDER BY id ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'legacy_id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'bank_name' => (string) ($row['bank_name'] ?? ''),
                'account_number' => (string) ($row['account_number'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'currency' => (string) ($row['currency'] ?? 'EUR'),
                'initial_balance' => (string) $row['initial_balance'],
                'is_archived' => (bool) ((int) $row['is_archived'] === 1),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allCategories(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, parent_id, name, emoji, category_type, is_active, sort_order, legacy_source, legacy_id
             FROM categories
             WHERE company_id = :company_id
             ORDER BY sort_order ASC, name ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'legacy_id' => (string) $row['id'],
                'parent_legacy_id' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
                'name' => (string) $row['name'],
                'emoji' => (string) ($row['emoji'] ?? ''),
                'category_type' => (string) ($row['category_type'] ?? ''),
                'is_active' => (bool) ((int) $row['is_active'] === 1),
                'sort_order' => (int) $row['sort_order'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allTransactionTypes(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, label, sort_order, is_active, legacy_source, legacy_id
             FROM transaction_types
             WHERE company_id = :company_id
             ORDER BY sort_order ASC, code ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'legacy_id' => (string) $row['code'],
                'code' => (string) $row['code'],
                'label' => (string) ($row['label'] ?? ''),
                'sort_order' => (int) $row['sort_order'],
                'is_active' => (bool) ((int) $row['is_active'] === 1),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allTransactions(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.transaction_date, t.value_date, t.label, t.description, t.amount, t.is_reconciled, t.is_archived, t.type,
                    t.account_id, t.category_id, t.transfer_id, t.legacy_source, t.legacy_table, t.legacy_id, t.legacy_payload
             FROM transactions t
             WHERE t.company_id = :company_id
             ORDER BY t.transaction_date ASC, t.id ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'legacy_id' => (string) $row['id'],
                'legacy_table' => 'ECRITURE',
                'account_legacy_id' => (string) $row['account_id'],
                'category_legacy_id' => $row['category_id'] !== null ? (string) $row['category_id'] : null,
                'transfer_legacy_id' => $row['transfer_id'] !== null ? (string) $row['transfer_id'] : null,
                'transaction_date' => (string) $row['transaction_date'],
                'value_date' => $row['value_date'] !== null ? (string) $row['value_date'] : '',
                'label' => (string) $row['label'],
                'description' => (string) ($row['description'] ?? ''),
                'amount' => (string) $row['amount'],
                'is_reconciled' => (bool) ((int) $row['is_reconciled'] === 1),
                'is_archived' => (bool) ((int) $row['is_archived'] === 1),
                'type' => isset($row['type']) && $row['type'] !== null && $row['type'] !== '' ? (string) $row['type'] : '',
            ];
        }

        return $rows;
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
}
