<?php

declare(strict_types=1);

return [
    'up' => [
        ['ensure_column', 'audit_logs', 'username', 'ALTER TABLE audit_logs ADD COLUMN username VARCHAR(255) NULL AFTER user_id'],
    ],
];
