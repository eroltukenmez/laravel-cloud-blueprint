<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum OwnershipStatus: string
{
    case MANAGED = 'managed';
    case DERIVED = 'derived';
    case UNMANAGED = 'unmanaged';
    case CONFLICT = 'conflict';
    case NONE = 'none';
    case UNKNOWN = 'unknown';
}
