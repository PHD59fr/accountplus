<?php

declare(strict_types=1);

return [
    'production bootstrap pages never include arbitrary exception messages' => static function (): void {
        $kernel = new App\Application\AppKernel(new App\Support\Config(['app' => ['debug' => true, 'env' => 'production']]), new App\Support\View(dirname(__DIR__, 2) . '/app/templates'));
        $render = new ReflectionMethod($kernel, 'renderBootstrapError');
        $body = $render->invoke($kernel, 'SECRET_PASSWORD_TEST PRIVATE_DB_HOST_TEST INTERNAL_SQL_QUERY_TEST');
        if (str_contains($body, '_TEST') || !str_contains($body, '503')) {
            throw new RuntimeException('Unsafe bootstrap page');
        }
    },
    'setup messages exist in all eight locales' => static function (): void {
        foreach (['en', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'pl'] as $locale) {
            $messages = require dirname(__DIR__, 2) . '/app/lang/' . $locale . '.php';
            foreach (['setup.denied', 'setup.token', 'security.unavailable', 'security.recovery', 'security.expired', 'security.internal', 'security.reference'] as $key) {
                if (!is_string($messages[$key] ?? null) || $messages[$key] === '') {
                    throw new RuntimeException('Missing localized setup message');
                }
            }
        }
    },
];
