<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum DatabaseClusterLifecycleReadiness: string
{
    case ELIGIBLE = 'eligible';
    case INELIGIBLE = 'ineligible';
    case UNKNOWN = 'unknown';
}
