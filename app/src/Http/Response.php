<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $content,
        private int $status = 200,
        private array $headers = ['Content-Type' => 'text/html; charset=utf-8']
    ) {
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status);
    }

    public static function internalLocation(mixed $location, string $fallback = '/companies'): string
    {
        if (!is_string($location) || strlen($location) > 4096) {
            return $fallback;
        }
        $decoded = $location;
        for ($i = 0; $i <= strlen($location); $i++) {
            if (!str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
                || preg_match('/[\\\\\x00-\x1f\x7f]/', $decoded) === 1) {
                return $fallback;
            }
            $parts = parse_url($decoded);
            if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
                return $fallback;
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                return $location;
            }
            $decoded = $next;
        }
        return $fallback;
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => self::internalLocation($location)]);
    }

    /**
     * Builds a response that forces the browser to download a file.
     *
     * @param array<string, string> $headers
     */
    public static function file(string $content, string $filename, string $contentType = 'application/octet-stream', int $status = 200, array $headers = []): self
    {
        $headers = array_merge([
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
        ], $headers);

        return new self($content, $status, $headers);
    }

    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->headers[$name] = $value;
        return $copy;
    }

    public function send(): void
    {
        if ($this->status === 419) {
            // Apache needs an explicit reason phrase for this application status.
            header('HTTP/1.1 419 Page Expired', true, 419);
        } else {
            http_response_code($this->status);
        }
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->content;
    }
}
