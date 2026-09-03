<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State;

enum StateProvenance: string
{
    case CLUSTER_CREATE_RESPONSE = 'cluster_create_response';
}
