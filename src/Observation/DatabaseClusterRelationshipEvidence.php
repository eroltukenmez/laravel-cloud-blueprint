<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

final readonly class DatabaseClusterRelationshipEvidence
{
    /**
     * @param list<string> $childIds
     */
    public function __construct(
        public DatabaseClusterRelationshipEvidenceStatus $status,
        private array $childIds = [],
    ) {
        if ($this->childIds !== [] && in_array($this->status, [
            DatabaseClusterRelationshipEvidenceStatus::ABSENT,
            DatabaseClusterRelationshipEvidenceStatus::FAILED,
        ], true)) {
            throw new \InvalidArgumentException('Absent or failed Cluster relationship evidence cannot contain child IDs.');
        }
    }

    /** @return list<string> */
    public function childIds(): array
    {
        return $this->childIds;
    }
}
