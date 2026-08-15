<?php

declare(strict_types=1);

use App\Controller\ReportController;
use App\Http\Response;

/**
 * @return array<string, callable>
 */
return [
    'csv export neutralizes spreadsheet formulas' => static function (): void {
        $class = new ReflectionClass(ReportController::class);
        $controller = $class->newInstanceWithoutConstructor();
        $method = $class->getMethod('csvText');
        $method->setAccessible(true);

        $cases = [
            '=SUM(A1:A2)' => "'=SUM(A1:A2)",
            '  +cmd' => "'  +cmd",
            '-10' => "'-10",
            '@payload' => "'@payload",
            'ordinary label' => 'ordinary label',
        ];
        foreach ($cases as $value => $expected) {
            $actual = $method->invoke($controller, $value);
            if ($actual !== $expected) {
                throw new RuntimeException("csvText({$value}) returned {$actual}, expected {$expected}.");
            }
        }
    },

    'file response supplies safe download headers' => static function (): void {
        $response = Response::file('contents', 'report 2026.csv', 'text/csv; charset=utf-8');
        $property = new ReflectionProperty(Response::class, 'headers');
        $property->setAccessible(true);
        $headers = $property->getValue($response);

        $expected = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="report%202026.csv"',
            'Content-Length' => '8',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($headers !== $expected) {
            throw new RuntimeException('The file response did not provide the expected download headers.');
        }
    },
];
