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

        return !$this->complete || $this->unknownRelationships !== [] || $this->missingRelationships !== []
            ? DatabaseDestructiveReadiness::UNKNOWN
            : DatabaseDestructiveReadiness::SAFE;
    }
}
