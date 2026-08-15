<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class SettingsRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function get(string $key, string $default = ''): string
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1'
        );
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        if ($value === false || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at)
             VALUES (:key, :value, NOW())
             ON DUPLICATE KEY UPDATE setting_value = :value2, updated_at = NOW()'
        );
        $statement->execute([
            'key' => $key,
            'value' => $value,
            'value2' => $value,
        ]);
    }
}
