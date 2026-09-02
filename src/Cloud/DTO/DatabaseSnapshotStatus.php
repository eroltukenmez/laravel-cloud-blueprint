<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum DatabaseSnapshotStatus: string
{
    case PENDING = 'pending';
    case CREATING = 'creating';
    case AVAILABLE = 'available';
    case FAILED = 'failed';
    case DELETING = 'deleting';
}
