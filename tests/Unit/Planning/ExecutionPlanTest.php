<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Planning;

use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use PHPUnit\Framework\TestCase;

final class ExecutionPlanTest extends TestCase
{
    public function testDeleteHasStableSerializedValueAndIsActionable(): void
    {
        $plan = new ExecutionPlan(new PlanAction(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'preview'),
            ResourceType::ENVIRONMENT,
            PlanOperation::DELETE,
            'Safe test reason.',
            null,
            EnvironmentDependencies::authoritativeAbsence(),
        ));

        self::assertSame('delete', PlanOperation::DELETE->value);
        self::assertTrue($plan->hasActionableChanges());
        self::assertSame(1, $plan->countByOperation(PlanOperation::DELETE));
    }

    public function testMixedPlansOrderDeletesChildFirstWithoutReorderingOtherOperations(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'old-api');
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'new-api', PlanOperation::CREATE),
            self::action(ResourceType::APPLICATION, 'old-api', PlanOperation::DELETE),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::UPDATE),
            self::action(ResourceType::DATABASE_CLUSTER, 'primary', PlanOperation::DELETE),
            self::action(ResourceType::ENVIRONMENT, 'preview', PlanOperation::DELETE, $application),
            self::action(ResourceType::DATABASE, 'primary.application', PlanOperation::DELETE, $cluster),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::NO_CHANGE),
        );

        self::assertSame([
            'database.primary.application',
            'environment.preview',
            'application.old-api',
            'database_cluster.primary',
            'application.new-api',
            'environment.production',
            'variable.production.APP_ENV',
        ], array_map(
            static fn (PlanAction $action): string => (string) $action->address,
            iterator_to_array($plan, false),
        ));
    }

    public function testBlockedAndUnsupportedDifferencesAreReportableButNotActionable(): void
    {
        $plan = new ExecutionPlan(
            self::action(ResourceType::ENVIRONMENT, 'preview', PlanOperation::DELETE),
            self::action(ResourceType::APPLICATION, 'old-api', PlanOperation::DELETE),
        );

        self::assertFalse($plan->hasActionableChanges());
        self::assertTrue($plan->hasReportableActions());
        self::assertSame(2, $plan->countByOperation(PlanOperation::DELETE));
    }

    private static function action(
        ResourceType $type,
        string $name,
        PlanOperation $operation,
        ?ResourceAddress $parent = null,
    ): PlanAction {
        return new PlanAction(
            new ResourceAddress($type, $name),
            $type,
            $operation,
            'Safe test reason.',
            null,
            ...($parent === null ? [] : [$parent]),
        );
    }
}
