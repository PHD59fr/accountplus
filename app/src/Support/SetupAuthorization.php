<?php

declare(strict_types=1);

namespace App\Support;

final class SetupAuthorization
{
    public static function enabled(): bool
    {
        return getenv('APP_SETUP_ENABLED') === 'true' && strlen(self::token()) >= 32;
    }

    public static function accepts(mixed $supplied): bool
    {
        return self::enabled() && is_string($supplied) && hash_equals(self::token(), $supplied);
    }

    private static function token(): string
    {
        $value = getenv('APP_SETUP_TOKEN') ?: '';
        $file = getenv('APP_SETUP_TOKEN_FILE') ?: '';
        if ($file !== '') {
            if ($value !== '' || !is_readable($file)) {
                return '';
            }
            return rtrim((string) file_get_contents($file), "\r\n");
        }
        return $value;
    }
}
