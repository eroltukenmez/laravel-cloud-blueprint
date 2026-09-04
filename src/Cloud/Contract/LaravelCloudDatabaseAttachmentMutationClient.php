<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentDatabaseAttachmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;

interface LaravelCloudDatabaseAttachmentMutationClient
{
    public function updateEnvironmentDatabaseAttachment(
        string $environmentId,
        UpdateEnvironmentDatabaseAttachmentRequest $request,
    ): UpdatedCloudEnvironment;
}
