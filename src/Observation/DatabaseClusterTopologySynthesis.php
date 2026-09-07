<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum DatabaseClusterTopologySynthesis: string
{
    case CORROBORATED_COMPLETE = 'corroborated_complete';
    case SCOPED_COMPLETE = 'scoped_complete';
    case PARTIAL_POSITIVE = 'partial_positive';
    case INCOMPLETE = 'incomplete';
    case CONFLICTING = 'conflicting';
}
