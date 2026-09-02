<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum DatabaseDependencyType: string
{
    case ENVIRONMENT_ATTACHMENT = 'environment_attachment';
    case OWNED_DATABASE_CHILD = 'owned_database_child';
    case UNMANAGED_DATABASE_CHILD = 'unmanaged_database_child';
    case OWNERSHIP_CONFLICT = 'ownership_conflict';
}
