<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum DatabaseClusterParentProof: string
{
    case EXACT = 'exact';
    case MISSING = 'missing';
    case CONFLICTING = 'conflicting';
}
