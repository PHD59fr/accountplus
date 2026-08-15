<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_key CHAR(64) NOT NULL PRIMARY KEY,
            failures INT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_login_attempts_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
