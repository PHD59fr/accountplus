<?php

declare(strict_types=1);

use App\Support\SetupAuthorization;
use App\Http\Request;
use App\Support\SecurityError;

return [
    'setup requires explicit mode and a strong server token' => static function (): void {
        $oldEnabled = getenv('APP_SETUP_ENABLED');
        $oldToken = getenv('APP_SETUP_TOKEN');
        try {
            putenv('APP_SETUP_ENABLED=false');
            putenv('APP_SETUP_TOKEN=' . str_repeat('t', 32));
            if (SetupAuthorization::accepts(str_repeat('t', 32))) { throw new RuntimeException('Disabled setup accepted'); }
            putenv('APP_SETUP_ENABLED=true');
            foreach ([null, [], 123, 'wrong'] as $value) {
                if (SetupAuthorization::accepts($value)) { throw new RuntimeException('Invalid token accepted'); }
            }
            if (!SetupAuthorization::accepts(str_repeat('t', 32))) { throw new RuntimeException('Valid token rejected'); }
            $request = new Request('POST', '/setup', ['setup_token' => str_repeat('t', 32)], [], [], []);
            if ($request->post('setup_token') !== null) { throw new RuntimeException('GET token accepted'); }
            if (csrf_validate([])) { throw new RuntimeException('Array CSRF accepted'); }
        } finally {
            putenv($oldEnabled === false ? 'APP_SETUP_ENABLED' : 'APP_SETUP_ENABLED=' . $oldEnabled);
            putenv($oldToken === false ? 'APP_SETUP_TOKEN' : 'APP_SETUP_TOKEN=' . $oldToken);
        }
    },
    'error logs omit secret exception messages and expose a correlation reference' => static function (): void {
        $log = tempnam(sys_get_temp_dir(), 'security-log-');
        $previous = ini_get('error_log');
        ini_set('error_log', $log);
        try {
            $reference = SecurityError::report(new RuntimeException('SECRET_PASSWORD_TEST PRIVATE_DB_HOST_TEST INTERNAL_SQL_QUERY_TEST'), 'test.failure');
            $contents = file_get_contents($log);
            if (!str_contains($contents, $reference) || !str_contains($contents, 'RuntimeException') || str_contains($contents, '_TEST')) {
                throw new RuntimeException('Unsafe diagnostic output');
            }
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
        }
    },
];
