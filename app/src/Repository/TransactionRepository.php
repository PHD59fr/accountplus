<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

final class TransactionRepository
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
            'INSERT INTO transactions (
                company_id,
                account_id,
                category_id,
                transaction_date,
                value_date,
                label,
                description,
                amount,
                is_reconciled,
                is_archived,
                type,
                transfer_id,
                recurring_rule_id,
                legacy_source,
                legacy_table,
                legacy_id,
                legacy_payload,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :account_id,
                :category_id,
                :transaction_date,
                :value_date,
                :label,
                :description,
                :amount,
                :is_reconciled,
                :is_archived,
                :type,
                :transfer_id,
                :recurring_rule_id,
                :legacy_source,
                :legacy_table,
                :legacy_id,
                :legacy_payload,
                NOW(),
                NOW()
            )'
        );

        $legacyPayload = $payload['legacy_payload'] ?? null;
        $legacyPayload = is_array($legacyPayload) ? json_encode($legacyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $legacyPayload;

        $statement->execute([
            'company_id' => (int) $payload['company_id'],
            'account_id' => (int) $payload['account_id'],
            'category_id' => isset($payload['category_id']) && $payload['category_id'] !== '' ? (int) $payload['category_id'] : null,
            'transaction_date' => (string) $payload['transaction_date'],
            'value_date' => $payload['value_date'] !== '' ? (string) $payload['value_date'] : null,
            'label' => (string) $payload['label'],
            'description' => $payload['description'] !== '' ? (string) $payload['description'] : null,
            'amount' => (string) $payload['amount'],
            'is_reconciled' => (int) ($payload['is_reconciled'] ?? 0),
            'is_archived' => (int) ($payload['is_archived'] ?? 0),
            'type' => isset($payload['type']) && $payload['type'] !== '' ? (string) $payload['type'] : null,
            'transfer_id' => isset($payload['transfer_id']) && $payload['transfer_id'] !== '' ? (int) $payload['transfer_id'] : null,
            'recurring_rule_id' => isset($payload['recurring_rule_id']) && $payload['recurring_rule_id'] !== '' ? (int) $payload['recurring_rule_id'] : null,
            'legacy_source' => isset($payload['legacy_source']) && $payload['legacy_source'] !== '' ? (string) $payload['legacy_source'] : null,
            'legacy_table' => isset($payload['legacy_table']) && $payload['legacy_table'] !== '' ? (string) $payload['legacy_table'] : null,
            'legacy_id' => isset($payload['legacy_id']) && $payload['legacy_id'] !== '' ? (string) $payload['legacy_id'] : null,
            'legacy_payload' => $legacyPayload,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInCompany(int $transactionId, int $companyId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                company_id,
                account_id,
                category_id,
                transaction_date,
                value_date,
                label,
                description,
                amount,
                is_reconciled,
                is_archived,
                transfer_id,
                recurring_rule_id
             FROM transactions
             WHERE id = :id AND company_id = :company_id
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            'id' => $transactionId,
            'company_id' => $companyId,
        ]);

        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function lockTransfer(int $transferId, int $companyId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM transfers WHERE id = :id AND company_id = :company_id FOR UPDATE');
        $statement->execute(['id' => $transferId, 'company_id' => $companyId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function transferEntriesForUpdate(int $transferId, int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM transactions WHERE transfer_id = :transfer_id AND company_id = :company_id ORDER BY id FOR UPDATE'
        );
        $statement->execute(['transfer_id' => $transferId, 'company_id' => $companyId]);

        return $statement->fetchAll();
    }

    public function updateTransfer(array $payload): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE transfers SET source_account_id = :source_account_id, destination_account_id = :destination_account_id,
                amount = :amount, transaction_date = :transaction_date, label = :label, updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute($payload);
    }

    public function deleteTransfer(int $transferId, int $companyId): void
    {
        $this->pdo->prepare('DELETE FROM transfers WHERE id = :id AND company_id = :company_id')
            ->execute(['id' => $transferId, 'company_id' => $companyId]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(array $payload): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE transactions
             SET
                account_id = :account_id,
                category_id = :category_id,
                transaction_date = :transaction_date,
                value_date = :value_date,
                label = :label,
                description = :description,
                amount = :amount,
                is_reconciled = :is_reconciled,
                updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );

        $statement->execute([
            'id' => (int) $payload['id'],
            'company_id' => (int) $payload['company_id'],
            'account_id' => (int) $payload['account_id'],
            'category_id' => isset($payload['category_id']) && $payload['category_id'] !== '' ? (int) $payload['category_id'] : null,
            'transaction_date' => (string) $payload['transaction_date'],
            'value_date' => $payload['value_date'] !== '' ? (string) $payload['value_date'] : null,
            'label' => (string) $payload['label'],
            'description' => $payload['description'] !== '' ? (string) $payload['description'] : null,
            'amount' => (string) $payload['amount'],
            'is_reconciled' => (int) ($payload['is_reconciled'] ?? 0),
        ]);
    }

    public function setArchived(int $transactionId, int $companyId, bool $archived): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE transactions
             SET is_archived = :is_archived, updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $transactionId,
            'company_id' => $companyId,
            'is_archived' => $archived ? 1 : 0,
        ]);
    }

    public function countByCategory(int $categoryId, int $companyId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transactions WHERE category_id = :category_id AND company_id = :company_id'
        );
        $statement->execute([
            'category_id' => $categoryId,
            'company_id' => $companyId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function delete(int $transactionId, int $companyId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM transactions WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $transactionId,
            'company_id' => $companyId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByLegacy(int $companyId, string $source, string $table, string $legacyId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM transactions
             WHERE company_id = :company_id AND legacy_source = :legacy_source AND legacy_table = :legacy_table AND legacy_id = :legacy_id
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'legacy_source' => $source,
            'legacy_table' => $table,
            'legacy_id' => $legacyId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}
