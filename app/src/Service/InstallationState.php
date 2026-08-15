<?php

declare(strict_types=1);

namespace App\Service;

final class InstallationState
{
    public function __construct(
        private string $basePath
    ) {
    }

    public function wasInitialized(): bool
    {
        return is_file($this->basePath . '/app/storage/app/installed.lock');
    }

    public function markInitialized(): void
    {
        $directory = $this->basePath . '/app/storage/app';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create installation state directory.');
        }
        $path = $directory . '/installed.lock';
        if (file_put_contents($path, "Initialized. Restore configuration from backup.\n", LOCK_EX) === false || !chmod($path, 0600)) {
            throw new \RuntimeException('Could not preserve installation state.');
        }
    }

    public function isInstalled(): bool
    {
        return is_file($this->configFile());
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        $config = require $this->configFile();
        if (!is_array($config)) {
            return [];
        }

        /** @var array<string, mixed> $config */
        $app = isset($config['app']) && is_array($config['app']) ? $config['app'] : [];
        $database = isset($config['database']) && is_array($config['database']) ? $config['database'] : [];

        return [
            'app_name' => (string) ($app['name'] ?? 'Account+'),
            'db_host' => (string) ($database['host'] ?? ''),
            'db_port' => (int) ($database['port'] ?? 3306),
            'db_name' => (string) ($database['database'] ?? ''),
            'db_username' => (string) ($database['username'] ?? ''),
            'db_password' => (string) ($database['password'] ?? ''),
            'installed_via_config' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function write(array $payload): void
    {
        $content = <<<PHP
<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => %s,
        'env' => 'production',
        'debug' => false,
        'url' => %s,
        'session_name' => 'php_compte',
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => %s,
        'port' => %d,
        'database' => %s,
        'username' => %s,
        'password' => %s,
        'charset' => 'utf8mb4',
    ],
];
PHP;

        $generated = sprintf(
            $content,
            var_export((string) ($payload['app_name'] ?? 'Account+'), true),
            var_export((string) ($payload['app_url'] ?? 'http://127.0.0.1:8000'), true),
            var_export((string) ($payload['db_host'] ?? ''), true),
            (int) ($payload['db_port'] ?? 3306),
            var_export((string) ($payload['db_name'] ?? ''), true),
            var_export((string) ($payload['db_username'] ?? ''), true),
            var_export((string) ($payload['db_password'] ?? ''), true),
        );

        $path = $this->configFile();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create configuration directory.');
        }
        $temporary = tempnam($directory, '.config-');
        if ($temporary === false) {
            throw new \RuntimeException('Could not create configuration file.');
        }
        try {
            if (file_put_contents($temporary, $generated . PHP_EOL) === false
                || !chmod($temporary, 0600)
                || !rename($temporary, $path)) {
                throw new \RuntimeException('Could not save configuration file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function clear(): void
    {
        if ($this->isInstalled()) {
            unlink($this->configFile());
        }
    }

    public function configFile(): string
    {
        $legacy = rtrim($this->basePath, '/') . '/config.php';

        return is_file($legacy) ? $legacy : rtrim($this->basePath, '/') . '/app/storage/app/config.php';
    }
}
