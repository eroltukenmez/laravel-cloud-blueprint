<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseScopedList;

/** Supplies scoped logical Database rows without discarding partial read-only evidence. */
interface LaravelCloudScopedDatabaseListReader
{
    public function scopedDatabases(string $clusterId): CloudDatabaseScopedList;
}
