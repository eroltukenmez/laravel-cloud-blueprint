<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State;

enum StateOwnershipClassification: string
{
    case MANAGED = 'managed';
    case DERIVED = 'derived';
}
