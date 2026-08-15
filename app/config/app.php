<?php

declare(strict_types=1);

return [
    'name' => 'Account+',
    'env' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'url' => $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost',
    'locale' => 'en',
    'session_name' => 'php_compte',
];
