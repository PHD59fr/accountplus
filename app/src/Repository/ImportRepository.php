<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ImportRepository
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
            'INSERT INTO imports (
                company_id,
                user_id,
                source_filename,
                source_sha256,
                converter_version,
                import_format_version,
                status,
                accounts_count,
                transactions_count,
                categories_count,
                warnings_count,
                errors_count,
                started_at,
                completed_at,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :user_id,
                :source_filename,
                :source_sha256,
                :converter_version,
                :import_format_version,
                :status,
                0,
                0,
                0,
                0,
                0,
                NOW(),
                NULL,
                NOW(),
                NOW()
            )'
        );

        $statement->execute([
            'company_id' => isset($payload['company_id']) && $payload['company_id'] !== '' ? (int) $payload['company_id'] : null,
            'user_id' => isset($payload['user_id']) && $payload['user_id'] !== '' ? (int) $payload['user_id'] : null,
            'source_filename' => (string) $payload['source_filename'],
            'source_sha256' => (string) $payload['source_sha256'],
            'converter_version' => isset($payload['converter_version']) && $payload['converter_version'] !== '' ? (string) $payload['converter_version'] : null,
            'import_format_version' => isset($payload['import_format_version']) && $payload['import_format_version'] !== '' ? (int) $payload['import_format_version'] : null,
            'status' => (string) $payload['status'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function restart(int $importId, array $payload): void
    {
        $this->pdo->prepare('DELETE FROM import_errors WHERE import_id = :import_id')
            ->execute(['import_id' => $importId]);

        $statement = $this->pdo->prepare(
            'UPDATE imports
             SET company_id = :company_id,
                 user_id = :user_id,
                 source_filename = :source_filename,
                 converter_version = NULL,
                 import_format_version = :import_format_version,
                 status = :status,
                 accounts_count = 0,
                 transactions_count = 0,
                 categories_count = 0,
                 warnings_count = 0,
                 errors_count = 0,
                 started_at = NOW(),
                 completed_at = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $importId,
            'company_id' => isset($payload['company_id']) && $payload['company_id'] !== '' ? (int) $payload['company_id'] : null,
            'user_id' => isset($payload['user_id']) && $payload['user_id'] !== '' ? (int) $payload['user_id'] : null,
            'source_filename' => (string) $payload['source_filename'],
            'import_format_version' => (int) ($payload['import_format_version'] ?? 1),
            'status' => (string) ($payload['status'] ?? 'processing'),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateMetadata(int $importId, array $payload): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE imports
             SET source_filename = :source_filename,
                 converter_version = :converter_version,
                 import_format_version = :import_format_version,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $importId,
            'source_filename' => (string) $payload['source_filename'],
            'converter_version' => isset($payload['converter_version']) && $payload['converter_version'] !== '' ? (string) $payload['converter_version'] : null,
            'import_format_version' => (int) ($payload['import_format_version'] ?? 1),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySha256(string $sha256, int $companyId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, source_filename, source_sha256, status, completed_at
             FROM imports
             WHERE source_sha256 = :source_sha256 AND company_id = :company_id
             LIMIT 1'
        );
        $statement->execute(['source_sha256' => $sha256, 'company_id' => $companyId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, source_filename, converter_version, import_format_version, status, accounts_count, transactions_count, categories_count, warnings_count, errors_count, started_at, completed_at, created_at
             FROM imports
             ORDER BY id DESC
             LIMIT ' . (int) $limit
        );
        $statement->execute();

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
     * @param array<string, mixed> $payload
     */
    public function updateCounters(int $importId, array $payload): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE imports
             SET
                accounts_count = :accounts_count,
                transactions_count = :transactions_count,
                categories_count = :categories_count,
                warnings_count = :warnings_count,
                errors_count = :errors_count,
                updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $importId,
            'accounts_count' => (int) ($payload['accounts_count'] ?? 0),
            'transactions_count' => (int) ($payload['transactions_count'] ?? 0),
            'categories_count' => (int) ($payload['categories_count'] ?? 0),
            'warnings_count' => (int) ($payload['warnings_count'] ?? 0),
            'errors_count' => (int) ($payload['errors_count'] ?? 0),
        ]);
    }

    public function complete(int $importId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE imports
             SET status = :status, completed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $importId,
            'status' => $status,
        ]);
    }

    public function fail(int $importId, string $status = 'failed'): void
    {
        $this->complete($importId, $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addError(int $importId, array $payload): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO import_errors (
                import_id,
                entity_type,
                legacy_id,
                line_number,
                severity,
                message,
                payload,
                created_at
            ) VALUES (
                :import_id,
                :entity_type,
                :legacy_id,
                :line_number,
                :severity,
                :message,
                :payload,
                NOW()
            )'
        );

        $rawPayload = $payload['payload'] ?? null;
        $rawPayload = is_array($rawPayload) ? json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $rawPayload;

        $statement->execute([
            'import_id' => $importId,
            'entity_type' => (string) $payload['entity_type'],
            'legacy_id' => isset($payload['legacy_id']) && $payload['legacy_id'] !== '' ? (string) $payload['legacy_id'] : null,
            'line_number' => isset($payload['line_number']) && $payload['line_number'] !== '' ? (int) $payload['line_number'] : null,
            'severity' => (string) ($payload['severity'] ?? 'warning'),
            'message' => (string) $payload['message'],
            'payload' => $rawPayload,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function errors(int $importId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, import_id, entity_type, legacy_id, line_number, severity, message, payload, created_at
             FROM import_errors
             WHERE import_id = :import_id
             ORDER BY id ASC'
        );
        $statement->execute(['import_id' => $importId]);

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
