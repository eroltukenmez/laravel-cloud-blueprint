<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

enum RecoveryDisposition: string
{
    case NONE = 'none';
    case MANUAL_DECISION_REQUIRED = 'manual_decision_required';
    case UNAVAILABLE = 'unavailable';
}
