<?php

declare(strict_types=1);

// Reject old upstream releases rather than silently assuming distribution backports.
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4 || version_compare(PHP_VERSION, '8.4.27', '<')) {
    fwrite(STDERR, "Account+ requires PHP 8.4.27 or newer on the 8.4 branch.\n");
    exit(1);
}
foreach (['pdo_mysql', 'zip', 'fileinfo', 'session', 'json', 'zlib', 'ctype'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Required PHP extension missing: {$extension}\n");
        exit(1);
    }
}
echo 'Runtime verified: PHP ' . PHP_VERSION . PHP_EOL;
