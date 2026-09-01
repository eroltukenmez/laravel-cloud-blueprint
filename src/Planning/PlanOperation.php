<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

enum PlanOperation: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case NO_CHANGE = 'no_change';
    case UNSUPPORTED = 'unsupported';
}
