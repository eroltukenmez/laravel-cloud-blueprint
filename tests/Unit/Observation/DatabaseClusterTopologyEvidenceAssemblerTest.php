<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Observation\DatabaseClusterScopedListEvidenceStatus;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologyEvidenceAssembler;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologySynthesis;
use PHPUnit\Framework\TestCase;

final class DatabaseClusterTopologyEvidenceAssemblerTest extends TestCase
{
    public function testItAssemblesCorroboratedCloudDtos(): void
    {
        $evidence = (new DatabaseClusterTopologyEvidenceAssembler())->assemble(
            'cluster-1',
            $this->cluster(['database-1'], true),
            DatabaseClusterScopedListEvidenceStatus::COMPLETE,
            [new CloudDatabase('database-1', 'cluster-1', 'application', 'cluster-1')],
        );

        self::assertSame(DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE, $evidence->synthesis);
    }

    public function testItPreservesScopedOnlyParentProofWithoutTreatingEmptyResultsAsDestructiveQuality(): void
    {
        $assembler = new DatabaseClusterTopologyEvidenceAssembler();
        $positive = $assembler->assemble(
            'cluster-1',
            $this->cluster([], false, ['databases']),
            DatabaseClusterScopedListEvidenceStatus::COMPLETE,
            [new CloudDatabase('database-1', 'cluster-1', 'application', 'cluster-1')],
        );
        $empty = $assembler->assemble(
            'cluster-1',
            $this->cluster([], false, ['databases']),
            DatabaseClusterScopedListEvidenceStatus::COMPLETE,
        );

        self::assertSame(DatabaseClusterTopologySynthesis::SCOPED_COMPLETE, $positive->synthesis);
        self::assertSame(DatabaseClusterTopologySynthesis::SCOPED_COMPLETE, $empty->synthesis);
        self::assertFalse((new \LaravelCloudBlueprint\Observation\DatabaseClusterTopologyQualityPolicy())->isDestructiveQuality($empty));
    }

    public function testItFailsClosedForDuplicateRowsAndConflictingParents(): void
    {
        $assembler = new DatabaseClusterTopologyEvidenceAssembler();
        $duplicate = $assembler->assemble(
            'cluster-1', $this->cluster([], false, ['databases']), DatabaseClusterScopedListEvidenceStatus::COMPLETE,
            [new CloudDatabase('database-1', 'cluster-1', 'one', 'cluster-1'), new CloudDatabase('database-1', 'cluster-1', 'two', 'cluster-1')],
        );
        $conflicting = $assembler->assemble(
            'cluster-1', $this->cluster([], false, ['databases']), DatabaseClusterScopedListEvidenceStatus::COMPLETE,
            [new CloudDatabase('database-1', 'cluster-1', 'application', 'other-cluster')],
        );

        self::assertSame(DatabaseClusterTopologySynthesis::CONFLICTING, $duplicate->synthesis);
        self::assertSame(DatabaseClusterTopologySynthesis::CONFLICTING, $conflicting->synthesis);
    }

    /**
     * @param list<string> $databaseIds
     * @param list<string> $missing
     */
    private function cluster(array $databaseIds, bool $complete, array $missing = []): CloudDatabaseCluster
    {
        return new CloudDatabaseCluster(
            'cluster-1', 'primary', 'mysql', 'ready', 'region', new CloudUnknownDatabaseConfiguration(),
            $databaseIds, $complete, $missing,
        );
    }
}
