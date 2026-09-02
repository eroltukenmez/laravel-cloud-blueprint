<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseSnapshot;

interface LaravelCloudDatabaseLifecycleClient extends LaravelCloudDatabaseClient
{
    /** @return list<CloudDatabaseSnapshot> */
    public function databaseSnapshots(string $clusterId): array;
}
