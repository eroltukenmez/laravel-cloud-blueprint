<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console;

enum ExitCode: int
{
    case SUCCESS = 0;
    case GENERAL_ERROR = 1;
    case BLUEPRINT_ERROR = 2;
    case DRIFT_CHECK_FAILED = 3;
}
