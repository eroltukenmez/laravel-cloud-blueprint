<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

enum ResourceType: string
{
    case APPLICATION = 'application';
    case ENVIRONMENT = 'environment';
}
