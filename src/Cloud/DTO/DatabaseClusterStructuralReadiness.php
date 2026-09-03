<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum DatabaseClusterStructuralReadiness: string
{
    case SATISFIED = 'satisfied';
    case BLOCKED = 'blocked';
    case UNKNOWN = 'unknown';
}
