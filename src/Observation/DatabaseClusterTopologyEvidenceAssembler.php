<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;

/** Assembles ownership-neutral topology evidence from already-normalized Cloud DTOs. */
final readonly class DatabaseClusterTopologyEvidenceAssembler
{
    public function __construct(
        private DatabaseClusterTopologyEvidenceSynthesizer $synthesizer = new DatabaseClusterTopologyEvidenceSynthesizer(),
    ) {
    }

    /**
     * @param list<CloudDatabase> $databases
     */
    public function assemble(
        string $clusterId,
        ?CloudDatabaseCluster $cluster,
        DatabaseClusterScopedListEvidenceStatus $scopedListStatus,
        array $databases = [],
        bool $exactClusterReadFailed = false,
    ): DatabaseClusterTopologyEvidence {
        $identity = $exactClusterReadFailed
            ? DatabaseClusterExactIdentityStatus::FAILED
            : ($cluster === null
                ? DatabaseClusterExactIdentityStatus::MISSING
                : ($cluster->id === $clusterId
                    ? DatabaseClusterExactIdentityStatus::VERIFIED
                    : DatabaseClusterExactIdentityStatus::CONFLICTING));

        return $this->synthesizer->synthesize(
            $clusterId,
            $identity,
            $this->relationship($cluster, $exactClusterReadFailed),
            new DatabaseClusterScopedListEvidence(
                $scopedListStatus,
                array_map(
                    static fn (CloudDatabase $database): DatabaseClusterScopedChildEvidence =>
                        new DatabaseClusterScopedChildEvidence(
                            $database->id,
                            $database->name,
                            $clusterId,
                            $database->relationshipClusterId,
                        ),
                    $databases,
                ),
            ),
        );
    }

    private function relationship(
        ?CloudDatabaseCluster $cluster,
        bool $exactClusterReadFailed,
    ): DatabaseClusterRelationshipEvidence {
        if ($exactClusterReadFailed) {
            return new DatabaseClusterRelationshipEvidence(DatabaseClusterRelationshipEvidenceStatus::FAILED);
        }
        if ($cluster === null || in_array('databases', $cluster->missingRelationships, true)) {
            return new DatabaseClusterRelationshipEvidence(DatabaseClusterRelationshipEvidenceStatus::ABSENT);
        }
        if (!$cluster->childDiscoveryComplete || $cluster->unknownRelationships !== []) {
            return new DatabaseClusterRelationshipEvidence(DatabaseClusterRelationshipEvidenceStatus::MALFORMED);
        }

        return new DatabaseClusterRelationshipEvidence(
            DatabaseClusterRelationshipEvidenceStatus::COMPLETE,
            $cluster->databaseIds,
        );
    }
}
