<?php

declare(strict_types=1);

use App\Support\Secret;

return [
    'mounted secrets preserve special characters and reject ambiguous sources' => static function (): void {
        $path = tempnam(sys_get_temp_dir(), 'secret-test-');
        $previous = getenv('SECURITY_TEST_SECRET');
        $previousFile = getenv('SECURITY_TEST_SECRET_FILE');
        try {
            $value = "synthetic quote' slash\\ dollar$ and spaces";
            file_put_contents($path, $value . "\n");
            putenv('SECURITY_TEST_SECRET');
            putenv('SECURITY_TEST_SECRET_FILE=' . $path);
            if (Secret::read('SECURITY_TEST_SECRET', true) !== $value) { throw new RuntimeException('File secret changed'); }
            putenv('SECURITY_TEST_SECRET=ambiguous');
            try { Secret::read('SECURITY_TEST_SECRET'); } catch (RuntimeException) { return; }
            throw new RuntimeException('Ambiguous secret sources accepted');
        } finally {
            putenv($previous === false ? 'SECURITY_TEST_SECRET' : 'SECURITY_TEST_SECRET=' . $previous);
            putenv($previousFile === false ? 'SECURITY_TEST_SECRET_FILE' : 'SECURITY_TEST_SECRET_FILE=' . $previousFile);
            unlink($path);
        }
    },
];
