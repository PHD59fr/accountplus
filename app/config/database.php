<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
    'database' => $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'compte',
    'username' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'compte',
    'password' => \App\Support\Secret::read('DB_PASSWORD'),
    'charset' => 'utf8mb4',
];
