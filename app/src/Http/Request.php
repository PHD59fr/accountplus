<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $request
     * @param array<string, array<string, mixed>> $files
     * @param array<string, string> $server
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $request,
        private array $files,
        private array $server
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $request */
        $request = $_POST;
        /** @var array<string, array<string, mixed>> $files */
        $files = $_FILES ?? [];
        /** @var array<string, string> $server */
        $server = $_SERVER;

        return new self($method, $path, $query, $request, $files, $server);
    }

    public function clientIp(): string
    {
        $peer = $this->server('REMOTE_ADDR');
        if (filter_var($peer, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        if (!$this->trusted($peer)) {
            return $this->canonicalIp($peer);
        }
        $forwarded = $this->server('HTTP_X_FORWARDED_FOR');
        if ($forwarded === '' || strlen($forwarded) > 4096) {
            return $this->canonicalIp($peer);
        }
        $chain = array_map('trim', explode(',', $forwarded));
        if (count($chain) > 32) {
            return $this->canonicalIp($peer);
        }
        foreach ($chain as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                return $this->canonicalIp($peer);
            }
        }
        $chain[] = $peer;
        for ($i = count($chain) - 1; $i > 0; $i--) {
            if (!$this->trusted($chain[$i])) {
                return $this->canonicalIp($chain[$i]);
            }
        }
        return $this->canonicalIp($chain[0]);
    }

    public function isSecure(): bool
    {
        $https = $this->server('HTTPS');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        // The trusted ingress must overwrite this header with a single protocol.
        return $this->trusted($this->server('REMOTE_ADDR'))
            && strtolower($this->server('HTTP_X_FORWARDED_PROTO')) === 'https';
    }

    private function canonicalIp(string $ip): string
    {
        $packed = inet_pton($ip);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === hex2bin('00000000000000000000ffff')) {
            $packed = substr($packed, 12);
        }
        return (string) inet_ntop($packed);
    }

    private function trusted(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $ip = $this->canonicalIp($ip);
        foreach (explode(',', getenv('APP_TRUSTED_PROXIES') ?: '') as $entry) {
            $parts = explode('/', trim($entry));
            if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $network = inet_pton($this->canonicalIp($parts[0]));
            $client = inet_pton($ip);
            $bits = $parts[1] ?? (string) (strlen($network) * 8);
            if (strlen($network) !== strlen($client) || !ctype_digit($bits) || (int) $bits > strlen($network) * 8) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $remainder = (int) $bits % 8;
            if (substr($network, 0, $bytes) === substr($client, 0, $bytes)
                && ($remainder === 0 || ((ord($network[$bytes]) ^ ord($client[$bytes])) & (0xff << (8 - $remainder))) === 0)) {
                return true;
            }
        }
        return false;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        return $this->request[$key] ?? $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    public function server(string $key, string $default = ''): string
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file)) {
            return null;
        }

        return $file;
    }
}
