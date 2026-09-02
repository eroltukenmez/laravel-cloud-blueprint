<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum DatabaseSnapshotType: string
{
    case MANUAL = 'manual';
    case SCHEDULED = 'scheduled';
}
