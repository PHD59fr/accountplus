<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class AccountRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO accounts (
                company_id,
                user_id,
                name,
                bank_name,
                account_number,
                description,
                currency,
                initial_balance,
                is_archived,
                legacy_source,
                legacy_id,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :user_id,
                :name,
                :bank_name,
                :account_number,
                :description,
                :currency,
                :initial_balance,
                :is_archived,
                :legacy_source,
                :legacy_id,
                NOW(),
                NOW()
            )'
        );

        $statement->execute([
            'company_id' => (int) $payload['company_id'],
            'user_id' => $payload['user_id'],
            'name' => (string) $payload['name'],
            'bank_name' => $payload['bank_name'] !== '' ? (string) $payload['bank_name'] : null,
            'account_number' => $payload['account_number'] !== '' ? (string) $payload['account_number'] : null,
            'description' => $payload['description'] !== '' ? (string) $payload['description'] : null,
            'currency' => (string) $payload['currency'],
            'initial_balance' => (string) $payload['initial_balance'],
            'is_archived' => (int) ($payload['is_archived'] ?? 0),
            'legacy_source' => isset($payload['legacy_source']) && $payload['legacy_source'] !== '' ? (string) $payload['legacy_source'] : null,
            'legacy_id' => isset($payload['legacy_id']) && $payload['legacy_id'] !== '' ? (string) $payload['legacy_id'] : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInCompany(int $accountId, int $companyId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, name, bank_name, account_number, description, currency, initial_balance, is_archived
             FROM accounts
             WHERE id = :id AND company_id = :company_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $accountId,
            'company_id' => $companyId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function setArchived(int $accountId, int $companyId, bool $archived): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE accounts
             SET is_archived = :is_archived, updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $accountId,
            'company_id' => $companyId,
            'is_archived' => $archived ? 1 : 0,
        ]);
    }

    public function hasTransactions(int $accountId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transactions WHERE account_id = :account_id'
        );
        $statement->execute(['account_id' => $accountId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function delete(int $accountId, int $companyId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM accounts WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $accountId,
            'company_id' => $companyId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(array $payload): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE accounts
             SET
                name = :name,
                bank_name = :bank_name,
                account_number = :account_number,
                description = :description,
                currency = :currency,
                initial_balance = :initial_balance,
                updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => (int) $payload['id'],
            'company_id' => (int) $payload['company_id'],
            'name' => (string) $payload['name'],
            'bank_name' => $payload['bank_name'] !== '' ? (string) $payload['bank_name'] : null,
            'account_number' => $payload['account_number'] !== '' ? (string) $payload['account_number'] : null,
            'description' => $payload['description'] !== '' ? (string) $payload['description'] : null,
            'currency' => (string) $payload['currency'],
            'initial_balance' => (string) $payload['initial_balance'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByLegacy(int $companyId, string $source, string $legacyId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, name, bank_name, account_number, description, currency, initial_balance, is_archived, legacy_source, legacy_id
             FROM accounts
             WHERE company_id = :company_id AND legacy_source = :legacy_source AND legacy_id = :legacy_id
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'legacy_source' => $source,
            'legacy_id' => $legacyId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allInCompany(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name
             FROM accounts
             WHERE company_id = :company_id AND is_archived = 0
             ORDER BY name ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
