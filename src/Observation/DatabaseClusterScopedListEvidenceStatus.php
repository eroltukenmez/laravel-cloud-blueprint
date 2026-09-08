<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum DatabaseClusterScopedListEvidenceStatus: string
{
    case COMPLETE = 'complete';
    case PARTIAL = 'partial';
    case MALFORMED = 'malformed';
    case FAILED = 'failed';
}
