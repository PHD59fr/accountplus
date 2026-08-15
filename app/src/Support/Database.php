<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Database
{
    /**
     * @param array<string, mixed> $databaseConfig
     */
    public static function connect(array $databaseConfig): PDO
    {
        foreach (['host', 'database', 'charset'] as $key) {
            $value = $databaseConfig[$key] ?? ($key === 'charset' ? 'utf8mb4' : '');
            if (!is_string($value) || $value === '' || preg_match('/[;\x00-\x20]/', $value)) {
                throw new \InvalidArgumentException('Invalid database connection configuration.');
            }
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) $databaseConfig['host'],
            (int) $databaseConfig['port'],
            (string) $databaseConfig['database'],
            (string) ($databaseConfig['charset'] ?? 'utf8mb4')
        );

        return new PDO($dsn, (string) $databaseConfig['username'], (string) $databaseConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
