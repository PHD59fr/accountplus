<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class CategoryRepository
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
            'INSERT INTO categories (
                company_id,
                parent_id,
                name,
                emoji,
                category_type,
                is_active,
                sort_order,
                legacy_source,
                legacy_id,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :parent_id,
                :name,
                :emoji,
                :category_type,
                :is_active,
                :sort_order,
                :legacy_source,
                :legacy_id,
                NOW(),
                NOW()
            )'
        );

        $statement->execute([
            'company_id' => (int) $payload['company_id'],
            'parent_id' => isset($payload['parent_id']) && $payload['parent_id'] !== '' ? (int) $payload['parent_id'] : null,
            'name' => (string) $payload['name'],
            'emoji' => isset($payload['emoji']) && $payload['emoji'] !== '' ? (string) $payload['emoji'] : null,
            'category_type' => $payload['category_type'] !== '' ? (string) $payload['category_type'] : null,
            'is_active' => !empty($payload['is_active']) ? 1 : 0,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
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
            'SELECT id, company_id, parent_id, name, emoji, category_type, is_active, sort_order
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

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInCompany(int $categoryId, int $companyId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_id, parent_id, name, emoji, category_type, is_active, sort_order
             FROM categories
             WHERE id = :id AND company_id = :company_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $categoryId,
            'company_id' => $companyId,
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
            'SELECT id, company_id, parent_id, name, emoji, category_type, is_active, sort_order, legacy_source, legacy_id
             FROM categories
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

    public function updateParent(int $categoryId, int $companyId, ?int $parentId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE categories
             SET parent_id = :parent_id, updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $categoryId,
            'company_id' => $companyId,
            'parent_id' => $parentId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(array $payload): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE categories
             SET
                parent_id = :parent_id,
                name = :name,
                emoji = :emoji,
                category_type = :category_type,
                is_active = :is_active,
                sort_order = :sort_order,
                updated_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );

        $statement->execute([
            'id' => (int) $payload['id'],
            'company_id' => (int) $payload['company_id'],
            'parent_id' => isset($payload['parent_id']) && $payload['parent_id'] !== '' ? (int) $payload['parent_id'] : null,
            'name' => (string) $payload['name'],
            'emoji' => isset($payload['emoji']) && $payload['emoji'] !== '' ? (string) $payload['emoji'] : null,
            'category_type' => $payload['category_type'] !== '' ? (string) $payload['category_type'] : null,
            'is_active' => !empty($payload['is_active']) ? 1 : 0,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);
    }

    public function delete(int $categoryId, int $companyId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM categories WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            'id' => $categoryId,
            'company_id' => $companyId,
        ]);
    }
}
