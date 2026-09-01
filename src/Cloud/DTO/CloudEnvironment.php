<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudEnvironment
{
    public EnvironmentDependencies $dependencies;

    public function __construct(
        public string $id,
        public string $applicationId,
        public string $name,
        public ?string $branch,
        public ?string $databaseId = null,
        ?EnvironmentDependencies $dependencies = null,
    ) {
        $this->dependencies = $dependencies ?? EnvironmentDependencies::incomplete($databaseId);
    }
}
