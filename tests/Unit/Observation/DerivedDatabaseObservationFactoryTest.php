<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Observation\DerivedDatabaseObservationEvidence;
use LaravelCloudBlueprint\Observation\DerivedDatabaseObservationFactory;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;

final class DerivedDatabaseObservationFactoryTest extends TestCase
{
    private DerivedDatabaseObservationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new DerivedDatabaseObservationFactory();
    }

    public function testValidDerivedResourceIsInSyncWithAuthorizedProvenance(): void
    {
        self::assertObservation($this->create(self::evidence([self::remote()], ['database-derived'])), ObservationKind::IN_SYNC, OwnershipStatus::DERIVED, ReconciliationStatus::NOT_APPLICABLE);
    }

    public function testCompleteAbsenceAndExplicitReplacementAreDistinct(): void
    {
        self::assertObservation($this->create(self::evidence([], [])), ObservationKind::IDENTITY_MISSING, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
        self::assertObservation(
            $this->create(self::evidence([], [], [self::remote(id: 'replacement') ])),
            ObservationKind::IDENTITY_REPLACEMENT,
            OwnershipStatus::DERIVED,
            ReconciliationStatus::UNSUPPORTED,
        );
    }

    public function testExactDerivedIdentityUnderWrongRemoteParentIsAConflict(): void
    {
        $observation = $this->create(self::evidence([self::remote(clusterId: 'other-cluster')], ['database-derived']));
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testIncompleteListEvidenceIsUnknownNotMissing(): void
    {
        $observation = $this->create(self::evidence([], [], list: EvidenceStatus::INCOMPLETE));
        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::DERIVED, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
        self::assertNotSame(ObservationKind::IDENTITY_MISSING, $observation->observation);
    }

    public function testIncompleteRelationshipEvidenceIsUnknownNotMissingOrConflict(): void
    {
        $observation = $this->create(self::evidence([self::remote()], [], relationships: EvidenceStatus::INCOMPLETE));
        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::DERIVED, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
        self::assertNotSame(ObservationKind::IDENTITY_MISSING, $observation->observation);
        self::assertNotSame(ObservationKind::IDENTITY_CONFLICT, $observation->observation);
    }

    public function testMissingRelationshipForExactIdentityIsACompleteConflict(): void
    {
        $observation = $this->create(self::evidence([self::remote()], []));
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testNoDerivedProvenanceIsEverInferred(): void
    {
        $ordinary = new StateResource(self::derivedAddress(), ResourceType::DATABASE, 'database-derived', self::parentAddress());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid State provenance authorization');
        $this->factory->create($ordinary, self::parent(), self::evidence([self::remote()], ['database-derived']));
    }

    public function testContradictoryOwnershipUsesConflictOwnership(): void
    {
        $observation = $this->create(self::evidence([], [], ownershipConflict: true));
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
    }

    public function testWrongStateParentUsesConflictOwnership(): void
    {
        $otherParent = new StateResource(
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'other'),
            ResourceType::DATABASE_CLUSTER,
            'cluster-2',
        );
        $observation = $this->factory->create(self::derived(), $otherParent, self::evidence([], []));
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
    }

    public function testBlueprintAddressCollisionDoesNotEraseDerivedClassification(): void
    {
        $observation = $this->create(self::evidence([], [], blueprintCollision: true));
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testNamesCannotCreateDerivedAuthorizationOrReplacement(): void
    {
        $sameName = self::remote(id: 'unmanaged', name: '__derived_default');
        $observation = $this->create(self::evidence([$sameName], []));

        self::assertObservation($observation, ObservationKind::IDENTITY_MISSING, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
        self::assertNotSame(ObservationKind::IDENTITY_REPLACEMENT, $observation->observation);
    }

    private function create(DerivedDatabaseObservationEvidence $evidence): ResourceObservation
    {
        return $this->factory->create(self::derived(), self::parent(), $evidence);
    }

    /**
     * @param list<CloudDatabase> $databases
     * @param list<string> $relationshipIds
     * @param list<CloudDatabase> $replacements
     */
    private static function evidence(
        array $databases,
        array $relationshipIds,
        array $replacements = [],
        EvidenceStatus $list = EvidenceStatus::COMPLETE,
        EvidenceStatus $relationships = EvidenceStatus::COMPLETE,
        bool $ownershipConflict = false,
        bool $blueprintCollision = false,
    ): DerivedDatabaseObservationEvidence {
        return new DerivedDatabaseObservationEvidence(
            $databases,
            $relationshipIds,
            $replacements,
            $list,
            $relationships,
            $ownershipConflict,
            $blueprintCollision,
        );
    }

    private static function derivedAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::DATABASE, 'primary.__derived_default');
    }

    private static function parentAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
    }

    private static function derived(): StateResource
    {
        return new StateResource(
            self::derivedAddress(),
            ResourceType::DATABASE,
            'database-derived',
            self::parentAddress(),
            StateOwnershipClassification::DERIVED,
            StateProvenance::CLUSTER_CREATE_RESPONSE,
        );
    }

    private static function parent(): StateResource
    {
        return new StateResource(self::parentAddress(), ResourceType::DATABASE_CLUSTER, 'cluster-1');
    }

    private static function remote(
        string $id = 'database-derived',
        string $clusterId = 'cluster-1',
        string $name = 'cloud-default-name',
    ): CloudDatabase {
        return new CloudDatabase($id, $clusterId, $name, $clusterId, [], true);
    }

    private static function assertObservation(ResourceObservation $actual, ObservationKind $kind, OwnershipStatus $ownership, ReconciliationStatus $reconciliation, EvidenceStatus $evidence = EvidenceStatus::COMPLETE): void
    {
        self::assertSame('database.primary.__derived_default', (string) $actual->address);
        self::assertSame($kind, $actual->observation);
        self::assertSame($ownership, $actual->ownership);
        self::assertSame($reconciliation, $actual->reconciliation);
        self::assertSame($evidence, $actual->evidence);
    }
}
