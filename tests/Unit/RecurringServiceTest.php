<?php

declare(strict_types=1);

use App\Service\RecurringService;

/**
 * @return array<string, callable>
 */
return [
    'monthly addition clamps to the last day of the month' => static function (): void {
        $service = recurringReflectionHelper();
        $cases = [
            ['2026-01-31', 1, '2026-02-28'],
            ['2026-01-31', 2, '2026-03-31'],
            ['2024-02-29', 1, '2024-03-29'],
            ['2026-08-10', 12, '2027-08-10'],
        ];
        foreach ($cases as [$from, $months, $expected]) {
            $actual = $service['addMonths']($from, $months);
            if ($actual !== $expected) {
                throw new RuntimeException("addMonths({$from}, {$months}) = {$actual}, expected {$expected}.");
            }
        }
    },

    'daily and weekly advancement' => static function (): void {
        $service = recurringReflectionHelper();
        $actual = $service['advance']('daily', 2, '2026-08-10');
        if ($actual !== '2026-08-12') {
            throw new RuntimeException("daily advance = {$actual}, expected 2026-08-12.");
        }
        $actual = $service['advance']('weekly', 3, '2026-08-10');
        if ($actual !== '2026-08-31') {
            throw new RuntimeException("weekly advance = {$actual}, expected 2026-08-31.");
        }
    },

    'yearly advancement keeps day and month' => static function (): void {
        $service = recurringReflectionHelper();
        $actual = $service['advance']('yearly', 1, '2026-01-31');
        if ($actual !== '2027-01-31') {
            throw new RuntimeException("yearly advance = {$actual}, expected 2027-01-31.");
        }
    },

    'recurring transfer rejects identical accounts' => static function (): void {
        $service = recurringReflectionHelper();
        try {
            $service['createTransfer'](7, 7, [], '2026-08-10', '10.00');
        } catch (InvalidArgumentException $exception) {
            return;
        }

        throw new RuntimeException('A recurring transfer between identical accounts was accepted.');
    },
];

/**
 * @return array<string, Closure>
 */
function recurringReflectionHelper(): array
{
    $class = new ReflectionClass(RecurringService::class);
    $instance = $class->newInstanceWithoutConstructor();

    $wrap = static function (string $method) use ($class, $instance): Closure {
        $method = $class->getMethod($method);
        $method->setAccessible(true);

        return static fn (...$args) => $method->invoke($instance, ...$args);
    };

    return [
        'addMonths' => $wrap('addMonths'),
        'advance' => $wrap('advance'),
        'createTransfer' => $wrap('createTransfer'),
    ];
}
