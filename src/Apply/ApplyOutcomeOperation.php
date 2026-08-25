<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

enum ApplyOutcomeOperation: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case UNCHANGED = 'unchanged';
    case FAILED = 'failed';
}
