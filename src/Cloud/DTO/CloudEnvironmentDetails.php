<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudEnvironmentDetails
{
    public EnvironmentDependencies $dependencies;

    public function __construct(
        public string $id,
        public string $name,
        public ?CloudEnvironmentVariableCollection $variables,
        public ?string $databaseId = null,
        ?EnvironmentDependencies $dependencies = null,
    ) {
        $this->dependencies = $dependencies ?? EnvironmentDependencies::incomplete($databaseId);
    }
}
