<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class TransactionTypeRepository
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
            'INSERT INTO transaction_types (
                company_id,
                code,
                label,
                sort_order,
                is_active,
                legacy_source,
                legacy_id,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :code,
                :label,
                :sort_order,
                :is_active,
                :legacy_source,
                :legacy_id,
                NOW(),
                NOW()
            )'
        );

        $statement->execute([
            'company_id' => (int) $payload['company_id'],
            'code' => strtoupper(substr(trim((string) ($payload['code'] ?? '')), 0, 20)),
            'label' => trim((string) ($payload['label'] ?? '')) ?: strtoupper(trim((string) ($payload['code'] ?? ''))),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => !empty($payload['is_active']) ? 1 : 0,
            'legacy_source' => isset($payload['legacy_source']) && $payload['legacy_source'] !== '' ? (string) $payload['legacy_source'] : null,
            'legacy_id' => isset($payload['legacy_id']) && $payload['legacy_id'] !== '' ? (string) $payload['legacy_id'] : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allInCompany(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, code, label, sort_order, is_active, legacy_source, legacy_id
             FROM transaction_types
             WHERE company_id = :company_id
             ORDER BY sort_order ASC, code ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInCompany(int $id, int $companyId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, code, label, sort_order, is_active, legacy_source, legacy_id
             FROM transaction_types
             WHERE id = :id AND company_id = :company_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $id,
            'company_id' => $companyId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(int $companyId, string $code): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, code, label, sort_order, is_active, legacy_source, legacy_id
             FROM transaction_types
             WHERE company_id = :company_id AND code = :code
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'code' => strtoupper(substr(trim($code), 0, 20)),
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByLegacy(int $companyId, string $source, string $legacyId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, code, label, sort_order, is_active, legacy_source, legacy_id
             FROM transaction_types
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
}
