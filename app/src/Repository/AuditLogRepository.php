<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class AuditLogRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (
                company_id,
                user_id,
                username,
                action,
                entity_type,
                entity_id,
                old_values,
                new_values,
                ip_address,
                created_at
            ) VALUES (
                :company_id,
                :user_id,
                :username,
                :action,
                :entity_type,
                :entity_id,
                :old_values,
                :new_values,
                :ip_address,
                NOW()
            )'
        );

        $statement->execute([
            'company_id' => $payload['company_id'],
            'user_id' => $payload['user_id'],
            'username' => $payload['username'],
            'action' => (string) $payload['action'],
            'entity_type' => (string) $payload['entity_type'],
            'entity_id' => $payload['entity_id'] !== null ? (string) $payload['entity_id'] : null,
            'old_values' => $payload['old_values'] !== null ? json_encode($payload['old_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'new_values' => $payload['new_values'] !== null ? json_encode($payload['new_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip_address' => $payload['ip_address'],
        ]);
    }

    /**
     * Returns the audit trail of a single entity inside a company (newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forEntity(string $entityType, string $entityId, int $companyId, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, user_id, username, action, entity_type, entity_id, old_values, new_values, ip_address, created_at
             FROM audit_logs
             WHERE company_id = :company_id AND entity_type = :entity_type AND entity_id = :entity_id
             ORDER BY id DESC
             LIMIT ' . (int) $limit
        );
        $statement->execute([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
        ]);

        return $this->mapRows($statement->fetchAll());
    }

    /**
     * Returns the recent audit trail of a company (newest first). Admin only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $companyId, int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, user_id, username, action, entity_type, entity_id, old_values, new_values, ip_address, created_at
             FROM audit_logs
             WHERE company_id = :company_id
             ORDER BY id DESC
             LIMIT ' . (int) $limit
        );
        $statement->execute(['company_id' => $companyId]);

        return $this->mapRows($statement->fetchAll());
    }

    /**
     * Searches the audit trail of a company with an optional text query and a date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(
        int $companyId,
        ?string $query = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 200
    ): array {
        $conditions = ['company_id = :company_id'];
        $params = ['company_id' => $companyId];

        $query = trim((string) $query);
        if ($query !== '') {
            $conditions[] = '(username LIKE :q
                OR action LIKE :q
                OR entity_type LIKE :q
                OR entity_id LIKE :q
                OR ip_address LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateFrom) === 1) {
            $conditions[] = 'created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateTo) === 1) {
            $conditions[] = 'created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $statement = $this->pdo->prepare(
            'SELECT id, company_id, user_id, username, action, entity_type, entity_id, old_values, new_values, ip_address, created_at
             FROM audit_logs
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY id DESC
             LIMIT ' . (int) $limit
        );
        $statement->execute($params);

        return $this->mapRows($statement->fetchAll());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mapped[] = [
                'id' => (int) $row['id'],
                'company_id' => $row['company_id'] !== null ? (int) $row['company_id'] : null,
                'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'username' => $row['username'] !== null ? (string) $row['username'] : null,
                'action' => (string) $row['action'],
                'entity_type' => (string) $row['entity_type'],
                'entity_id' => $row['entity_id'] !== null ? (string) $row['entity_id'] : null,
                'old_values' => $this->decodeJson($row['old_values']),
                'new_values' => $this->decodeJson($row['new_values']),
                'ip_address' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
