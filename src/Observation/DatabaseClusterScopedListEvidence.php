<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

final readonly class DatabaseClusterScopedListEvidence
{
    /** @var list<DatabaseClusterScopedChildEvidence> */
    private array $children;

    /**
     * @param list<DatabaseClusterScopedChildEvidence> $children
     */
    public function __construct(
        public DatabaseClusterScopedListEvidenceStatus $status,
        array $children = [],
    ) {
        if ($children !== [] && $this->status === DatabaseClusterScopedListEvidenceStatus::FAILED) {
            throw new \InvalidArgumentException('Failed scoped Database list evidence cannot contain child rows.');
        }
        $this->children = $children;
    }

    /** @return list<DatabaseClusterScopedChildEvidence> */
    public function children(): array
    {
        return $this->children;
    }

    /** @return list<string> */
    public function childIds(): array
    {
        return array_map(
            static fn (DatabaseClusterScopedChildEvidence $child): string => $child->id,
            $this->children,
        );
    }
}
