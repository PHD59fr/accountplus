<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;

final class IpRestrictionService
{
    private const BEGIN = '# BEGIN ACCOUNTPLUS IP RESTRICTIONS';
    private const END = '# END ACCOUNTPLUS IP RESTRICTIONS';

    public function __construct(private string $basePath, private ?AuditLogService $audit = null, private ?string $htaccessPath = null)
    {
    }

    /** @return array{mode: string, whitelist: list<string>, blacklist: list<string>} */
    public function read(): array
    {
        return $this->parse($this->contents());
    }

    public function writable(): bool
    {
        $path = $this->path();
        return is_writable($path) && is_writable(dirname($path));
    }

    public function managedBlock(): string
    {
        return $this->block($this->read());
    }

    private function path(): string
    {
        $path = $this->htaccessPath ?? $this->basePath . '/.htaccess';
        return realpath($path) ?: $path;
    }

    public function allows(string $clientIp): bool
    {
        $settings = $this->read();
        if ($settings['mode'] === 'disabled') {
            return true;
        }
        $listed = false;
        foreach ($settings[$settings['mode']] as $entry) {
            $listed = $listed || $this->contains($entry, $clientIp);
        }
        return $settings['mode'] === 'whitelist' ? $listed : !$listed;
    }

    public function update(string $mode, string $whitelist, string $blacklist, string $clientIp): void
    {
        $settings = $this->validate($mode, $whitelist, $blacklist);
        if ($mode !== 'disabled') {
            if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('settings.ip_error_client');
            }
            $listed = false;
            foreach ($settings[$mode] as $entry) {
                $listed = $listed || $this->contains($entry, $clientIp);
            }
            if (($mode === 'whitelist' && !$listed) || ($mode === 'blacklist' && $listed)) {
                throw new InvalidArgumentException('settings.ip_error_lockout');
            }
        }
        if (!$this->writable()) {
            throw new RuntimeException('settings.ip_error_unwritable');
        }

        $lock = @fopen($this->basePath . '/app/storage/app/ip-restrictions.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('settings.ip_error_write');
        }
        $temporary = false;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('settings.ip_error_write');
            }
            $contents = $this->contents();
            $old = $this->parse($contents);
            $block = $this->block($settings);
            $pattern = '/^' . preg_quote(self::BEGIN, '/') . '\r?\n.*?^' . preg_quote(self::END, '/') . '\r?$/ms';
            $updated = preg_match($pattern, $contents) === 1
                ? preg_replace_callback($pattern, static fn () => $block, $contents)
                : rtrim($contents) . "\n\n" . $block . "\n";

            $path = $this->path();
            $directory = dirname($path);
            $temporary = @tempnam($directory, '.htaccess-');
            $permissions = @fileperms($path);
            if ($temporary === false || dirname($temporary) !== realpath($directory)
                || $permissions === false || !is_string($updated)
                || @file_put_contents($temporary, $updated) !== strlen($updated)
                || !@chmod($temporary, $permissions & 0777)
                || !@rename($temporary, $path)) {
                throw new RuntimeException('settings.ip_error_write');
            }
            $temporary = false;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        if ($this->audit !== null) {
            $this->audit->log('settings.ip_restrictions.update', 'settings', 'ip_restrictions', $old, $settings);
        }
    }

    private function contents(): string
    {
        $contents = @file_get_contents($this->path());
        if ($contents === false) {
            throw new RuntimeException('settings.ip_error_read');
        }
        return $contents;
    }

    /** @return array{mode: string, whitelist: list<string>, blacklist: list<string>} */
    private function parse(string $contents): array
    {
        if (!str_contains($contents, self::BEGIN) && !str_contains($contents, self::END)) {
            return ['mode' => 'disabled', 'whitelist' => [], 'blacklist' => []];
        }
        if (substr_count($contents, self::BEGIN) !== 1 || substr_count($contents, self::END) !== 1
            || preg_match('/^' . preg_quote(self::BEGIN, '/') . '\r?\n.*?^' . preg_quote(self::END, '/') . '\r?$/ms', $contents, $match) !== 1
            || preg_match('/^# Settings: ([^\r\n]+)\r?$/m', $match[0], $metadata) !== 1) {
            throw new RuntimeException('settings.ip_error_invalid_block');
        }
        $settings = json_decode($metadata[1], true);
        if (!is_array($settings) || !is_string($settings['mode'] ?? null)
            || !is_array($settings['whitelist'] ?? null) || !is_array($settings['blacklist'] ?? null)
            || array_filter(array_merge($settings['whitelist'], $settings['blacklist']), static fn ($value) => !is_string($value)) !== []) {
            throw new RuntimeException('settings.ip_error_invalid_block');
        }
        try {
            $validated = $this->validate($settings['mode'], implode("\n", $settings['whitelist']), implode("\n", $settings['blacklist']));
        } catch (InvalidArgumentException) {
            throw new RuntimeException('settings.ip_error_invalid_block');
        }
        if (str_replace("\r\n", "\n", $match[0]) !== $this->block($validated)) {
            throw new RuntimeException('settings.ip_error_invalid_block');
        }
        return $validated;
    }

    /** @return array{mode: string, whitelist: list<string>, blacklist: list<string>} */
    private function validate(string $mode, string $whitelist, string $blacklist): array
    {
        if (!in_array($mode, ['disabled', 'whitelist', 'blacklist'], true)) {
            throw new InvalidArgumentException('settings.ip_error_mode');
        }
        $settings = ['mode' => $mode, 'whitelist' => $this->entries($whitelist), 'blacklist' => $this->entries($blacklist)];
        if ($mode !== 'disabled' && $settings[$mode] === []) {
            throw new InvalidArgumentException('settings.ip_error_empty');
        }
        return $settings;
    }

    /** @return list<string> */
    private function entries(string $input): array
    {
        if (strlen($input) > 20000) {
            throw new InvalidArgumentException('settings.ip_error_limit');
        }
        $entries = [];
        foreach (preg_split('/\R/', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('/', $line);
            $ip = $parts[0];
            if (count($parts) > 2 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('settings.ip_error_address');
            }
            $packed = inet_pton($ip);
            $mapped = strlen($packed) === 16 && substr($packed, 0, 12) === hex2bin('00000000000000000000ffff');
            $ip = (string) inet_ntop($mapped ? substr($packed, 12) : $packed);
            if (isset($parts[1])) {
                if (preg_match('/^[0-9]{1,3}$/D', $parts[1]) !== 1
                    || (int) $parts[1] > ($mapped || str_contains($ip, ':') ? 128 : 32)
                    || ($mapped && (int) $parts[1] < 96)) {
                    throw new InvalidArgumentException('settings.ip_error_address');
                }
                $ip .= '/' . ((int) $parts[1] - ($mapped ? 96 : 0));
            }
            $entries[] = $ip;
        }
        $entries = array_values(array_unique($entries));
        if (count($entries) > 256) {
            throw new InvalidArgumentException('settings.ip_error_limit');
        }
        return $entries;
    }

    private function contains(string $entry, string $clientIp): bool
    {
        $parts = explode('/', $entry);
        $network = inet_pton($parts[0]);
        $client = @inet_pton($clientIp);
        if ($client !== false && strlen($client) === 16 && substr($client, 0, 12) === hex2bin('00000000000000000000ffff')) {
            $client = substr($client, 12);
        }
        if ($network === false || $client === false || strlen($network) !== strlen($client)) {
            return false;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($network) * 8;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        return substr($network, 0, $bytes) === substr($client, 0, $bytes)
            && ($remainder === 0 || ((ord($network[$bytes]) ^ ord($client[$bytes])) & (0xff << (8 - $remainder))) === 0);
    }

    /** @param array{mode: string, whitelist: list<string>, blacklist: list<string>} $settings */
    private function block(array $settings): string
    {
        $lines = [self::BEGIN, '# Settings: ' . json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        if ($settings['mode'] === 'whitelist') {
            $lines[] = '<RequireAny>';
            foreach ($settings['whitelist'] as $entry) {
                $lines[] = '    Require ip ' . $entry;
            }
            $lines[] = '</RequireAny>';
        } elseif ($settings['mode'] === 'blacklist') {
            $lines[] = '<RequireAll>';
            $lines[] = '    Require all granted';
            foreach ($settings['blacklist'] as $entry) {
                $lines[] = '    Require not ip ' . $entry;
            }
            $lines[] = '</RequireAll>';
        }
        $lines[] = self::END;
        return implode("\n", $lines);
    }
}
