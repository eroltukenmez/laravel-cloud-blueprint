<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

enum RecoveryDisposition: string
{
    case NONE = 'none';
    case EXPLICIT_IMPORT_AVAILABLE = 'explicit_import_available';
    case MANUAL_ADOPTION_RUNBOOK = 'manual_adoption_runbook';
    case MANUAL_DECISION_REQUIRED = 'manual_decision_required';
    case UNAVAILABLE = 'unavailable';
}
