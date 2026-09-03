<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum EnvironmentVariableEvidenceStatus: string
{
    case AVAILABLE = 'available';
    case COLLECTION_UNAVAILABLE = 'collection_unavailable';
    case PARENT_UNRESOLVED = 'parent_unresolved';
}
