<?php

declare(strict_types=1);

use App\Http\Request;

return [
    'proxy headers require a trusted peer and valid complete IP chain' => static function (): void {
        $previous = getenv('APP_TRUSTED_PROXIES');
        $secure = getenv('APP_SESSION_SECURE');
        putenv('APP_SESSION_SECURE=false');
        $request = static fn (string $peer, string $chain): Request => new Request('GET', '/', [], [], [], [
            'REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $chain, 'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        try {
            putenv('APP_TRUSTED_PROXIES');
            if ($request('192.0.2.1', '198.51.100.1')->clientIp() !== '192.0.2.1' || $request('192.0.2.1', '')->isSecure()) {
                throw new RuntimeException('Untrusted proxy spoofed identity');
            }
            putenv('APP_TRUSTED_PROXIES=192.0.2.0/24,2001:db8::/32');
            foreach ([
                ['192.0.2.1', '198.51.100.1, 192.0.2.2', '198.51.100.1'],
                ['192.0.2.1', 'invalid, 198.51.100.1', '192.0.2.1'],
                ['192.0.2.1', '203.0.113.99, 198.51.100.1', '198.51.100.1'],
                ['2001:db8::1', '2001:4860::1', '2001:4860::1'],
                ['::ffff:192.0.2.1', '198.51.100.1', '198.51.100.1'],
            ] as [$peer, $chain, $expected]) {
                if ($request($peer, $chain)->clientIp() !== $expected) { throw new RuntimeException('Wrong proxy chain resolution'); }
            }
            if (!$request('192.0.2.1', '')->isSecure()) { throw new RuntimeException('Trusted TLS was ignored'); }
        } finally {
            putenv($previous === false ? 'APP_TRUSTED_PROXIES' : 'APP_TRUSTED_PROXIES=' . $previous);
            putenv($secure === false ? 'APP_SESSION_SECURE' : 'APP_SESSION_SECURE=' . $secure);
        }
    },
];
