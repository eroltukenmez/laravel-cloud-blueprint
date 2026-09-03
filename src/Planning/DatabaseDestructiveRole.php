<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

enum DatabaseDestructiveRole: string
{
    case PARENT_LIFECYCLE_DEPENDENCY = 'parent_lifecycle_dependency';
}
