<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $migrationPath
    ) {
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        $applied = $this->applied();
        $pending = [];
        foreach ($this->files() as $file) {
            if (!in_array($file, $applied, true)) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    public function assertCurrent(): void
    {
        $applied = $this->applied();
        if ($applied !== $this->files()) {
            throw new \RuntimeException('Schema version mismatch. Run db:migrate during deployment.');
        }
    }

    public function migrate(): void
    {
        $lock = 'accountplus.migrate.' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 30)');
        $statement->execute(['name' => $lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Another migration is running.');
        }
        try {
            $this->ensureMigrationsTable();
            foreach ($this->pending() as $file) {
                $this->run($file);
            }
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $lock]);
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->migrationPath . '/*.php') ?: [];
        sort($files, SORT_STRING);

        return array_map('basename', $files);
    }

    /**
     * @return list<string>
     */
    private function applied(): array
    {
        $statement = $this->pdo->query('SELECT filename FROM migrations ORDER BY id ASC');

        $applied = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $file) {
            $applied[] = (string) $file;
        }

        return $applied;
    }

    private function run(string $file): void
    {
        $migration = require $this->migrationPath . '/' . $file;
        if (!is_array($migration) || !isset($migration['up']) || !is_array($migration['up']) || $migration['up'] === []) {
            throw new \RuntimeException('Invalid migration definition: ' . $file);
        }
        $statements = $migration['up'];

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($statements as $statement) {
                if (is_array($statement) && ($statement[0] ?? '') === 'ensure_column') {
                    $this->ensureColumnExists((string) $statement[1], (string) $statement[2], (string) $statement[3]);
                    continue;
                }

                $this->pdo->exec((string) $statement);
            }

            $this->pdo->prepare('INSERT INTO migrations (filename, applied_at) VALUES (:filename, NOW())')
                ->execute(['filename' => $file]);

            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureColumnExists(string $table, string $column, string $sql): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        if ((int) $statement->fetchColumn() === 0) {
            $this->pdo->exec($sql);
        }
    }
}
