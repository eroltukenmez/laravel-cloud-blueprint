<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinition;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Observation\DatabaseParentEvidence;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\LogicalDatabaseObservationEvidence;
use LaravelCloudBlueprint\Observation\LogicalDatabaseObservationFactory;
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

final class LogicalDatabaseObservationFactoryTest extends TestCase
{
    private LogicalDatabaseObservationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new LogicalDatabaseObservationFactory();
    }

    public function testMissingDesiredDatabaseUnderManagedParentIsSupported(): void
    {
        self::assertObservation($this->create(null), ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE, ReconciliationStatus::SUPPORTED);
    }

    public function testMissingChildUnderUnmanagedParentIsNotAuthorized(): void
    {
        $evidence = new LogicalDatabaseObservationEvidence(self::parent(OwnershipStatus::UNMANAGED), [], EvidenceStatus::COMPLETE);
        $observation = $this->factory->create('primary', self::desired(), null, $evidence);
        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::NONE, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    public function testUnmanagedAndManagedExactDatabasesAreInSync(): void
    {
        self::assertObservation($this->create(null, self::remote(id: 'other')), ObservationKind::IN_SYNC, OwnershipStatus::UNMANAGED, ReconciliationStatus::NOT_APPLICABLE);
        self::assertObservation($this->create(self::managed(), self::remote()), ObservationKind::IN_SYNC, OwnershipStatus::MANAGED, ReconciliationStatus::NOT_APPLICABLE);
    }

    public function testManagedIdentityMissingAndReplacementAreDistinct(): void
    {
        self::assertObservation($this->create(self::managed()), ObservationKind::IDENTITY_MISSING, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        self::assertObservation($this->create(self::managed(), self::remote(id: 'replacement')), ObservationKind::IDENTITY_REPLACEMENT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testWrongParentWinsOverSameNameReplacement(): void
    {
        $observation = $this->create(
            self::managed(),
            self::remote(clusterId: 'other-cluster'),
            self::remote(id: 'replacement'),
        );
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testRemoteNameConflictIsNotAReplacement(): void
    {
        $observation = $this->create(self::managed(), self::remote(name: 'renamed'), self::remote(id: 'replacement'));
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testAmbiguousUnmanagedMatchesAreDeterministic(): void
    {
        $first = $this->create(null, self::remote(id: 'database-2'), self::remote(id: 'database-3'));
        $second = $this->create(null, self::remote(id: 'database-3'), self::remote(id: 'database-2'));
        self::assertEquals($first, $second);
        self::assertObservation($first, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
    }

    public function testUnresolvedParentAndIncompleteListRemainUnknown(): void
    {
        $unresolved = new LogicalDatabaseObservationEvidence(DatabaseParentEvidence::unresolved(), [], EvidenceStatus::INCOMPLETE);
        self::assertObservation(
            $this->factory->create('primary', self::desired(), self::managed(), $unresolved),
            ObservationKind::UNKNOWN,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::BLOCKED,
            EvidenceStatus::INCOMPLETE,
        );
    }

    public function testCompleteAttachmentIsLifecycleConditionAndIncompleteRelationshipsAreUnknown(): void
    {
        self::assertObservation(
            $this->create(self::managed(), self::remote(environmentIds: ['env-1'])),
            ObservationKind::LIFECYCLE_CONDITION,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::BLOCKED,
        );
        self::assertObservation(
            $this->create(self::managed(), self::remote(relationshipsComplete: false)),
            ObservationKind::UNKNOWN,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::BLOCKED,
            EvidenceStatus::INCOMPLETE,
        );
    }

    public function testDerivedStateIsRejectedFromOrdinaryPath(): void
    {
        $derived = new StateResource(
            self::address(),
            ResourceType::DATABASE,
            'database-1',
            self::parentAddress(),
            StateOwnershipClassification::DERIVED,
            StateProvenance::CLUSTER_CREATE_RESPONSE,
        );
        self::assertObservation($this->create($derived, self::remote()), ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
    }

    public function testReleasedDerivedResourceIsOnlyAnUnmanagedOrdinaryMatch(): void
    {
        $observation = $this->create(null, self::remote(id: 'formerly-derived'));
        self::assertObservation($observation, ObservationKind::IN_SYNC, OwnershipStatus::UNMANAGED, ReconciliationStatus::NOT_APPLICABLE);
        self::assertNotSame(OwnershipStatus::DERIVED, $observation->ownership);
    }

    private function create(?StateResource $state, CloudDatabase ...$databases): ResourceObservation
    {
        return $this->factory->create(
            'primary',
            self::desired(),
            $state,
            new LogicalDatabaseObservationEvidence(self::parent(), array_values($databases), EvidenceStatus::COMPLETE),
        );
    }

    private static function desired(): LogicalDatabaseDefinition
    {
        return new LogicalDatabaseDefinition('application');
    }

    private static function address(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::DATABASE, 'primary.application');
    }

    private static function parentAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
    }

    private static function parent(OwnershipStatus $ownership = OwnershipStatus::MANAGED): DatabaseParentEvidence
    {
        return DatabaseParentEvidence::resolved(self::parentAddress(), 'cluster-1', $ownership);
    }

    private static function managed(): StateResource
    {
        return new StateResource(self::address(), ResourceType::DATABASE, 'database-1', self::parentAddress());
    }

    /** @param list<string> $environmentIds */
    private static function remote(
        string $id = 'database-1',
        string $clusterId = 'cluster-1',
        string $name = 'application',
        ?string $relationshipClusterId = 'cluster-1',
        array $environmentIds = [],
        bool $relationshipsComplete = true,
    ): CloudDatabase {
        return new CloudDatabase($id, $clusterId, $name, $relationshipClusterId, $environmentIds, $relationshipsComplete);
    }

    private static function assertObservation(ResourceObservation $actual, ObservationKind $kind, OwnershipStatus $ownership, ReconciliationStatus $reconciliation, EvidenceStatus $evidence = EvidenceStatus::COMPLETE): void
    {
        self::assertSame('database.primary.application', (string) $actual->address);
        self::assertSame($kind, $actual->observation);
        self::assertSame($ownership, $actual->ownership);
        self::assertSame($reconciliation, $actual->reconciliation);
        self::assertSame($evidence, $actual->evidence);
    }
}
