<?php

declare(strict_types=1);

namespace App\Support;

final class SecurityError
{
    public static function report(\Throwable $error, string $event): string
    {
        $id = bin2hex(random_bytes(8));
        // Exception messages, traces and arguments can contain SQL, credentials or records.
        error_log(json_encode([
            'event' => $event, 'reference' => $id, 'type' => $error::class,
            'code' => (string) $error->getCode(), 'source' => basename($error->getFile()),
            'line' => $error->getLine(),
        ], JSON_THROW_ON_ERROR));
        return $id;
    }
}
