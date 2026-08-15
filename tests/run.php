<?php

declare(strict_types=1);

/*
 * Lightweight test runner without external dependencies.
 * Run with: php tests/run.php
 *
 * Covers the pure PHP logic (archive I/O, date math, import parsing)
 * that does not require a MySQL connection. Database integration
 * tests belong in tests/Integration and need a real PDO driver.
 */

$basePath = dirname(__DIR__);
require_once $basePath . '/app/src/Support/helpers.php';
require_once $basePath . '/app/src/Support/Autoloader.php';

\App\Support\Autoloader::register($basePath . '/app');

$testFiles = glob($basePath . '/tests/Unit/*Test.php') ?: [];
sort($testFiles);

$total = 0;
$failed = 0;

foreach ($testFiles as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, 'Missing test file: ' . $file . PHP_EOL);
        $failed++;
        continue;
    }

    $test = require $file;
    if (!is_array($test)) {
        fwrite(STDERR, 'Test file must return an array of checks: ' . $file . PHP_EOL);
        $failed++;
        continue;
    }

    foreach ($test as $name => $callback) {
        $total++;
        try {
            $callback();
            fwrite(STDOUT, "ok   " . basename($file) . " :: {$name}" . PHP_EOL);
        } catch (Throwable $throwable) {
            $failed++;
            fwrite(STDOUT, "FAIL " . basename($file) . " :: {$name}" . PHP_EOL);
            fwrite(STDOUT, "     " . $throwable->getMessage() . PHP_EOL);
        }
    }
}

fwrite(STDOUT, PHP_EOL . "{$total} checks, {$failed} failed" . PHP_EOL);
exit($failed === 0 ? 0 : 1);
