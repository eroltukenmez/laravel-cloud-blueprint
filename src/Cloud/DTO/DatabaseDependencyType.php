<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum DatabaseDependencyType: string
{
    case ENVIRONMENT_ATTACHMENT = 'environment_attachment';
    case OWNED_DATABASE_CHILD = 'owned_database_child';
    case DERIVED_PARENT_DEPENDENCY = 'derived_parent_dependency';
    case UNMANAGED_DATABASE_CHILD = 'unmanaged_database_child';
    case OWNERSHIP_CONFLICT = 'ownership_conflict';
    case DATABASE_SNAPSHOT = 'database_snapshot';
    case RETAINED_DATABASE_RECOVERY = 'retained_database_recovery';
    case DATABASE_CLUSTER_LIFECYCLE = 'database_cluster_lifecycle';
}
