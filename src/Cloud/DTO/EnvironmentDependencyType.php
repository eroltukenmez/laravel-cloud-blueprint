<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum EnvironmentDependencyType: string
{
    case DATABASE_ATTACHMENT = 'database_attachment';
    case CACHE_ATTACHMENT = 'cache_attachment';
    case WEBSOCKET_ATTACHMENT = 'websocket_attachment';
    case CUSTOM_DOMAIN = 'custom_domain';
    case INSTANCE = 'instance';
    case DEPLOYMENT = 'deployment';
    case SECRET = 'secret';
    case FILESYSTEM = 'filesystem';
    case DEFAULT_ENVIRONMENT = 'default_environment';
}
