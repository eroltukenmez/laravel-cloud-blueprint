<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

enum DatabaseClusterType: string
{
    case LARAVEL_MYSQL_8 = 'laravel_mysql_8';
    case NEON_SERVERLESS_POSTGRES_18 = 'neon_serverless_postgres_18';
    case NEON_SERVERLESS_POSTGRES_17 = 'neon_serverless_postgres_17';

    public function isNeon(): bool
    {
        return $this === self::NEON_SERVERLESS_POSTGRES_18
            || $this === self::NEON_SERVERLESS_POSTGRES_17;
    }
}
