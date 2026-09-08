<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use LaravelCloudBlueprint\Observation\DatabaseClusterExactIdentityStatus;
use LaravelCloudBlueprint\Observation\DatabaseClusterParentProof;
use LaravelCloudBlueprint\Observation\DatabaseClusterRelationshipEvidence;
use LaravelCloudBlueprint\Observation\DatabaseClusterRelationshipEvidenceStatus;
use LaravelCloudBlueprint\Observation\DatabaseClusterScopedChildEvidence;
use LaravelCloudBlueprint\Observation\DatabaseClusterScopedListEvidence;
use LaravelCloudBlueprint\Observation\DatabaseClusterScopedListEvidenceStatus;
use LaravelCloudBlueprint\Observation\DatabaseClusterScopedPaginationStatus;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologyEvidence;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologyEvidenceSource;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologyEvidenceSynthesizer;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologyQualityPolicy;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologySynthesis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseClusterTopologyEvidenceSynthesizerTest extends TestCase
{
    private const string CLUSTER_ID = 'cluster-1';

    /**
     * @return iterable<string, array{
     *     DatabaseClusterExactIdentityStatus,
     *     DatabaseClusterRelationshipEvidence,
     *     DatabaseClusterScopedListEvidence,
     *     DatabaseClusterTopologySynthesis
     * }>
     */
    public static function topologyCases(): iterable
    {
        yield 'relationship and scoped list agree' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE, ['database-1']),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE, self::child('database-1')),
            DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE,
        ];
        yield 'relationship and scoped list are both authoritatively empty' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE),
            DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE,
        ];
        yield 'complete sources disagree' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE, ['database-1']),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE, self::child('database-2')),
            DatabaseClusterTopologySynthesis::CONFLICTING,
        ];
        yield 'complete relationship cannot compensate for failed scoped read' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE, ['database-1']),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::FAILED),
            DatabaseClusterTopologySynthesis::INCOMPLETE,
        ];
        yield 'scoped rows with exact returned parents are scoped complete' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE, self::child('database-1')),
            DatabaseClusterTopologySynthesis::SCOPED_COMPLETE,
        ];
        yield 'scoped row without returned parent proof is only partial positive' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE, self::child('database-1', parent: null)),
            DatabaseClusterTopologySynthesis::PARTIAL_POSITIVE,
        ];
        yield 'scoped empty is scoped complete but not corroborated' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE),
            DatabaseClusterTopologySynthesis::SCOPED_COMPLETE,
        ];
        yield 'malformed relationship and failed scoped list are incomplete' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::MALFORMED),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::FAILED),
            DatabaseClusterTopologySynthesis::INCOMPLETE,
        ];
        yield 'malformed scoped list is incomplete' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE, ['database-1']),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::MALFORMED, self::child('database-1')),
            DatabaseClusterTopologySynthesis::INCOMPLETE,
        ];
        yield 'duplicate scoped child identity conflicts' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(
                DatabaseClusterScopedListEvidenceStatus::COMPLETE,
                self::child('database-1'),
                self::child('database-1'),
            ),
            DatabaseClusterTopologySynthesis::CONFLICTING,
        ];
        yield 'duplicate relationship child identity conflicts' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(
                DatabaseClusterRelationshipEvidenceStatus::COMPLETE,
                ['database-1', 'database-1'],
            ),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE, self::child('database-1')),
            DatabaseClusterTopologySynthesis::CONFLICTING,
        ];
        yield 'returned child parent conflicts with request target' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(
                DatabaseClusterScopedListEvidenceStatus::COMPLETE,
                self::child('database-1', parent: 'cluster-2'),
            ),
            DatabaseClusterTopologySynthesis::CONFLICTING,
        ];
        yield 'missing row parent is accepted when complete sources corroborate identity' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE, ['database-1']),
            self::scoped(
                DatabaseClusterScopedListEvidenceStatus::COMPLETE,
                self::child('database-1', parent: null),
            ),
            DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE,
        ];
        yield 'same names with distinct IDs remain distinct identities' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(
                DatabaseClusterRelationshipEvidenceStatus::COMPLETE,
                ['database-1', 'database-2'],
            ),
            self::scoped(
                DatabaseClusterScopedListEvidenceStatus::COMPLETE,
                self::child('database-1', name: 'shared-name'),
                self::child('database-2', name: 'shared-name'),
            ),
            DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE,
        ];
        yield 'exact identity failure is incomplete' => [
            DatabaseClusterExactIdentityStatus::FAILED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::FAILED),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::FAILED),
            DatabaseClusterTopologySynthesis::INCOMPLETE,
        ];
        yield 'missing exact identity is incomplete' => [
            DatabaseClusterExactIdentityStatus::MISSING,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE),
            DatabaseClusterTopologySynthesis::INCOMPLETE,
        ];
        yield 'exact identity conflict is conflicting' => [
            DatabaseClusterExactIdentityStatus::CONFLICTING,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE),
            DatabaseClusterTopologySynthesis::CONFLICTING,
        ];
        yield 'partial scoped positive evidence remains explicitly positive' => [
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::PARTIAL, self::child('database-1')),
            DatabaseClusterTopologySynthesis::PARTIAL_POSITIVE,
        ];
    }

    #[DataProvider('topologyCases')]
    public function testItSynthesizesTopologyEvidence(
        DatabaseClusterExactIdentityStatus $identity,
        DatabaseClusterRelationshipEvidence $relationship,
        DatabaseClusterScopedListEvidence $scopedList,
        DatabaseClusterTopologySynthesis $expected,
    ): void {
        self::assertSame($expected, $this->synthesize($identity, $relationship, $scopedList)->synthesis);
    }

    public function testRequestScopeAndReturnedParentProofRemainDistinct(): void
    {
        $exact = self::child('database-1');
        $missing = self::child('database-2', parent: null);
        $conflicting = self::child('database-3', parent: 'cluster-2');
        $wrongScope = self::child('database-4', scope: 'cluster-2');

        self::assertSame(DatabaseClusterParentProof::EXACT, $exact->parentProof(self::CLUSTER_ID));
        self::assertSame(DatabaseClusterParentProof::MISSING, $missing->parentProof(self::CLUSTER_ID));
        self::assertSame(DatabaseClusterParentProof::CONFLICTING, $conflicting->parentProof(self::CLUSTER_ID));
        self::assertSame(DatabaseClusterParentProof::CONFLICTING, $wrongScope->parentProof(self::CLUSTER_ID));
        self::assertSame(self::CLUSTER_ID, $missing->requestedClusterId);
        self::assertNull($missing->relationshipClusterId);
    }

    public function testContradictoryRowsForTheSameIdentityConflict(): void
    {
        $evidence = $this->synthesize(
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(
                DatabaseClusterScopedListEvidenceStatus::COMPLETE,
                self::child('database-1'),
                self::child('database-1', parent: null),
            ),
        );

        self::assertSame(DatabaseClusterTopologySynthesis::CONFLICTING, $evidence->synthesis);
    }

    public function testEvidencePreservesExactIdentitySetsAndSources(): void
    {
        $evidence = $this->synthesize(
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE, ['database-1', 'database-2']),
            self::scoped(
                DatabaseClusterScopedListEvidenceStatus::PARTIAL,
                self::child('database-2'),
                self::child('database-3'),
            ),
        );

        self::assertSame(['database-1', 'database-2', 'database-3'], $evidence->childIds());
        self::assertSame(
            DatabaseClusterTopologyEvidenceSource::EXACT_CLUSTER_RELATIONSHIP,
            $evidence->sourceFor('database-1'),
        );
        self::assertSame(DatabaseClusterTopologyEvidenceSource::BOTH, $evidence->sourceFor('database-2'));
        self::assertSame(
            DatabaseClusterTopologyEvidenceSource::SCOPED_DATABASE_LIST,
            $evidence->sourceFor('database-3'),
        );
        self::assertNull($evidence->sourceFor('database-4'));
    }

    public function testOnlyCorroboratedEvidenceIsDestructiveQuality(): void
    {
        $policy = new DatabaseClusterTopologyQualityPolicy();
        $corroborated = $this->synthesize(
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE),
        );
        $scopedOnly = $this->synthesize(
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::ABSENT),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE),
        );

        self::assertSame(DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE, $corroborated->synthesis);
        self::assertTrue($policy->isDestructiveQuality($corroborated));
        self::assertSame(DatabaseClusterTopologySynthesis::SCOPED_COMPLETE, $scopedOnly->synthesis);
        self::assertFalse(
            $policy->isDestructiveQuality($scopedOnly),
            'A complete empty scoped list must not authorize deletion.',
        );
    }

    public function testUnverifiedOrInvalidScopedPaginationCannotBecomeDestructiveQuality(): void
    {
        $policy = new DatabaseClusterTopologyQualityPolicy();
        foreach ([
            DatabaseClusterScopedPaginationStatus::UNVERIFIED,
            DatabaseClusterScopedPaginationStatus::INVALID,
        ] as $pagination) {
            $evidence = $this->synthesize(
                DatabaseClusterExactIdentityStatus::VERIFIED,
                self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE, ['database-1']),
                new DatabaseClusterScopedListEvidence(
                    DatabaseClusterScopedListEvidenceStatus::COMPLETE,
                    [self::child('database-1')],
                    $pagination,
                ),
            );

            self::assertSame(DatabaseClusterTopologySynthesis::INCOMPLETE, $evidence->synthesis);
            self::assertFalse($policy->isDestructiveQuality($evidence));
        }

        $empty = $this->synthesize(
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE),
            new DatabaseClusterScopedListEvidence(
                DatabaseClusterScopedListEvidenceStatus::COMPLETE,
                [],
                DatabaseClusterScopedPaginationStatus::INVALID,
            ),
        );
        self::assertSame(DatabaseClusterTopologySynthesis::INCOMPLETE, $empty->synthesis);
        self::assertFalse($policy->isDestructiveQuality($empty));
    }

    /** @return iterable<string, array{DatabaseClusterRelationshipEvidenceStatus}> */
    public static function relationshipStatusesWithoutChildren(): iterable
    {
        yield 'absent' => [DatabaseClusterRelationshipEvidenceStatus::ABSENT];
        yield 'failed' => [DatabaseClusterRelationshipEvidenceStatus::FAILED];
    }

    #[DataProvider('relationshipStatusesWithoutChildren')]
    public function testAbsentAndFailedRelationshipEvidenceCannotContainChildren(
        DatabaseClusterRelationshipEvidenceStatus $status,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        new DatabaseClusterRelationshipEvidence($status, ['database-1']);
    }

    public function testFailedScopedListEvidenceCannotContainRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::scoped(DatabaseClusterScopedListEvidenceStatus::FAILED, self::child('database-1'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function emptyScopedChildIdentities(): iterable
    {
        yield 'child ID' => ['', self::CLUSTER_ID];
        yield 'requested Cluster ID' => ['database-1', ''];
    }

    #[DataProvider('emptyScopedChildIdentities')]
    public function testScopedChildIdentitiesCannotBeEmpty(string $id, string $scope): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatabaseClusterScopedChildEvidence($id, 'database', $scope, self::CLUSTER_ID);
    }

    public function testTopologyIdentityCannotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DatabaseClusterTopologyEvidenceSynthesizer())->synthesize(
            '',
            DatabaseClusterExactIdentityStatus::VERIFIED,
            self::relationship(DatabaseClusterRelationshipEvidenceStatus::COMPLETE),
            self::scoped(DatabaseClusterScopedListEvidenceStatus::COMPLETE),
        );
    }

    private function synthesize(
        DatabaseClusterExactIdentityStatus $identity,
        DatabaseClusterRelationshipEvidence $relationship,
        DatabaseClusterScopedListEvidence $scopedList,
    ): DatabaseClusterTopologyEvidence {
        return (new DatabaseClusterTopologyEvidenceSynthesizer())->synthesize(
            self::CLUSTER_ID,
            $identity,
            $relationship,
            $scopedList,
        );
    }

    /** @param list<string> $ids */
    private static function relationship(
        DatabaseClusterRelationshipEvidenceStatus $status,
        array $ids = [],
    ): DatabaseClusterRelationshipEvidence {
        return new DatabaseClusterRelationshipEvidence($status, $ids);
    }

    private static function scoped(
        DatabaseClusterScopedListEvidenceStatus $status,
        DatabaseClusterScopedChildEvidence ...$children,
    ): DatabaseClusterScopedListEvidence {
        return new DatabaseClusterScopedListEvidence($status, array_values($children));
    }

    private static function child(
        string $id,
        string $name = 'database',
        ?string $parent = self::CLUSTER_ID,
        string $scope = self::CLUSTER_ID,
    ): DatabaseClusterScopedChildEvidence {
        return new DatabaseClusterScopedChildEvidence($id, $name, $scope, $parent);
    }
}
