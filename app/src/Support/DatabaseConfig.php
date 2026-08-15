<?php

declare(strict_types=1);

namespace App\Support;

use App\Service\InstallationState;

final class DatabaseConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function resolve(Config $config, InstallationState $installationState): array
    {
        /** @var array<string, mixed> $database */
        $database = (array) $config->get('database', []);

        return $database;
    }
}
