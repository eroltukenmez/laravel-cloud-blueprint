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
        public int $derivedParentDependencyCount = 0,
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
        if ($this->derivedParentDependencyCount > 0) {
            $categories[] = DatabaseDependencyType::DERIVED_PARENT_DEPENDENCY;
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
        return array_values(array_filter(
            $this->categories(),
            static fn (DatabaseDependencyType $type): bool => $type !== DatabaseDependencyType::DERIVED_PARENT_DEPENDENCY,
        ));
    }

    /** @return list<DatabaseDependencyType> */
    public function informationalCategories(): array
    {
        return array_values(array_filter(
            $this->categories(),
            static fn (DatabaseDependencyType $type): bool => $type === DatabaseDependencyType::DERIVED_PARENT_DEPENDENCY,
        ));
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

    public function structuralReadiness(): DatabaseClusterStructuralReadiness
    {
        if ($this->ownedChildCount > 0 || $this->unmanagedChildCount > 0 || $this->ownershipConflict) {
            return DatabaseClusterStructuralReadiness::BLOCKED;
        }

        return $this->complete
            ? DatabaseClusterStructuralReadiness::SATISFIED
            : DatabaseClusterStructuralReadiness::UNKNOWN;
    }
}
