<?php

declare(strict_types=1);

return [
    'up' => [
        ['ensure_column', 'transactions', 'type', 'ALTER TABLE transactions ADD COLUMN type VARCHAR(20) NULL AFTER description'],
    ],
];
