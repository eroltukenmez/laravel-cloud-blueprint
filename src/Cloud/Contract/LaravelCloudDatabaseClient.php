<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;

interface LaravelCloudDatabaseClient extends LaravelCloudClient
{
    /** @return list<CloudDatabaseCluster> */
    public function databaseClusters(): array;

    public function databaseCluster(string $clusterId): CloudDatabaseCluster;

    /** @return list<CloudDatabase> */
    public function databases(string $clusterId): array;

    public function database(string $clusterId, string $databaseId): CloudDatabase;
}
