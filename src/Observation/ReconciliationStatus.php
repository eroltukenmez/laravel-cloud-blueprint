<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum ReconciliationStatus: string
{
    case SUPPORTED = 'supported';
    case UNSUPPORTED = 'unsupported';
    case BLOCKED = 'blocked';
    case NOT_APPLICABLE = 'not_applicable';
}
