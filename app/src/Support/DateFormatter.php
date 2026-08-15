<?php

declare(strict_types=1);

namespace App\Support;

final class DateFormatter
{
    public const DEFAULT_FORMAT = 'Y-m-d';

    public static function format(?string $value, string $format = self::DEFAULT_FORMAT): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $value = trim($value);
        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        $result = date($format, $timestamp);

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value) === 1) {
            $result .= ' ' . date('H:i', $timestamp);
        }

        return $result;
    }
}
