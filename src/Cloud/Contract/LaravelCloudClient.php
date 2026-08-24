<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;

interface LaravelCloudClient
{
    public function organization(): CloudOrganization;

    /** @return list<CloudApplication> */
    public function applications(): array;

    /** @return list<CloudEnvironment> */
    public function environments(string $applicationId): array;
}
