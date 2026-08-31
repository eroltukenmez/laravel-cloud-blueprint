<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseClusterRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseRequest;

interface LaravelCloudDatabaseMutationClient extends LaravelCloudDatabaseClient
{
    public function createDatabaseCluster(CreateDatabaseClusterRequest $request): CloudDatabaseCluster;

    public function createDatabase(string $clusterId, CreateDatabaseRequest $request): CloudDatabase;
}
