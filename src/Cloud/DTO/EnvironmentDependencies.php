<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class EnvironmentDependencies
{
    /**
     * @param list<string> $unknownRelationships
     * @param list<string> $missingRelationships
     */
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
        public array $missingRelationships = [],
        public ?bool $databaseRelationshipEvidenceComplete = null,
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

    public static function authoritativeDatabaseRelationship(?string $databaseId): self
    {
        return new self($databaseId, null, null, 0, 0, 0, 0, 0, false, null, false, [], [], true);
    }

    public function databaseRelationshipComplete(): bool
    {
        if ($this->databaseRelationshipEvidenceComplete !== null) {
            return $this->databaseRelationshipEvidenceComplete;
        }
        if ($this->complete) {
            return true;
        }

        return $this->missingRelationships !== []
            && !in_array('database', $this->missingRelationships, true)
            && !in_array('database', $this->unknownRelationships, true);
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

    /** @return list<EnvironmentDependencyType> */
    public function blockingCategories(): array
    {
        return array_values(array_filter(
            $this->categories(),
            static fn (EnvironmentDependencyType $category): bool => $category !== EnvironmentDependencyType::INSTANCE,
        ));
    }

    /** @return list<EnvironmentDependencyType> */
    public function informationalCategories(): array
    {
        return $this->instanceCount > 0 ? [EnvironmentDependencyType::INSTANCE] : [];
    }

    public function readiness(): EnvironmentDestructiveReadiness
    {
        if ($this->blockingCategories() !== []) {
            return EnvironmentDestructiveReadiness::BLOCKED;
        }

        return $this->hasUnknownDestructiveDependencies()
            ? EnvironmentDestructiveReadiness::UNKNOWN
            : EnvironmentDestructiveReadiness::SAFE;
    }
}
