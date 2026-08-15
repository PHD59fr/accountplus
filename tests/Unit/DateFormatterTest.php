<?php

declare(strict_types=1);

use App\Support\DateFormatter;

/**
 * @return array<string, callable>
 */
return [
    'formats a plain date with the configured format' => static function (): void {
        $result = DateFormatter::format('2026-08-10', 'd/m/Y');
        if ($result !== '10/08/2026') {
            throw new RuntimeException('Expected 10/08/2026, got ' . $result);
        }
    },

    'keeps the time part for datetime values' => static function (): void {
        $result = DateFormatter::format('2026-08-10 14:30:00', 'd/m/Y');
        if ($result !== '10/08/2026 14:30') {
            throw new RuntimeException('Expected 10/08/2026 14:30, got ' . $result);
        }
    },

    'returns an empty string for null and empty values' => static function (): void {
        if (DateFormatter::format(null, 'd/m/Y') !== '') {
            throw new RuntimeException('Expected empty string for null.');
        }
        if (DateFormatter::format('', 'd/m/Y') !== '') {
            throw new RuntimeException('Expected empty string for empty value.');
        }
    },

    'falls back to the raw value when it is not parseable' => static function (): void {
        $result = DateFormatter::format('not-a-date', 'd/m/Y');
        if ($result !== 'not-a-date') {
            throw new RuntimeException('Expected raw fallback, got ' . $result);
        }
    },

    'supports a custom format string' => static function (): void {
        $result = DateFormatter::format('2026-12-31', 'Y-m-d');
        if ($result !== '2026-12-31') {
            throw new RuntimeException('Expected 2026-12-31, got ' . $result);
        }
        $result = DateFormatter::format('2026-12-31', 'd.m.Y');
        if ($result !== '31.12.2026') {
            throw new RuntimeException('Expected 31.12.2026, got ' . $result);
        }
    },
];
