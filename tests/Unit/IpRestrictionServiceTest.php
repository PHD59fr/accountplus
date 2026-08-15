<?php

declare(strict_types=1);

use App\Service\IpRestrictionService;

return [
    'switches exclusive modes while preserving both lists and unrelated rules' => static function (): void {
        ipRestrictionFixture(static function (IpRestrictionService $service, string $root): void {
            $original = file_get_contents($root . '/.htaccess');
            $service->update('whitelist', "192.0.2.0/24\n2001:db8::/32\n192.0.2.0/24", '198.51.100.0/24', '192.0.2.5');
            $contents = file_get_contents($root . '/.htaccess');
            ipAssert(str_starts_with($contents, $original), 'Unrelated rules changed');
            ipAssert(str_contains($contents, 'Require ip 192.0.2.0/24'), 'Whitelist is missing');
            ipAssert(!str_contains($contents, 'Require not ip'), 'Blacklist is active at the same time');
            ipAssert(count($service->read()['whitelist']) === 2, 'Duplicate was not removed');
            $service->update('blacklist', implode("\n", $service->read()['whitelist']), '198.51.100.0/24', '192.0.2.5');
            $contents = file_get_contents($root . '/.htaccess');
            ipAssert(!str_contains($contents, 'Require ip '), 'Whitelist stayed active');
            ipAssert(str_contains($contents, 'Require not ip 198.51.100.0/24'), 'Blacklist is missing');
            $saved = $service->read();
            $service->update('disabled', implode("\n", $saved['whitelist']), implode("\n", $saved['blacklist']), '192.0.2.5');
            ipAssert(!str_contains(file_get_contents($root . '/.htaccess'), '<Require'), 'Disabled rules still restrict access');
            ipAssert($service->read()['whitelist'] === $saved['whitelist'] && $service->read()['blacklist'] === $saved['blacklist'], 'Inactive lists were lost');
        });
    },
    'rejects injection, invalid CIDR, empty active lists and multiple modes without writing' => static function (): void {
        ipRestrictionFixture(static function (IpRestrictionService $service, string $root): void {
            $original = file_get_contents($root . '/.htaccess');
            foreach (['Require all granted', "192.0.2.1\n</RequireAny>", '192.0.2.1/33', '2001:db8::/129', '192.0.2.1/-1', '192.0.2.1/1/2', 'example.com', '192.0.2.1/24 Require all granted'] as $bad) {
                ipExpectRejection(static fn () => $service->update('whitelist', $bad, '', '192.0.2.1'));
            }
            ipExpectRejection(static fn () => $service->update('whitelist', '', '', '192.0.2.1'));
            ipExpectRejection(static fn () => $service->update('blacklist', '', '', '192.0.2.1'));
            ipExpectRejection(static fn () => $service->update('whitelist,blacklist', '192.0.2.1', '', '192.0.2.1'));
            ipExpectRejection(static fn () => $service->update('disabled', str_repeat(' ', 20001), '', '192.0.2.1'));
            $tooMany = implode("\n", array_map(static fn ($i) => '192.0.' . intdiv($i, 256) . '.' . ($i % 256), range(0, 256)));
            ipExpectRejection(static fn () => $service->update('disabled', $tooMany, '', '192.0.2.1'));
            ipAssert(file_get_contents($root . '/.htaccess') === $original, 'Invalid input modified the file');
        });
    },
    'prevents self lockout for IPv4 and IPv6 networks including partial prefixes' => static function (): void {
        ipRestrictionFixture(static function (IpRestrictionService $service): void {
            foreach ([['192.0.2.128/25', '192.0.2.200'], ['2001:db8::80/121', '2001:db8::ff'], ['0.0.0.0/0', '192.0.2.1'], ['::/0', '2001:db8::1']] as [$network, $client]) {
                $service->update('whitelist', $network, '', $client);
                ipExpectRejection(static fn () => $service->update('blacklist', '', $network, $client), 'settings.ip_error_lockout');
            }
            foreach ([['192.0.2.128/25', '192.0.2.127'], ['2001:db8::80/121', '2001:db8::7f'], ['192.0.2.1/32', '192.0.2.2'], ['2001:db8::1/128', '2001:db8::2']] as [$network, $client]) {
                $service->update('blacklist', '', $network, $client);
                ipExpectRejection(static fn () => $service->update('whitelist', $network, '', $client), 'settings.ip_error_lockout');
            }
            ipExpectRejection(static fn () => $service->update('whitelist', '192.0.2.1', '', ''), 'settings.ip_error_client');
        });
    },
    'refuses damaged managed blocks and preserves the file' => static function (): void {
        ipRestrictionFixture(static function (IpRestrictionService $service, string $root): void {
            $service->update('whitelist', '192.0.2.1', '', '192.0.2.1');
            $damaged = str_replace('Require ip 192.0.2.1', 'Require all granted', file_get_contents($root . '/.htaccess'));
            file_put_contents($root . '/.htaccess', $damaged);
            try {
                $service->update('disabled', '', '', '192.0.2.1');
                throw new LogicException('Damaged block was silently replaced');
            } catch (RuntimeException $error) {
                ipAssert($error->getMessage() === 'settings.ip_error_invalid_block', 'Unexpected error');
            }
            ipAssert(file_get_contents($root . '/.htaccess') === $damaged, 'Damaged file was overwritten');
        });
    },
    'normalizes IPv4 mapped IPv6 addresses when checking administrator access' => static function (): void {
        ipRestrictionFixture(static function (IpRestrictionService $service): void {
            $service->update('whitelist', '::ffff:192.0.2.0/120', '', '::ffff:192.0.2.1');
            ipAssert($service->read()['whitelist'] === ['192.0.2.0/24'], 'Mapped network was not normalized');
            ipExpectRejection(static fn () => $service->update('blacklist', '', '192.0.2.0/24', '::ffff:192.0.2.1'), 'settings.ip_error_lockout');
            ipExpectRejection(static fn () => $service->update('blacklist', '', '::ffff:192.0.2.1', '192.0.2.1'), 'settings.ip_error_lockout');
        });
    },
    'updates persistent symlink targets atomically without replacing the link' => static function (): void {
        ipRestrictionFixture(static function (IpRestrictionService $service, string $root): void {
            rename($root . '/.htaccess', $root . '/app/storage/app/apache.htaccess');
            symlink($root . '/app/storage/app/apache.htaccess', $root . '/.htaccess');
            $service->update('blacklist', '', '198.51.100.0/24', '192.0.2.1');
            ipAssert(is_link($root . '/.htaccess'), 'Persistent symlink was replaced');
            ipAssert($service->read()['mode'] === 'blacklist', 'Persistent rules were not saved');
            ipAssert((fileperms($root . '/app/storage/app/apache.htaccess') & 0777) === 0644, 'Permissions changed');
        });
    },
    'reports read only deployments without changing existing rules' => static function (): void {
        ipRestrictionFixture(static function (IpRestrictionService $service, string $root): void {
            $original = file_get_contents($root . '/.htaccess');
            chmod($root . '/.htaccess', 0444);
            clearstatcache();
            ipAssert(!$service->writable(), 'Read only file was reported writable');
            try {
                $service->update('whitelist', '192.0.2.1', '', '192.0.2.1');
                throw new LogicException('Read only deployment accepted an update');
            } catch (RuntimeException $error) {
                ipAssert($error->getMessage() === 'settings.ip_error_unwritable', 'Unexpected permission error');
            }
            ipAssert(file_get_contents($root . '/.htaccess') === $original, 'Read only rules changed');
        });
    },
];

function ipRestrictionFixture(callable $check): void
{
    $root = sys_get_temp_dir() . '/accountplus-ip-' . bin2hex(random_bytes(6));
    mkdir($root . '/app/storage/app', 0700, true);
    file_put_contents($root . '/.htaccess', "DirectoryIndex index.php\nRewriteEngine On\nRewriteRule ^app/ - [F,L]\n");
    chmod($root . '/.htaccess', 0644);
    try {
        $check(new IpRestrictionService($root), $root);
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    }
}

function ipAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ipExpectRejection(callable $callback, ?string $key = null): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        ipAssert($key === null || $error->getMessage() === $key, 'Unexpected validation error');
        return;
    }
    throw new LogicException('Invalid restriction was accepted');
}
