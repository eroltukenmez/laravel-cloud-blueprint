<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterType;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinitionCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseClusterLifecycleReadiness;
use LaravelCloudBlueprint\Observation\DatabaseClusterCandidate;
use LaravelCloudBlueprint\Observation\DatabaseClusterObservationEvidence;
use LaravelCloudBlueprint\Observation\DatabaseClusterObservationFactory;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;

final class DatabaseClusterObservationFactoryTest extends TestCase
{
    private DatabaseClusterObservationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new DatabaseClusterObservationFactory();
    }

    public function testMissingDesiredClusterIsSupported(): void
    {
        self::assertObservation($this->create(null), ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE, ReconciliationStatus::SUPPORTED);
    }

    public function testUnmanagedAndManagedMatchesAreInSync(): void
    {
        self::assertObservation($this->create(null, self::candidate()), ObservationKind::IN_SYNC, OwnershipStatus::UNMANAGED, ReconciliationStatus::NOT_APPLICABLE);
        self::assertObservation($this->create(self::managed(), self::candidate()), ObservationKind::IN_SYNC, OwnershipStatus::MANAGED, ReconciliationStatus::NOT_APPLICABLE);
    }

    public function testUnmanagedConfigurationDifferenceIsBlocked(): void
    {
        $observation = $this->create(null, self::candidate(self::remote(region: 'us-east-1')));
        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
        self::assertSame(['region'], $observation->changedFields->values());
    }

    public function testAmbiguousUnmanagedMatchesAreDeterministic(): void
    {
        $first = $this->create(null, self::candidate(), self::candidate(self::remote(id: 'cluster-2')));
        $second = $this->create(null, self::candidate(self::remote(id: 'cluster-2')), self::candidate());
        self::assertEquals($first, $second);
        self::assertObservation($first, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
    }

    public function testTypeAndRegionDifferencesAreSafeAndSorted(): void
    {
        $observation = $this->create(self::managed(), self::candidate(self::remote(type: 'other', region: 'us-east-1')));
        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        self::assertSame(['region', 'type'], $observation->changedFields->values());
    }

    public function testProviderConfigurationDifferencesExposeOnlyFieldNames(): void
    {
        $configuration = new CloudLaravelMySqlConfiguration('large', 64, 7, true, false);
        $observation = $this->create(self::managed(), self::candidate(self::remote(configuration: $configuration)));
        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        self::assertSame(['is_public', 'retention_days', 'size', 'storage', 'uses_scheduled_snapshots'], $observation->changedFields->values());
        self::assertStringNotContainsString('large', serialize($observation));
    }

    public function testNonComparableConfigurationIsUnknown(): void
    {
        $observation = $this->create(self::managed(), self::candidate(self::remote(configuration: new CloudUnknownDatabaseConfiguration())));
        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::MANAGED, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    public function testManagedIdentityMissingAndReplacementAreDistinct(): void
    {
        self::assertObservation($this->create(self::managed()), ObservationKind::IDENTITY_MISSING, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        self::assertObservation($this->create(self::managed(), self::candidate(self::remote(id: 'replacement'))), ObservationKind::IDENTITY_REPLACEMENT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testExactManagedIdentityWinsAndNameConflictIsNotReplacement(): void
    {
        $observation = $this->create(
            self::managed(),
            self::candidate(self::remote(name: 'renamed')),
            self::candidate(self::remote(id: 'replacement')),
        );
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testContradictoryOwnershipIsAConflict(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), new DatabaseClusterObservationEvidence([], EvidenceStatus::INCOMPLETE, true));
        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
    }

    public function testKnownAndUnknownLifecycleStatusesRemainObservationFacts(): void
    {
        self::assertObservation(
            $this->create(self::managed(), self::candidate(lifecycle: DatabaseClusterLifecycleReadiness::INELIGIBLE)),
            ObservationKind::LIFECYCLE_CONDITION,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::BLOCKED,
        );
        self::assertObservation(
            $this->create(self::managed(), self::candidate(lifecycle: DatabaseClusterLifecycleReadiness::UNKNOWN)),
            ObservationKind::UNKNOWN,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::BLOCKED,
            EvidenceStatus::INCOMPLETE,
        );
    }

    public function testIncompleteDiscoveryNeverProducesMissing(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), new DatabaseClusterObservationEvidence([], EvidenceStatus::INCOMPLETE));
        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::MANAGED, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    private function create(?StateResource $state, DatabaseClusterCandidate ...$candidates): ResourceObservation
    {
        return $this->factory->create(self::desired(), $state, new DatabaseClusterObservationEvidence(array_values($candidates), EvidenceStatus::COMPLETE));
    }

    private static function desired(): DatabaseClusterDefinition
    {
        return new DatabaseClusterDefinition(
            'primary',
            DatabaseClusterType::LARAVEL_MYSQL_8,
            'eu-west-1',
            new LaravelMySqlConfiguration('small', 32, 3, false, true),
            new LogicalDatabaseDefinitionCollection(),
        );
    }

    private static function managed(): StateResource
    {
        $address = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        return new StateResource($address, ResourceType::DATABASE_CLUSTER, 'cluster-1');
    }

    private static function candidate(
        ?CloudDatabaseCluster $cluster = null,
        DatabaseClusterLifecycleReadiness $lifecycle = DatabaseClusterLifecycleReadiness::ELIGIBLE,
    ): DatabaseClusterCandidate {
        return new DatabaseClusterCandidate($cluster ?? self::remote(), $lifecycle);
    }

    private static function remote(
        string $id = 'cluster-1',
        string $name = 'primary',
        string $type = 'laravel_mysql_8',
        string $region = 'eu-west-1',
        \LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseClusterConfiguration $configuration = new CloudLaravelMySqlConfiguration('small', 32, 3, false, true),
    ): CloudDatabaseCluster {
        return new CloudDatabaseCluster($id, $name, $type, 'available', $region, $configuration);
    }

    private static function assertObservation(ResourceObservation $actual, ObservationKind $kind, OwnershipStatus $ownership, ReconciliationStatus $reconciliation, EvidenceStatus $evidence = EvidenceStatus::COMPLETE): void
    {
        self::assertSame('database_cluster.primary', (string) $actual->address);
        self::assertSame($kind, $actual->observation);
        self::assertSame($ownership, $actual->ownership);
        self::assertSame($reconciliation, $actual->reconciliation);
        self::assertSame($evidence, $actual->evidence);
    }
}
