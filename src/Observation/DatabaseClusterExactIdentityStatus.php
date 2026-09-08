<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum DatabaseClusterExactIdentityStatus: string
{
    case VERIFIED = 'verified';
    case MISSING = 'missing';
    case FAILED = 'failed';
    case CONFLICTING = 'conflicting';
}
