<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

enum DatabaseClusterChildClassification: string
{
    case BLUEPRINT_OWNED = 'blueprint_owned';
    case DERIVED_PARENT_DEPENDENCY = 'derived_parent_dependency';
    case UNMANAGED = 'unmanaged';
    case CONFLICT = 'conflict';
}
