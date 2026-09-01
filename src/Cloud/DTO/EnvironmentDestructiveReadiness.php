<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum EnvironmentDestructiveReadiness: string
{
    case SAFE = 'safe';
    case BLOCKED = 'blocked';
    case UNKNOWN = 'unknown';
}
