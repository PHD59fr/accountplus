<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use App\Support\Migrator;
use PDO;
use Throwable;

final class InstallerService
{
    public function __construct(
        private PDO $pdo,
        private string $migrationsPath
    ) {
    }

    public function isInstalled(): bool
    {
        $requiredTables = [
            'users',
            'companies',
            'user_companies',
            'accounts',
            'categories',
            'transactions',
            'transfers',
            'recurring_rules',
            'imports',
            'import_errors',
            'audit_logs',
        ];

        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        $statement = $this->pdo->query('SELECT COUNT(*) FROM users WHERE role = "ADMIN" AND is_active = 1');

        return (int) $statement->fetchColumn() > 0;
    }

    public function migrate(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsPath);
        $migrator->migrate();
    }

    public function hasUsers(): bool
    {
        return $this->tableExists('users') && (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function install(array $payload): void
    {
        $lock = 'accountplus.setup.' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 30)');
        $statement->execute(['name' => $lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Installation is busy.');
        }
        try {
            if ($this->hasUsers()) {
                throw new \RuntimeException('This database already contains users. Restore the existing configuration file.');
            }
            $this->initialize($payload);
            if (!$this->isInstalled()) {
                throw new \RuntimeException('Initial administrator is unavailable.');
            }
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $lock]);
        }
    }

    private function initialize(array $payload): void
    {
        // MySQL commits DDL implicitly, so migrations must finish before the seed transaction starts.
        if (!$this->tableExists('migrations') || (new Migrator($this->pdo, $this->migrationsPath))->pending() !== []) {
            $this->migrate();
        }
        $transactionStarted = $this->pdo->beginTransaction();

        try {
            $userRepository = new UserRepository($this->pdo);
            $companyRepository = new CompanyRepository($this->pdo);

            // Never turn a missing configuration file into a password-reset flow.
            $existing = $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1 FOR UPDATE')->fetchColumn();
            if ($existing !== false) {
                throw new \RuntimeException('This database already contains users. Restore the existing configuration file.');
            }

            $userId = $userRepository->createAdmin(
                (string) $payload['admin_username'],
                (string) $payload['admin_password_hash'],
                (string) ($payload['admin_display_name'] ?? $payload['admin_username'])
            );

            if ($companyRepository->count() === 0) {
                $companyId = $companyRepository->create(
                    (string) ($payload['company_name'] ?? 'Sample company'),
                    'SAMPLE-COMPANY',
                    'Active'
                );
            } else {
                $companyId = 0;
            }

            if ($userId > 0 && $companyId > 0) {
                $statement = $this->pdo->prepare(
                    'INSERT IGNORE INTO user_companies (user_id, company_id, created_at) VALUES (:user_id, :company_id, NOW())'
                );
                $statement->execute([
                    'user_id' => $userId,
                    'company_id' => $companyId,
                ]);
            }

            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $throwable) {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }
}
