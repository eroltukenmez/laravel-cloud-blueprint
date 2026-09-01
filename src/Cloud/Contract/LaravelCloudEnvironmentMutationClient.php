<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

interface LaravelCloudEnvironmentMutationClient extends LaravelCloudClient
{
    public function deleteEnvironment(string $environmentId): void;
}
