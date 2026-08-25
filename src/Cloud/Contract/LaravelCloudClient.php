<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;

interface LaravelCloudClient
{
    public function organization(): CloudOrganization;

    /** @return list<CloudApplication> */
    public function applications(): array;

    /** @return list<CloudEnvironment> */
    public function environments(string $applicationId): array;

    public function environment(string $environmentId): CloudEnvironmentDetails;

    public function createApplication(CreateApplicationRequest $request): CloudApplication;

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment;

    public function updateEnvironment(
        string $environmentId,
        UpdateEnvironmentRequest $request,
    ): UpdatedCloudEnvironment;

    public function setEnvironmentVariables(
        string $environmentId,
        SetEnvironmentVariablesRequest $request,
    ): void;
}
