<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum EvidenceStatus: string
{
    case COMPLETE = 'complete';
    case INCOMPLETE = 'incomplete';
}
