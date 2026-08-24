<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

enum ApplyStatus: string
{
    case SUCCESS = 'success';
    case PARTIAL_FAILURE = 'partial_failure';
    case FAILED = 'failed';
}
