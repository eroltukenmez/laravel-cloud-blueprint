<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Planning;

use InvalidArgumentException;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseClusterLifecycleReadiness;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencies;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\PlanReconciliationStatus;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanActionTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     ResourceType,
     *     PlanOperation,
     *     PlanReconciliationStatus,
     *     EnvironmentDependencies|DatabaseDependencies|null
     * }>
     */
    public static function reconciliationSemantics(): iterable
    {
        yield 'Application create' => [ResourceType::APPLICATION, PlanOperation::CREATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Application no change' => [ResourceType::APPLICATION, PlanOperation::NO_CHANGE, PlanReconciliationStatus::NOT_APPLICABLE, null];
        yield 'Application delete intent' => [ResourceType::APPLICATION, PlanOperation::DELETE, PlanReconciliationStatus::UNSUPPORTED, null];
        yield 'Application immutable change' => [ResourceType::APPLICATION, PlanOperation::UNSUPPORTED, PlanReconciliationStatus::UNSUPPORTED, null];

        yield 'Environment create' => [ResourceType::ENVIRONMENT, PlanOperation::CREATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Environment branch update' => [ResourceType::ENVIRONMENT, PlanOperation::UPDATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Environment no change' => [ResourceType::ENVIRONMENT, PlanOperation::NO_CHANGE, PlanReconciliationStatus::NOT_APPLICABLE, null];
        yield 'Environment delete ready' => [ResourceType::ENVIRONMENT, PlanOperation::DELETE, PlanReconciliationStatus::SUPPORTED, EnvironmentDependencies::authoritativeAbsence()];
        yield 'Environment delete blocked' => [ResourceType::ENVIRONMENT, PlanOperation::DELETE, PlanReconciliationStatus::BLOCKED, new EnvironmentDependencies('database-id', null, null, 0, 0, 0, 0, 0, false, false, true)];
        yield 'Environment delete evidence incomplete' => [ResourceType::ENVIRONMENT, PlanOperation::DELETE, PlanReconciliationStatus::BLOCKED, EnvironmentDependencies::incomplete()];

        yield 'Variable create' => [ResourceType::VARIABLE, PlanOperation::CREATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Variable update' => [ResourceType::VARIABLE, PlanOperation::UPDATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Variable no change' => [ResourceType::VARIABLE, PlanOperation::NO_CHANGE, PlanReconciliationStatus::NOT_APPLICABLE, null];
        yield 'Variable delete unsupported' => [ResourceType::VARIABLE, PlanOperation::DELETE, PlanReconciliationStatus::UNSUPPORTED, null];

        yield 'Database Cluster create' => [ResourceType::DATABASE_CLUSTER, PlanOperation::CREATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Database Cluster no change' => [ResourceType::DATABASE_CLUSTER, PlanOperation::NO_CHANGE, PlanReconciliationStatus::NOT_APPLICABLE, null];
        yield 'Database Cluster delete ready' => [ResourceType::DATABASE_CLUSTER, PlanOperation::DELETE, PlanReconciliationStatus::SUPPORTED, self::safeDatabaseDependencies()];
        yield 'Database Cluster topology incomplete' => [ResourceType::DATABASE_CLUSTER, PlanOperation::DELETE, PlanReconciliationStatus::BLOCKED, new DatabaseDependencies(0, 0, 0, false, false)];
        yield 'Database Cluster topology conflicting' => [ResourceType::DATABASE_CLUSTER, PlanOperation::DELETE, PlanReconciliationStatus::BLOCKED, new DatabaseDependencies(0, 0, 0, true, false)];
        yield 'Database Cluster snapshot blocker' => [ResourceType::DATABASE_CLUSTER, PlanOperation::DELETE, PlanReconciliationStatus::BLOCKED, new DatabaseDependencies(0, 0, 0, false, true, snapshotCount: 1)];
        yield 'Database Cluster retained recovery blocker' => [ResourceType::DATABASE_CLUSTER, PlanOperation::DELETE, PlanReconciliationStatus::BLOCKED, new DatabaseDependencies(0, 0, 0, false, true, retainedRecovery: true)];
        yield 'Database Cluster lifecycle blocker' => [ResourceType::DATABASE_CLUSTER, PlanOperation::DELETE, PlanReconciliationStatus::BLOCKED, new DatabaseDependencies(0, 0, 0, false, true, lifecycleReadiness: DatabaseClusterLifecycleReadiness::INELIGIBLE)];
        yield 'Database Cluster configuration update unsupported' => [ResourceType::DATABASE_CLUSTER, PlanOperation::UNSUPPORTED, PlanReconciliationStatus::UNSUPPORTED, null];

        yield 'Logical Database create' => [ResourceType::DATABASE, PlanOperation::CREATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Logical Database no change' => [ResourceType::DATABASE, PlanOperation::NO_CHANGE, PlanReconciliationStatus::NOT_APPLICABLE, null];
        yield 'Logical Database delete ready' => [ResourceType::DATABASE, PlanOperation::DELETE, PlanReconciliationStatus::SUPPORTED, self::safeDatabaseDependencies()];
        yield 'Logical Database delete blocked' => [ResourceType::DATABASE, PlanOperation::DELETE, PlanReconciliationStatus::BLOCKED, new DatabaseDependencies(1, 0, 0, false, true)];

        yield 'Database attachment attach' => [ResourceType::DATABASE_ATTACHMENT, PlanOperation::UPDATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Database attachment switch' => [ResourceType::DATABASE_ATTACHMENT, PlanOperation::UPDATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Database attachment detach' => [ResourceType::DATABASE_ATTACHMENT, PlanOperation::UPDATE, PlanReconciliationStatus::SUPPORTED, null];
        yield 'Database attachment no change' => [ResourceType::DATABASE_ATTACHMENT, PlanOperation::NO_CHANGE, PlanReconciliationStatus::NOT_APPLICABLE, null];
        yield 'Database attachment create unsupported' => [ResourceType::DATABASE_ATTACHMENT, PlanOperation::CREATE, PlanReconciliationStatus::UNSUPPORTED, null];
    }

    #[DataProvider('reconciliationSemantics')]
    public function testReconciliationIsTypedPerPlannedAction(
        ResourceType $resourceType,
        PlanOperation $operation,
        PlanReconciliationStatus $expected,
        EnvironmentDependencies|DatabaseDependencies|null $dependencies,
    ): void {
        $details = $dependencies === null ? [] : [$dependencies];

        $action = new PlanAction(
            new ResourceAddress($resourceType, 'test'),
            $resourceType,
            $operation,
            'Human wording is non-normative.',
            null,
            ...$details,
        );

        self::assertSame($expected, $action->reconciliation);
    }

    /** @return iterable<string, array{ResourceType, PlanOperation, PlanReconciliationStatus}> */
    public static function invalidCombinations(): iterable
    {
        yield 'no change blocked' => [ResourceType::APPLICATION, PlanOperation::NO_CHANGE, PlanReconciliationStatus::BLOCKED];
        yield 'unsupported supported' => [ResourceType::APPLICATION, PlanOperation::UNSUPPORTED, PlanReconciliationStatus::SUPPORTED];
        yield 'create not applicable' => [ResourceType::APPLICATION, PlanOperation::CREATE, PlanReconciliationStatus::NOT_APPLICABLE];
        yield 'update not applicable' => [ResourceType::ENVIRONMENT, PlanOperation::UPDATE, PlanReconciliationStatus::NOT_APPLICABLE];
        yield 'delete not applicable' => [ResourceType::APPLICATION, PlanOperation::DELETE, PlanReconciliationStatus::NOT_APPLICABLE];
    }

    #[DataProvider('invalidCombinations')]
    public function testIncompatibleOperationAndReconciliationAreRejected(
        ResourceType $resourceType,
        PlanOperation $operation,
        PlanReconciliationStatus $reconciliation,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Plan operation and reconciliation status are incompatible.');

        new PlanAction(
            new ResourceAddress($resourceType, 'test'),
            $resourceType,
            $operation,
            'Human wording is non-normative.',
            null,
            $reconciliation,
        );
    }

    public function testReasonWordingCannotChangeMachineSemanticsOrOwnership(): void
    {
        $first = new PlanAction(
            new ResourceAddress(ResourceType::APPLICATION, 'first'),
            ResourceType::APPLICATION,
            PlanOperation::NO_CHANGE,
            'Contains the old unmanaged marker.',
            null,
            OwnershipStatus::UNMANAGED,
        );
        $second = new PlanAction(
            new ResourceAddress(ResourceType::APPLICATION, 'second'),
            ResourceType::APPLICATION,
            PlanOperation::NO_CHANGE,
            'Completely different human explanation.',
            null,
            OwnershipStatus::UNMANAGED,
        );

        self::assertSame($first->reconciliation, $second->reconciliation);
        self::assertSame($first->ownership, $second->ownership);
        self::assertTrue((new ExecutionPlan($second))->hasReportableActions());
        self::assertFalse((new ExecutionPlan($second))->hasActionableChanges());
    }

    private static function safeDatabaseDependencies(): DatabaseDependencies
    {
        return new DatabaseDependencies(0, 0, 0, false, true);
    }
}
