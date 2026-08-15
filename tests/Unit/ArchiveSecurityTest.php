<?php

declare(strict_types=1);

use App\Support\Archive;

return [
    'NDJSON physical line limit is enforced without splitting an unbounded array' => static function (): void {
        $service = new App\Service\ImportService(null, null, null, null, null, null, null, '');
        $parse = new ReflectionMethod($service, 'parseNdjson');
        try {
            $parse->invoke($service, "{}\n" . str_repeat("\n", 200000) . '{}', 'accounts', []);
        } catch (InvalidArgumentException $error) {
            if (str_contains($error->getMessage(), 'record count')) { return; }
            throw $error;
        }
        throw new RuntimeException('Excessive NDJSON lines accepted');
    },
    'archives reject traversal absolute names special entries truncation and bad CRC' => static function (): void {
        $path = tempnam(sys_get_temp_dir(), 'archive-security-');
        $reject = static function () use ($path): void {
            try { Archive::read($path); } catch (RuntimeException) { return; }
            throw new RuntimeException('Unsafe archive accepted');
        };
        try {
            foreach (['../escape', '/absolute', 'C:/absolute', 'C:\\absolute', '..\\escape', "null\0byte"] as $name) {
                Archive::write([$name => 'contents'], $path);
                $reject();
            }
            Archive::write(['data.txt' => 'contents'], $path);
            $regular = file_get_contents($path);
            $central = strpos($regular, "PK\x01\x02");
            foreach ([0120000, 0040000, 0010000] as $type) {
                file_put_contents($path, substr_replace($regular, pack('V', ($type | 0600) << 16), $central + 38, 4));
                $reject();
            }
            file_put_contents($path, substr($regular, 0, -5));
            $reject();
            file_put_contents($path, substr_replace($regular, pack('V', 0), $central + 16, 4));
            $reject();
            Archive::write(['valid.txt' => 'ok'], $path);
            if ((fileperms($path) & 0777) !== 0600 || Archive::read($path)['valid.txt'] !== 'ok') {
                throw new RuntimeException('Private round trip failed');
            }
        } finally { unlink($path); }
    },
];
