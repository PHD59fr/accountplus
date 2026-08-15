<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User;
use PDO;

final class UserRepository
{
    private ?bool $hasLocaleColumn = null;

    private ?bool $hasLastLoginColumn = null;

    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findByUsername(string $username): ?User
    {
        $select = $this->userSelectColumns();
        $statement = $this->pdo->prepare(
            'SELECT ' . $select . ' FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new User(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['password_hash'],
            (string) $row['display_name'],
            (string) $row['role'],
            (bool) $row['is_active'],
            (string) ($row['locale'] ?? 'en'),
            isset($row['last_login_at']) && $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null
        );
    }

    public function findById(int $id): ?User
    {
        $select = $this->userSelectColumns();
        $statement = $this->pdo->prepare(
            'SELECT ' . $select . ' FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new User(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['password_hash'],
            (string) $row['display_name'],
            (string) $row['role'],
            (bool) $row['is_active'],
            (string) ($row['locale'] ?? 'en'),
            isset($row['last_login_at']) && $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null
        );
    }

    public function count(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM users');

        return (int) $statement->fetchColumn();
    }

    public function firstActiveAdmin(): ?User
    {
        $select = $this->userSelectColumns();
        $statement = $this->pdo->prepare(
            'SELECT ' . $select . '
             FROM users
             WHERE role = "ADMIN" AND is_active = 1
             ORDER BY id ASC
             LIMIT 1'
        );
        $statement->execute();
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new User(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['password_hash'],
            (string) $row['display_name'],
            (string) $row['role'],
            (bool) $row['is_active'],
            (string) ($row['locale'] ?? 'en'),
            isset($row['last_login_at']) && $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null
        );
    }

    public function countAdmins(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM users WHERE role = "ADMIN"');

        return (int) $statement->fetchColumn();
    }

    public function createAdmin(string $username, string $passwordHash, string $displayName): int
    {
        return $this->createUser($username, $passwordHash, $displayName, 'ADMIN');
    }

    public function createUser(string $username, string $passwordHash, string $displayName, string $role = 'USER'): int
    {
        if ($this->hasLocaleColumn()) {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (username, password_hash, display_name, role, is_active, locale, created_at, updated_at)
                 VALUES (:username, :password_hash, :display_name, :role, :is_active, :locale, NOW(), NOW())'
            );
            $statement->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'role' => $role,
                'is_active' => 1,
                'locale' => 'en',
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (username, password_hash, display_name, role, is_active, created_at, updated_at)
                 VALUES (:username, :password_hash, :display_name, :role, :is_active, NOW(), NOW())'
            );
            $statement->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'role' => $role,
                'is_active' => 1,
            ]);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        $select = $this->userSelectColumns();
        $statement = $this->pdo->query(
            'SELECT ' . $select . '
             FROM users
             ORDER BY username ASC'
        );

        $users = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $users[] = new User(
                (int) $row['id'],
                (string) $row['username'],
                (string) $row['password_hash'],
                (string) $row['display_name'],
                (string) $row['role'],
                (bool) $row['is_active'],
                (string) ($row['locale'] ?? 'en'),
                isset($row['last_login_at']) && $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null
            );
        }

        return $users;
    }

    public function updateRole(int $userId, string $role): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id'
        );
        $statement->execute([
            'role' => $role,
            'id' => $userId,
        ]);
    }

    public function updateLocale(int $userId, string $locale): void
    {
        if (!$this->hasLocaleColumn()) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE users SET locale = :locale, updated_at = NOW() WHERE id = :id'
        );
        $statement->execute([
            'locale' => $locale,
            'id' => $userId,
        ]);
    }

    public function updateLastLogin(int $userId): void
    {
        if (!$this->hasLastLoginColumn()) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE users SET last_login_at = NOW() WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    public function updateActive(int $userId, bool $isActive): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE id = :id'
        );
        $statement->execute([
            'is_active' => $isActive ? 1 : 0,
            'id' => $userId,
        ]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id'
        );
        $statement->execute([
            'password_hash' => $passwordHash,
            'id' => $userId,
        ]);
    }

    private function userSelectColumns(): string
    {
        $columns = 'id, username, password_hash, display_name, role, is_active';
        if ($this->hasLocaleColumn()) {
            $columns .= ', locale';
        }
        if ($this->hasLastLoginColumn()) {
            $columns .= ', last_login_at';
        }

        return $columns;
    }

    private function hasLocaleColumn(): bool
    {
        if ($this->hasLocaleColumn !== null) {
            return $this->hasLocaleColumn;
        }

        $statement = $this->pdo->query('SHOW COLUMNS FROM users LIKE "locale"');
        $this->hasLocaleColumn = $statement !== false && $statement->fetch() !== false;

        return $this->hasLocaleColumn;
    }

    private function hasLastLoginColumn(): bool
    {
        if ($this->hasLastLoginColumn !== null) {
            return $this->hasLastLoginColumn;
        }

        $statement = $this->pdo->query('SHOW COLUMNS FROM users LIKE "last_login_at"');
        $this->hasLastLoginColumn = $statement !== false && $statement->fetch() !== false;

        return $this->hasLastLoginColumn;
    }
}
