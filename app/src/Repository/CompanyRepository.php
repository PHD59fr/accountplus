<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Company;
use PDO;
use Throwable;

final class CompanyRepository
{
    private array $columnCache = [];

    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @return list<Company>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, legacy_filename, status, account_count, transaction_count
             FROM companies
             ORDER BY name ASC'
        );

        $companies = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $companies[] = new Company(
                (int) $row['id'],
                (string) $row['name'],
                (string) $row['legacy_filename'],
                (string) $row['status'],
                (int) $row['account_count'],
                (int) $row['transaction_count']
            );
        }

        return $companies;
    }

    /**
     * @param list<int> $companyIds
     * @return list<Company>
     */
    public function byIds(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($companyIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT id, name, legacy_filename, status, account_count, transaction_count
             FROM companies
             WHERE id IN (' . $placeholders . ')
             ORDER BY name ASC'
        );
        $statement->execute(array_map('intval', $companyIds));

        $companies = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $companies[] = new Company(
                (int) $row['id'],
                (string) $row['name'],
                (string) $row['legacy_filename'],
                (string) $row['status'],
                (int) $row['account_count'],
                (int) $row['transaction_count']
            );
        }

        return $companies;
    }

    public function create(string $name, string $legacyFilename, string $status = 'Draft'): int
    {
        $fields = ['name'];
        $placeholders = [':name'];
        $params = ['name' => $name];

        if ($this->hasColumn('slug')) {
            $fields[] = 'slug';
            $placeholders[] = ':slug';
            $params['slug'] = $this->slugify($name);
        }

        if ($this->hasColumn('legacy_filename')) {
            $fields[] = 'legacy_filename';
            $placeholders[] = ':legacy_filename';
            $params['legacy_filename'] = $legacyFilename;
        }

        if ($this->hasColumn('status')) {
            $fields[] = 'status';
            $placeholders[] = ':status';
            $params['status'] = $status;
        }

        if ($this->hasColumn('account_count')) {
            $fields[] = 'account_count';
            $placeholders[] = '0';
        }

        if ($this->hasColumn('transaction_count')) {
            $fields[] = 'transaction_count';
            $placeholders[] = '0';
        }

        $fields[] = 'created_at';
        $fields[] = 'updated_at';
        $placeholders[] = 'NOW()';
        $placeholders[] = 'NOW()';

        $statement = $this->pdo->prepare(
            'INSERT INTO companies (' . implode(', ', $fields) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);

        return (int) $this->pdo->lastInsertId();
    }

    public function count(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM companies');

        return (int) $statement->fetchColumn();
    }

    public function first(): ?Company
    {
        $statement = $this->pdo->query(
            'SELECT id, name, legacy_filename, status, account_count, transaction_count
             FROM companies
             ORDER BY id ASC
             LIMIT 1'
        );
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new Company(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['legacy_filename'],
            (string) $row['status'],
            (int) $row['account_count'],
            (int) $row['transaction_count']
        );
    }

    public function find(int $id): ?Company
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, legacy_filename, status, account_count, transaction_count
             FROM companies
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new Company(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['legacy_filename'],
            (string) $row['status'],
            (int) $row['account_count'],
            (int) $row['transaction_count']
        );
    }

    public function update(int $id, string $name, string $status): void
    {
        $set = ['name = :name'];
        $params = [
            'name' => $name,
            'id' => $id,
        ];

        if ($this->hasColumn('slug')) {
            $set[] = 'slug = :slug';
            $params['slug'] = $this->slugify($name);
        }

        if ($this->hasColumn('status')) {
            $set[] = 'status = :status';
            $params['status'] = $status;
        }

        $set[] = 'updated_at = NOW()';

        $statement = $this->pdo->prepare(
            'UPDATE companies
             SET ' . implode(', ', $set) . '
             WHERE id = :id'
        );
        $statement->execute($params);
    }

    public function updateCounters(int $companyId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE companies
             SET
                account_count = (SELECT COUNT(*) FROM accounts WHERE company_id = :company_id),
                transaction_count = (SELECT COUNT(*) FROM transactions WHERE company_id = :company_id),
                updated_at = NOW()
             WHERE id = :company_id'
        );
        $statement->execute(['company_id' => $companyId]);
    }

    /**
     * Deletes a company and all its data. Child rows are removed explicitly,
     * in dependency order, because transfers restrict the deletion of the
     * accounts they reference.
     */
    public function delete(int $companyId): void
    {
        $started = $this->pdo->beginTransaction();

        try {
            foreach (['transfers', 'recurring_rules', 'transactions', 'accounts', 'categories', 'user_companies'] as $table) {
                $statement = $this->pdo->prepare(
                    'DELETE FROM ' . $table . ' WHERE company_id = :company_id'
                );
                $statement->execute(['company_id' => $companyId]);
            }

            $statement = $this->pdo->prepare('DELETE FROM companies WHERE id = :id');
            $statement->execute(['id' => $companyId]);

            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $throwable) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = strtr($slug, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ñ' => 'n',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
        ]);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string) $slug, '-');

        if ($slug === '') {
            $slug = 'company';
        }

        $base = $slug;
        $suffix = 2;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function slugExists(string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM companies WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function hasColumn(string $column): bool
    {
        if (array_key_exists($column, $this->columnCache)) {
            return $this->columnCache[$column];
        }

        $statement = $this->pdo->query('SHOW COLUMNS FROM companies LIKE "' . str_replace('"', '\"', $column) . '"');
        $this->columnCache[$column] = $statement !== false && $statement->fetch() !== false;

        return $this->columnCache[$column];
    }
}
