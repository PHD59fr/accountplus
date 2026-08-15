<?php

declare(strict_types=1);

use App\Service\InstallationState;
use App\Support\Config;

return [
    'configuration is stored privately and loaded from persistent storage' => static function (): void {
        $base = sys_get_temp_dir() . '/accountplus-config-' . bin2hex(random_bytes(6));
        $state = new InstallationState($base);
        try {
            $state->write(['app_name' => 'Persistent test', 'db_password' => "quote'\\test"]);
            if (!$state->isInstalled() || !is_file($base . '/app/storage/app/config.php')) {
                throw new RuntimeException('Configuration was not saved in persistent storage.');
            }
            $config = Config::fromBasePath($base);
            if ($config->get('app.name') !== 'Persistent test' || $config->get('database.password') !== "quote'\\test") {
                throw new RuntimeException('Configuration values did not round trip.');
            }
            if ((fileperms($state->configFile()) & 0777) !== 0600) {
                throw new RuntimeException('Configuration permissions must be 0600.');
            }
        } finally {
            $state->clear();
            foreach (['/app/storage/app', '/app/storage', '/app', ''] as $suffix) {
                if (is_dir($base . $suffix)) {
                    rmdir($base . $suffix);
                }
            }
        }
    },
    'legacy root configuration is still supported' => static function (): void {
        $base = sys_get_temp_dir() . '/accountplus-legacy-' . bin2hex(random_bytes(6));
        mkdir($base);
        file_put_contents($base . '/config.php', '<?php return ["app" => ["name" => "Legacy"]];');
        try {
            if (!(new InstallationState($base))->isInstalled() || Config::fromBasePath($base)->get('app.name') !== 'Legacy') {
                throw new RuntimeException('Legacy configuration was not loaded.');
            }
        } finally {
            unlink($base . '/config.php');
            rmdir($base);
        }
    },
];
