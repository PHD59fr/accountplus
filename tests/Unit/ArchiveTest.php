<?php

declare(strict_types=1);

use App\Support\Archive;

/**
 * @return array<string, callable>
 */
return [
    'write and read round-trip with utf-8 content' => static function (): void {
        $path = sys_get_temp_dir() . '/archive_test_' . bin2hex(random_bytes(4)) . '.zip';
        $entries = [
            'manifest.json' => json_encode(['format' => 'compte-import', 'version' => 1], JSON_UNESCAPED_UNICODE),
            'transactions.ndjson' => "{\"label\":\"héllo\"}\n{\"amount\":\"-45.90\"}\n",
        ];
        Archive::write($entries, $path);
        $read = Archive::read($path);
        @unlink($path);

        if (array_keys($read) !== array_keys($entries)) {
            throw new RuntimeException('Archive entry names do not match.');
        }
        if ($read['transactions.ndjson'] !== $entries['transactions.ndjson']) {
            throw new RuntimeException('Archive content was corrupted by the round-trip.');
        }
    },

    'entries are compressed with deflate' => static function (): void {
        $path = sys_get_temp_dir() . '/archive_test_' . bin2hex(random_bytes(4)) . '.zip';
        Archive::write(['data.txt' => str_repeat('abcdefgh', 1024)], $path);
        $size = filesize($path);
        @unlink($path);

        if ($size >= 8192) {
            throw new RuntimeException('Expected deflate compression to shrink the payload.');
        }
    },

    'read rejects a non-archive file' => static function (): void {
        $path = sys_get_temp_dir() . '/archive_test_' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($path, 'not a zip at all');
        $rejected = false;
        try {
            Archive::read($path);
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        @unlink($path);
        if (!$rejected) {
            throw new RuntimeException('Expected read to fail on garbage input.');
        }
    },

    'read enforces compressed archive size limit' => static function (): void {
        $path = sys_get_temp_dir() . '/archive_test_' . bin2hex(random_bytes(4)) . '.zip';
        Archive::write(['data.txt' => 'contents'], $path);
        $rejected = false;
        try {
            Archive::read($path, 4);
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        @unlink($path);
        if (!$rejected) {
            throw new RuntimeException('Expected an oversized archive to be rejected.');
        }
    },

    'read enforces total uncompressed size limit' => static function (): void {
        $path = sys_get_temp_dir() . '/archive_test_' . bin2hex(random_bytes(4)) . '.zip';
        Archive::write(['data.txt' => str_repeat('a', 100)], $path);
        $rejected = false;
        try {
            Archive::read($path, 1024 * 1024, 50);
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        @unlink($path);
        if (!$rejected) {
            throw new RuntimeException('Expected oversized archive contents to be rejected.');
        }
    },
];
