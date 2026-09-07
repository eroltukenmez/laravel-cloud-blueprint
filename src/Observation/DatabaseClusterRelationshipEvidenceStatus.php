<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum DatabaseClusterRelationshipEvidenceStatus: string
{
    case COMPLETE = 'complete';
    case ABSENT = 'absent';
    case MALFORMED = 'malformed';
    case FAILED = 'failed';
}
