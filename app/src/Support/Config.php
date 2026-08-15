<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items)
    {
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function fromBasePath(string $basePath): self
    {
        $rootConfig = (new \App\Service\InstallationState($basePath))->configFile();
        if (is_file($rootConfig)) {
            $items = require $rootConfig;

            if (!is_array($items) || $items === []) {
                throw new RuntimeException('Configuration file is empty or invalid.');
            }

            return new self($items);
        }

        $configPath = rtrim($basePath, '/') . '/app/config';
        $items = [];
        foreach (glob($configPath . '/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }

        if ($items === []) {
            throw new RuntimeException('No configuration files found.');
        }

        return new self($items);
    }
}
