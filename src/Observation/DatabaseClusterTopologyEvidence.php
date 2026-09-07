<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

final readonly class DatabaseClusterTopologyEvidence
{
    public function __construct(
        public string $clusterId,
        public DatabaseClusterExactIdentityStatus $exactClusterIdentity,
        public DatabaseClusterRelationshipEvidence $relationshipEvidence,
        public DatabaseClusterScopedListEvidence $scopedListEvidence,
        public DatabaseClusterTopologySynthesis $synthesis,
    ) {
    }

    /** @return list<string> */
    public function childIds(): array
    {
        $ids = array_values(array_unique([
            ...$this->relationshipEvidence->childIds(),
            ...$this->scopedListEvidence->childIds(),
        ]));
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function sourceFor(string $childId): ?DatabaseClusterTopologyEvidenceSource
    {
        $relationship = in_array($childId, $this->relationshipEvidence->childIds(), true);
        $scoped = in_array($childId, $this->scopedListEvidence->childIds(), true);

        return match (true) {
            $relationship && $scoped => DatabaseClusterTopologyEvidenceSource::BOTH,
            $relationship => DatabaseClusterTopologyEvidenceSource::EXACT_CLUSTER_RELATIONSHIP,
            $scoped => DatabaseClusterTopologyEvidenceSource::SCOPED_DATABASE_LIST,
            default => null,
        };
    }
}
