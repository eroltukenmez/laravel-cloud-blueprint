<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum ObservationKind: string
{
    case IN_SYNC = 'in_sync';
    case DESIRED_RESOURCE_MISSING = 'desired_resource_missing';
    case DESIRED_RESOURCE_ABSENT = 'desired_resource_absent';
    case CONFIGURATION_DIFFERENCE = 'configuration_difference';
    case IDENTITY_MISSING = 'identity_missing';
    case IDENTITY_REPLACEMENT = 'identity_replacement';
    case IDENTITY_CONFLICT = 'identity_conflict';
    case LIFECYCLE_CONDITION = 'lifecycle_condition';
    case UNKNOWN = 'unknown';
}
