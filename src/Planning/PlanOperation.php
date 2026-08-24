<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

enum PlanOperation: string
{
    case CREATE = 'create';
    case NO_CHANGE = 'no_change';
    case UNSUPPORTED = 'unsupported';
}
