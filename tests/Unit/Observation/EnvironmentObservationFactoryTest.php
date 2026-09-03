<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Observation\EnvironmentObservationEvidence;
use LaravelCloudBlueprint\Observation\EnvironmentObservationFactory;
use LaravelCloudBlueprint\Observation\EnvironmentParentEvidence;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReasonCode;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;

final class EnvironmentObservationFactoryTest extends TestCase
{
    private EnvironmentObservationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EnvironmentObservationFactory();
    }

    public function testDesiredEnvironmentIsMissingUnderManagedParent(): void
    {
        $observation = $this->factory->create(self::desired(), null, self::evidence());

        self::assertObservation($observation, ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE, ReconciliationStatus::SUPPORTED);
    }

    public function testUnmanagedParentDoesNotAuthorizeAChildAbsenceConclusion(): void
    {
        $observation = $this->factory->create(
            self::desired(),
            null,
            new EnvironmentObservationEvidence(
                EnvironmentParentEvidence::resolved(self::parentAddress(), 'app-1', OwnershipStatus::UNMANAGED),
                [],
                EvidenceStatus::COMPLETE,
            ),
        );

        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::NONE, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    public function testManagedEnvironmentIsInSync(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote()));

        self::assertObservation($observation, ObservationKind::IN_SYNC, OwnershipStatus::MANAGED, ReconciliationStatus::NOT_APPLICABLE);
    }

    public function testManagedBranchDifferenceIsSupportedAndTyped(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote(branch: 'develop')));

        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, OwnershipStatus::MANAGED, ReconciliationStatus::SUPPORTED);
        self::assertSame(['branch'], $observation->changedFields->values());
        self::assertSame(ReasonCode::ENVIRONMENT_BRANCH_DIFFERENCE, $observation->reasonCode);
    }

    public function testUnmanagedEnvironmentCanBeObservedInSync(): void
    {
        $observation = $this->factory->create(self::desired(), null, self::evidence(self::remote('other-id')));

        self::assertObservation($observation, ObservationKind::IN_SYNC, OwnershipStatus::UNMANAGED, ReconciliationStatus::NOT_APPLICABLE);
    }

    public function testUnmanagedBranchDifferenceIsBlocked(): void
    {
        $observation = $this->factory->create(self::desired(), null, self::evidence(self::remote('other-id', branch: 'develop')));

        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
        self::assertSame(['branch'], $observation->changedFields->values());
    }

    public function testUnavailableBranchCannotBecomeAConfigurationDifference(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote(branch: null)));

        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::MANAGED, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    public function testManagedIdentityIsMissingOnlyFromCompleteScopedEvidence(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence());

        self::assertObservation($observation, ObservationKind::IDENTITY_MISSING, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testSameNameReplacementIsNotSelected(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote('replacement-id')));

        self::assertObservation($observation, ObservationKind::IDENTITY_REPLACEMENT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        self::assertNotSame(ObservationKind::IN_SYNC, $observation->observation);
    }

    public function testExactIdentityUnderWrongRemoteParentWinsOverSameNameReplacement(): void
    {
        $observation = $this->factory->create(
            self::desired(),
            self::managed(),
            self::evidence(self::remote(applicationId: 'wrong-app'), self::remote('replacement-id')),
        );

        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testStateParentAddressConflictIsContradictoryOwnership(): void
    {
        $address = new ResourceAddress(ResourceType::ENVIRONMENT, 'production');
        $managed = new StateResource(
            $address,
            ResourceType::ENVIRONMENT,
            'env-1',
            new ResourceAddress(ResourceType::APPLICATION, 'other-application'),
        );

        $observation = $this->factory->create(self::desired(), $managed, self::evidence(self::remote()));

        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
    }

    public function testExactIdentityWithDifferentNameIsAConflict(): void
    {
        $observation = $this->factory->create(self::desired(), self::managed(), self::evidence(self::remote(name: 'renamed')));

        self::assertObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
    }

    public function testIncompleteDiscoveryCannotClaimIdentityMissing(): void
    {
        $observation = $this->factory->create(
            self::desired(),
            self::managed(),
            new EnvironmentObservationEvidence(self::parent(), [], EvidenceStatus::INCOMPLETE),
        );

        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::MANAGED, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    public function testUnresolvedParentProducesUnknownEvidence(): void
    {
        $observation = $this->factory->create(
            self::desired(),
            null,
            new EnvironmentObservationEvidence(EnvironmentParentEvidence::unresolved(), [], EvidenceStatus::INCOMPLETE),
        );

        self::assertObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::UNKNOWN, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    public function testAmbiguousCandidatesAreDeterministicWhenReordered(): void
    {
        $first = $this->factory->create(self::desired(), null, self::evidence(self::remote('env-2'), self::remote('env-3')));
        $second = $this->factory->create(self::desired(), null, self::evidence(self::remote('env-3'), self::remote('env-2')));

        self::assertEquals($first, $second);
        self::assertObservation($first, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
    }

    private static function desired(): EnvironmentDefinition
    {
        return new EnvironmentDefinition('production', 'main', new VariableDefinitionCollection());
    }

    private static function managed(): StateResource
    {
        $address = new ResourceAddress(ResourceType::ENVIRONMENT, 'production');
        return new StateResource(
            $address,
            ResourceType::ENVIRONMENT,
            'env-1',
            new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
        );
    }

    private static function parent(): EnvironmentParentEvidence
    {
        return EnvironmentParentEvidence::resolved(self::parentAddress(), 'app-1', OwnershipStatus::MANAGED);
    }

    private static function parentAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::APPLICATION, 'my-api');
    }

    private static function remote(
        string $id = 'env-1',
        string $applicationId = 'app-1',
        string $name = 'production',
        ?string $branch = 'main',
    ): CloudEnvironment {
        return new CloudEnvironment($id, $applicationId, $name, $branch);
    }

    private static function evidence(
        CloudEnvironment ...$environments,
    ): EnvironmentObservationEvidence {
        return new EnvironmentObservationEvidence(self::parent(), array_values($environments), EvidenceStatus::COMPLETE);
    }

    private static function assertObservation(
        ResourceObservation $observation,
        ObservationKind $kind,
        OwnershipStatus $ownership,
        ReconciliationStatus $reconciliation,
        EvidenceStatus $evidence = EvidenceStatus::COMPLETE,
    ): void {
        self::assertSame('environment.production', (string) $observation->address);
        self::assertSame(ResourceType::ENVIRONMENT, $observation->resourceType);
        self::assertSame($kind, $observation->observation);
        self::assertSame($ownership, $observation->ownership);
        self::assertSame($reconciliation, $observation->reconciliation);
        self::assertSame($evidence, $observation->evidence);
    }
}
