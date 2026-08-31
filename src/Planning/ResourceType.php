<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

enum ResourceType: string
{
    case APPLICATION = 'application';
    case ENVIRONMENT = 'environment';
    case DATABASE_CLUSTER = 'database_cluster';
    case DATABASE = 'database';
    case DATABASE_ATTACHMENT = 'database_attachment';
    case VARIABLE = 'variable';
}
