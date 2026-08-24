<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;

interface LaravelCloudClient
{
    public function organization(): CloudOrganization;

    /** @return list<CloudApplication> */
    public function applications(): array;

    /** @return list<CloudEnvironment> */
    public function environments(string $applicationId): array;

    public function createApplication(CreateApplicationRequest $request): CloudApplication;

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment;
}
