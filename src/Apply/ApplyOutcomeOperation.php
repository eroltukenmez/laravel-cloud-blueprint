<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

enum ApplyOutcomeOperation: string
{
    case CREATED = 'created';
    case UNCHANGED = 'unchanged';
    case FAILED = 'failed';
}
