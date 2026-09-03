<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Observation\ApplicationObservationEvidence;
use LaravelCloudBlueprint\Observation\ApplicationObservationFactory;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;

final class ApplicationObservationFactoryTest extends TestCase
{
    private ApplicationObservationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ApplicationObservationFactory();
    }

    public function testDesiredApplicationIsMissingFromCompleteDiscovery(): void
    {
        $observation = $this->factory->create(self::desired(), null, self::evidence());

        self::assertObservation($observation, ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE, ReconciliationStatus::SUPPORTED);
    }

    public function testMatchingUnmanagedApplicationIsInSyncWithoutImplyingOwnership(): void
    {
        $observation = $this->factory->create(self::desired(), null, self::evidence(self::remote()));

        self::assertObservation($observation, ObservationKind::IN_SYNC, OwnershipStatus::UNMANAGED, ReconciliationStatus::NOT_APPLICABLE);
    }

    public function testUnmanagedConfigurationDifferenceIsBlocked(): void
    {
        $observation = $this->factory->create(
            self::desired(),
            null,
            self::evidence(self::remote(region: 'us-east-1', repository: 'other/repository')),
        );

        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
        self::assertSame(['region', 'repository'], $observation->changedFields->values());
    }

    public function testAmbiguousUnmanagedMatchIsAConflictRegardlessOfCandidateOrder(): void
    {
        $first = $this->factory->create(self::desired(), null, self::evidence(self::remote('app-2'), self::remote()));
        $second = $this->factory->create(self::desired(), null, self::evidence(self::remote(), self::remote('app-2')));

        self::assertEquals($first, $second);
        self::assertObservation($first, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
    }

    public function testManagedApplicationIsResolvedByExactIdentityAndIsInSync(): void
    {
        $observation = $this->factory->create(
            self::desired(),
            self::managed(),
            self::evidence(self::remote('same-name-but-wrong-id'), self::remote()),
        );

        self::assertObservation($observation, ObservationKind::IN_SYNC, OwnershipStatus::MANAGED, ReconciliationStatus::NOT_APPLICABLE);
    }

    public function testManagedRegionDifferenceIsUnsupported(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote(region: 'us-east-1')));

        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        self::assertSame(['region'], $observation->changedFields->values());
    }

    public function testManagedRepositoryDifferenceIsUnsupported(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote(repository: 'other/repository')));

        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        self::assertSame(['repository'], $observation->changedFields->values());
    }

    public function testUnavailableRepositoryMakesComparisonUnknown(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote(repository: null)));

        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::MANAGED, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    public function testManagedIdentityIsMissingOnlyAfterCompleteDiscovery(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence());

        self::assertObservation($observation, ObservationKind::IDENTITY_MISSING, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testSameNameReplacementDoesNotAcquireManagedIdentity(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote('replacement-id')));

        self::assertObservation($observation, ObservationKind::IDENTITY_REPLACEMENT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        self::assertNotSame(ObservationKind::IN_SYNC, $observation->observation);
    }

    public function testExactIdentityWithDifferentNameIsAManagedIdentityConflict(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote(name: 'renamed')));

        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testProvenStateOwnershipConflictIsTypedIndependently(): void
    {
        $observation = $this->factory->create(
            self::desired(),
            self::managed(),
            new ApplicationObservationEvidence([], EvidenceStatus::INCOMPLETE, true),
        );

        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
    }

    public function testIncompleteDiscoveryCannotClaimAbsence(): void
    {
        $observation = $this->factory->create(
            self::desired(),
            self::managed(),
            new ApplicationObservationEvidence([], EvidenceStatus::INCOMPLETE),
        );

        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::MANAGED, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    private static function desired(): ApplicationDefinition
    {
        return new ApplicationDefinition('my-api', 'eu-west-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/api'));
    }

    private static function managed(): StateResource
    {
        $address = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        return new StateResource($address, ResourceType::APPLICATION, 'app-1');
    }

    private static function remote(
        string $id = 'app-1',
        string $name = 'my-api',
        string $region = 'eu-west-1',
        ?string $repository = 'acme/api',
    ): CloudApplication {
        return new CloudApplication($id, $name, null, $region, $repository);
    }

    private static function evidence(CloudApplication ...$applications): ApplicationObservationEvidence
    {
        return new ApplicationObservationEvidence(array_values($applications), EvidenceStatus::COMPLETE);
    }

    private static function assertObservation(
        ResourceObservation $observation,
        ObservationKind $kind,
        OwnershipStatus $ownership,
        ReconciliationStatus $reconciliation,
        EvidenceStatus $evidence = EvidenceStatus::COMPLETE,
    ): void {
        self::assertSame('application.my-api', (string) $observation->address);
        self::assertSame(ResourceType::APPLICATION, $observation->resourceType);
        self::assertSame($kind, $observation->observation);
        self::assertSame($ownership, $observation->ownership);
        self::assertSame($reconciliation, $observation->reconciliation);
        self::assertSame($evidence, $observation->evidence);
    }
}
