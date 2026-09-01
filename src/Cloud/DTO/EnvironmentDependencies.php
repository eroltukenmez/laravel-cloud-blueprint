<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class EnvironmentDependencies
{
    /** @param list<string> $unknownRelationships */
    public function __construct(
        public ?string $databaseId,
        public ?string $cacheId,
        public ?string $websocketApplicationId,
        public int $domainCount,
        public int $instanceCount,
        public int $deploymentCount,
        public int $secretCount,
        public int $filesystemCount,
        public bool $hasCurrentDeployment,
        public ?bool $isDefaultEnvironment,
        public bool $complete,
        public array $unknownRelationships = [],
    ) {
    }

    public static function incomplete(?string $databaseId = null): self
    {
        return new self($databaseId, null, null, 0, 0, 0, 0, 0, false, null, false);
    }

    public static function authoritativeAbsence(): self
    {
        return new self(null, null, null, 0, 0, 0, 0, 0, false, false, true);
    }

    public function hasDatabaseAttachment(): bool
    {
        return $this->databaseId !== null;
    }

    public function hasCacheAttachment(): bool
    {
        return $this->cacheId !== null;
    }

    public function hasWebSocketAttachment(): bool
    {
        return $this->websocketApplicationId !== null;
    }

    public function hasDomains(): bool
    {
        return $this->domainCount > 0;
    }

    public function hasSecrets(): bool
    {
        return $this->secretCount > 0;
    }

    public function hasUnknownDestructiveDependencies(): bool
    {
        return !$this->complete || $this->unknownRelationships !== [];
    }

    /** @return list<EnvironmentDependencyType> */
    public function categories(): array
    {
        $categories = [];
        if ($this->hasDatabaseAttachment()) {
            $categories[] = EnvironmentDependencyType::DATABASE_ATTACHMENT;
        }
        if ($this->hasCacheAttachment()) {
            $categories[] = EnvironmentDependencyType::CACHE_ATTACHMENT;
        }
        if ($this->hasWebSocketAttachment()) {
            $categories[] = EnvironmentDependencyType::WEBSOCKET_ATTACHMENT;
        }
        if ($this->domainCount > 0) {
            $categories[] = EnvironmentDependencyType::CUSTOM_DOMAIN;
        }
        if ($this->instanceCount > 0) {
            $categories[] = EnvironmentDependencyType::INSTANCE;
        }
        if ($this->deploymentCount > 0 || $this->hasCurrentDeployment) {
            $categories[] = EnvironmentDependencyType::DEPLOYMENT;
        }
        if ($this->secretCount > 0) {
            $categories[] = EnvironmentDependencyType::SECRET;
        }
        if ($this->filesystemCount > 0) {
            $categories[] = EnvironmentDependencyType::FILESYSTEM;
        }
        if ($this->isDefaultEnvironment === true) {
            $categories[] = EnvironmentDependencyType::DEFAULT_ENVIRONMENT;
        }

        return $categories;
    }

    public function readiness(): EnvironmentDestructiveReadiness
    {
        if ($this->categories() !== []) {
            return EnvironmentDestructiveReadiness::BLOCKED;
        }

        return $this->hasUnknownDestructiveDependencies()
            ? EnvironmentDestructiveReadiness::UNKNOWN
            : EnvironmentDestructiveReadiness::SAFE;
    }
}
