<?php

declare(strict_types=1);

return [
    'up' => [
        ['ensure_column', 'categories', 'emoji', 'ALTER TABLE categories ADD COLUMN emoji VARCHAR(32) NULL AFTER name'],
    ],
];
