<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class RecurringRuleRepository
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
            'INSERT INTO recurring_rules (
                company_id,
                account_id,
                destination_account_id,
                category_id,
                type,
                label,
                amount,
                recurrence,
                interval_value,
                next_execution_date,
                end_date,
                is_active,
                legacy_source,
                legacy_id,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :account_id,
                :destination_account_id,
                :category_id,
                :type,
                :label,
                :amount,
                :recurrence,
                :interval_value,
                :next_execution_date,
                :end_date,
                :is_active,
                NULL,
                NULL,
                NOW(),
                NOW()
            )'
        );

        $statement->execute([
            'company_id' => (int) $payload['company_id'],
            'account_id' => (int) $payload['account_id'],
            'destination_account_id' => isset($payload['destination_account_id']) && $payload['destination_account_id'] !== '' ? (int) $payload['destination_account_id'] : null,
            'category_id' => isset($payload['category_id']) && $payload['category_id'] !== '' ? (int) $payload['category_id'] : null,
            'type' => (string) $payload['type'],
            'label' => (string) $payload['label'],
            'amount' => (string) $payload['amount'],
            'recurrence' => (string) $payload['recurrence'],
            'interval_value' => (int) $payload['interval_value'],
            'next_execution_date' => (string) $payload['next_execution_date'],
            'end_date' => isset($payload['end_date']) && $payload['end_date'] !== '' ? (string) $payload['end_date'] : null,
            'is_active' => !empty($payload['is_active']) ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInCompany(int $ruleId, int $companyId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, account_id, destination_account_id, category_id, type, label, amount, recurrence, interval_value, next_execution_date, end_date, is_active
             FROM recurring_rules
             WHERE id = :id AND company_id = :company_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $ruleId,
            'company_id' => $companyId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(array $payload): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recurring_rules
             SET
                account_id = :account_id,
                destination_account_id = :destination_account_id,
                category_id = :category_id,
                type = :type,
                label = :label,
                amount = :amount,
                recurrence = :recurrence,
                interval_value = :interval_value,
                next_execution_date = :next_execution_date,
                end_date = :end_date,
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );

        $statement->execute([
            'id' => (int) $payload['id'],
            'company_id' => (int) $payload['company_id'],
            'account_id' => (int) $payload['account_id'],
            'destination_account_id' => isset($payload['destination_account_id']) && $payload['destination_account_id'] !== '' ? (int) $payload['destination_account_id'] : null,
            'category_id' => isset($payload['category_id']) && $payload['category_id'] !== '' ? (int) $payload['category_id'] : null,
            'type' => (string) $payload['type'],
            'label' => (string) $payload['label'],
            'amount' => (string) $payload['amount'],
            'recurrence' => (string) $payload['recurrence'],
            'interval_value' => (int) $payload['interval_value'],
            'next_execution_date' => (string) $payload['next_execution_date'],
            'end_date' => isset($payload['end_date']) && $payload['end_date'] !== '' ? (string) $payload['end_date'] : null,
            'is_active' => !empty($payload['is_active']) ? 1 : 0,
        ]);
    }

    public function setActive(int $ruleId, int $companyId, bool $active): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recurring_rules
             SET is_active = :is_active, updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $ruleId,
            'company_id' => $companyId,
            'is_active' => $active ? 1 : 0,
        ]);
    }

    public function delete(int $ruleId, int $companyId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM recurring_rules WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $ruleId,
            'company_id' => $companyId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allInCompany(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.account_id, r.destination_account_id, r.category_id, r.type, r.label, r.amount, r.recurrence, r.interval_value, r.next_execution_date, r.end_date, r.is_active,
                    COALESCE(a.name, "") AS account_name, COALESCE(da.name, "") AS destination_account_name,
                    COALESCE(CASE WHEN c.parent_id IS NOT NULL AND cp.name IS NOT NULL THEN CONCAT(cp.name, " › ", c.name) ELSE c.name END, "") AS category_name, COALESCE(a.currency, "EUR") AS currency
             FROM recurring_rules r
             LEFT JOIN accounts a ON a.id = r.account_id
             LEFT JOIN accounts da ON da.id = r.destination_account_id
             LEFT JOIN categories c ON c.id = r.category_id
             LEFT JOIN categories cp ON cp.id = c.parent_id
             WHERE r.company_id = :company_id
             ORDER BY r.next_execution_date ASC, r.id ASC'
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dueRules(int $companyId, string $referenceDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, account_id, destination_account_id, category_id, type, label, amount, recurrence, interval_value, next_execution_date, end_date, is_active
             FROM recurring_rules
             WHERE company_id = :company_id
               AND is_active = 1
               AND next_execution_date <= :reference_date
             ORDER BY next_execution_date ASC, id ASC
             LIMIT 200'
        );
        $statement->execute([
            'company_id' => $companyId,
            'reference_date' => $referenceDate,
        ]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Locks one still-due rule so concurrent workers cannot post the same occurrence.
     *
     * @return array<string, mixed>|null
     */
    public function findDueForUpdate(int $ruleId, int $companyId, string $referenceDate): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, account_id, destination_account_id, category_id, type, label, amount, recurrence, interval_value, next_execution_date, end_date, is_active
             FROM recurring_rules
             WHERE id = :id
               AND company_id = :company_id
               AND is_active = 1
               AND next_execution_date <= :reference_date
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'id' => $ruleId,
            'company_id' => $companyId,
            'reference_date' => $referenceDate,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function setNextExecutionDate(int $ruleId, int $companyId, string $date): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recurring_rules
             SET next_execution_date = :next_execution_date, updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $ruleId,
            'company_id' => $companyId,
            'next_execution_date' => $date,
        ]);
    }
}
