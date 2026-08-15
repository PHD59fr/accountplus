<?php

declare(strict_types=1);

namespace App\Support;

final class Secret
{
    public static function read(string $name, bool $required = false): string
    {
        $value = getenv($name);
        $file = getenv($name . '_FILE');
        if (is_string($file) && $file !== '') {
            if (($value !== false && $value !== '') || !is_readable($file)) {
                throw new \RuntimeException('Set exactly one readable ' . $name . '_FILE or ' . $name . '.');
            }
            $value = rtrim((string) file_get_contents($file), "\r\n");
        }
        if ($required && ($value === false || $value === '')) {
            throw new \RuntimeException($name . ' is required.');
        }
        return $value === false ? '' : $value;
    }
}
