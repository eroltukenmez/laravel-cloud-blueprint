<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

enum BlueprintRecoveryStatus: string
{
    case AVAILABLE = 'available';
    case INVALID = 'invalid';
}
