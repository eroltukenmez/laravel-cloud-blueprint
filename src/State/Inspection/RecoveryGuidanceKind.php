<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

enum RecoveryGuidanceKind: string
{
    case IMPORT = 'import';
    case UNMANAGE = 'unmanage';
    case ADOPTION_RUNBOOK = 'adoption_runbook';
}
