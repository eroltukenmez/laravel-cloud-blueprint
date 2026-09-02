<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class DatabaseDependencies
{
    /**
     * @param list<string> $unknownRelationships
     * @param list<string> $missingRelationships
     */
    public function __construct(
        public int $environmentAttachmentCount,
        public int $ownedChildCount,
        public int $unmanagedChildCount,
        public bool $ownershipConflict,
        public bool $complete,
        public array $unknownRelationships = [],
        public array $missingRelationships = [],
        public int $snapshotCount = 0,
        public bool $retainedRecovery = false,
        public int $manualSnapshotCount = 0,
        public int $scheduledSnapshotCount = 0,
        public bool $snapshotDiscoveryComplete = true,
        public bool $recoveryEvidenceComplete = true,
        public DatabaseClusterLifecycleReadiness $lifecycleReadiness = DatabaseClusterLifecycleReadiness::ELIGIBLE,
    ) {
    }

    /** @return list<DatabaseDependencyType> */
    public function categories(): array
    {
        $categories = [];
        if ($this->environmentAttachmentCount > 0) {
            $categories[] = DatabaseDependencyType::ENVIRONMENT_ATTACHMENT;
        }
        if ($this->ownedChildCount > 0) {
            $categories[] = DatabaseDependencyType::OWNED_DATABASE_CHILD;
        }
        if ($this->unmanagedChildCount > 0) {
            $categories[] = DatabaseDependencyType::UNMANAGED_DATABASE_CHILD;
        }
        if ($this->ownershipConflict) {
            $categories[] = DatabaseDependencyType::OWNERSHIP_CONFLICT;
        }
        if ($this->snapshotCount > 0) {
            $categories[] = DatabaseDependencyType::DATABASE_SNAPSHOT;
        }
        if ($this->retainedRecovery) {
            $categories[] = DatabaseDependencyType::RETAINED_DATABASE_RECOVERY;
        }
        if ($this->lifecycleReadiness === DatabaseClusterLifecycleReadiness::INELIGIBLE) {
            $categories[] = DatabaseDependencyType::DATABASE_CLUSTER_LIFECYCLE;
        }

        return $categories;
    }

    /** @return list<DatabaseDependencyType> */
    public function blockingCategories(): array
    {
        return $this->categories();
    }

    /** @return list<DatabaseDependencyType> */
    public function informationalCategories(): array
    {
        return [];
    }

    public function readiness(): DatabaseDestructiveReadiness
    {
        if ($this->blockingCategories() !== []) {
            return DatabaseDestructiveReadiness::BLOCKED;
        }

        return !$this->complete
            || !$this->snapshotDiscoveryComplete
            || !$this->recoveryEvidenceComplete
            || $this->lifecycleReadiness === DatabaseClusterLifecycleReadiness::UNKNOWN
            || $this->unknownRelationships !== []
            || $this->missingRelationships !== []
            ? DatabaseDestructiveReadiness::UNKNOWN
            : DatabaseDestructiveReadiness::SAFE;
    }
}
