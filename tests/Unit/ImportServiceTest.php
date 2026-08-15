<?php

declare(strict_types=1);

use App\Service\ImportService;

/**
 * @return array<string, callable>
 */
return [
    'decimalField normalizes comma and space separators' => static function (): void {
        $service = importReflectionHelper();
        $actual = $service['decimalField'](['amount' => '1 234,56'], 'amount', '0.00');
        if ($actual !== '1234.56') {
            throw new RuntimeException("decimalField returned {$actual}, expected 1234.56.");
        }
        $actual = $service['decimalField'](['amount' => 'abc'], 'amount', '0.00');
        if ($actual !== '0.00') {
            throw new RuntimeException("decimalField returned {$actual} for invalid input, expected 0.00.");
        }
    },

    'stringField handles numeric and missing values' => static function (): void {
        $service = importReflectionHelper();
        $actual = $service['stringField'](['legacy_id' => 42], 'legacy_id');
        if ($actual !== '42') {
            throw new RuntimeException("stringField int returned {$actual}, expected '42'.");
        }
        $actual = $service['stringField'](['a' => 1], 'missing');
        if ($actual !== null) {
            throw new RuntimeException('stringField should return null for a missing key.');
        }
    },

    'parseNdjson reads one record per line' => static function (): void {
        $service = importReflectionHelper();
        $rows = $service['parseNdjson']("{\"a\":1}\n{\"a\":2}\n", 'transactions', []);
        if (count($rows) !== 2) {
            throw new RuntimeException('Expected 2 records, got ' . count($rows) . '.');
        }
        if ((int) $rows[0]['a'] !== 1 || (int) $rows[1]['a'] !== 2) {
            throw new RuntimeException('NDJSON record values were not preserved.');
        }
    },

    'parseNdjson rejects malformed lines' => static function (): void {
        $service = importReflectionHelper();
        try {
            $service['parseNdjson']("{\"a\":1}\nnot-json\n", 'transactions', []);
            throw new RuntimeException('Expected parseNdjson to fail on a malformed line.');
        } catch (InvalidArgumentException $e) {
            // expected
        }
    },

    'ambiguous legacy identifiers are rejected before import' => static function (): void {
        $service = importReflectionHelper();
        try {
            $service['parseNdjson']("{\"legacy_id\":\"7\",\"name\":\"Manual\"}\n{\"legacy_id\":7,\"name\":\"Imported\"}", 'accounts', []);
        } catch (InvalidArgumentException $exception) {
            if (str_contains($exception->getMessage(), 'duplicate accounts legacy_id')) {
                return;
            }
            throw $exception;
        }
        throw new RuntimeException('Duplicate identifiers could silently merge accounts.');
    },

    'legacy transaction identifiers remain scoped by source table' => static function (): void {
        $service = importReflectionHelper();
        $rows = $service['parseNdjson']("{\"legacy_id\":1,\"legacy_table\":\"ECRITURE\"}\n{\"legacy_id\":1,\"legacy_table\":\"ARCHIVE\"}", 'transactions', []);
        if (count($rows) !== 2) {
            throw new RuntimeException('Distinct source tables were merged.');
        }
    },

    'isIsoDate validates real calendar dates' => static function (): void {
        $service = importReflectionHelper();
        if ($service['isIsoDate']('2026-02-29') !== false) {
            throw new RuntimeException('2026-02-29 is not a valid date.');
        }
        if ($service['isIsoDate']('2024-02-29') !== true) {
            throw new RuntimeException('2024-02-29 is a valid leap day.');
        }
    },
];

/**
 * @return array<string, Closure>
 */
function importReflectionHelper(): array
{
    $class = new ReflectionClass(ImportService::class);
    $instance = $class->newInstanceWithoutConstructor();

    $wrap = static function (string $method) use ($class, $instance): Closure {
        $method = $class->getMethod($method);
        $method->setAccessible(true);

        return static fn (...$args) => $method->invoke($instance, ...$args);
    };

    return [
        'decimalField' => $wrap('decimalField'),
        'stringField' => $wrap('stringField'),
        'parseNdjson' => $wrap('parseNdjson'),
        'isIsoDate' => $wrap('isIsoDate'),
    ];
}
