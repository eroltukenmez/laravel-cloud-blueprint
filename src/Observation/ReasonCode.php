<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum ReasonCode: string
{
    case ENVIRONMENT_BRANCH_DIFFERENCE = 'environment_branch_difference';
    case ENVIRONMENT_VARIABLE_VALUE_DIFFERENCE = 'environment_variable_value_difference';
}
