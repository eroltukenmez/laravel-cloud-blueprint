<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum DatabaseClusterScopedPaginationStatus: string
{
    case VALIDATED = 'validated';
    case UNVERIFIED = 'unverified';
    case INVALID = 'invalid';
}
