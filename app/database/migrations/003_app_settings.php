<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'INSERT INTO app_settings (setting_key, setting_value, updated_at)
         VALUES ("date_format", "Y-m-d", NOW())
         ON DUPLICATE KEY UPDATE setting_value = setting_value',
    ],
];
