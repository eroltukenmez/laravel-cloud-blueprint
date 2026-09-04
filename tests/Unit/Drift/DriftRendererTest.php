<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Drift;

use LaravelCloudBlueprint\Drift\DriftReport;
use LaravelCloudBlueprint\Drift\Rendering\DriftHumanRenderer;
use LaravelCloudBlueprint\Drift\Rendering\DriftJsonRenderer;
use LaravelCloudBlueprint\Observation\ChangedFields;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReasonCode;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Observation\ResourceObservationCollection;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use PHPUnit\Framework\TestCase;

final class DriftRendererTest extends TestCase
{
    public function testJsonContractIsCompleteSafeOrderedAndDeterministic(): void
    {
        $report = new DriftReport(new ResourceObservationCollection(
            self::observation(ResourceType::VARIABLE, 'production.APP_KEY', ObservationKind::IN_SYNC, OwnershipStatus::NONE),
            self::observation(ResourceType::DATABASE, 'primary.__derived_default', ObservationKind::IN_SYNC, OwnershipStatus::DERIVED),
            self::observation(ResourceType::APPLICATION, 'api', ObservationKind::IN_SYNC, OwnershipStatus::UNMANAGED),
            new ResourceObservation(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ObservationKind::CONFIGURATION_DIFFERENCE,
                OwnershipStatus::MANAGED,
                ReconciliationStatus::SUPPORTED,
                EvidenceStatus::COMPLETE,
                new ChangedFields('branch'),
                ReasonCode::ENVIRONMENT_BRANCH_DIFFERENCE,
            ),
            self::observation(
                ResourceType::DATABASE_CLUSTER,
                'primary',
                ObservationKind::UNKNOWN,
                OwnershipStatus::MANAGED,
                EvidenceStatus::INCOMPLETE,
            ),
        ));

        $renderer = new DriftJsonRenderer();
        $first = $renderer->render($report);

        self::assertSame($first, $renderer->render($report));
        self::assertSame(['status', 'summary', 'entries'], array_keys($first));
        self::assertSame([
            'in_sync',
            'desired_resource_missing',
            'desired_resource_absent',
            'configuration_difference',
            'identity_missing',
            'identity_replacement',
            'identity_conflict',
            'lifecycle_condition',
            'unknown',
            'total',
        ], array_keys($first['summary']));
        self::assertSame(5, $first['summary']['total']);
        self::assertSame([
            'application.api',
            'environment.production',
            'database_cluster.primary',
            'database.primary.__derived_default',
            'variable.production.APP_KEY',
        ], array_column($first['entries'], 'resource'));
        self::assertSame('unmanaged', $first['entries'][0]['ownership']);
        self::assertSame(['branch'], $first['entries'][1]['changed_fields']);
        self::assertSame('environment_branch_difference', $first['entries'][1]['reason_code']);
        self::assertSame('unknown', $first['entries'][2]['observation']);
        self::assertSame('incomplete', $first['entries'][2]['evidence']);
        self::assertSame('derived', $first['entries'][3]['ownership']);
        self::assertArrayNotHasKey('changed_fields', $first['entries'][0]);
        self::assertArrayNotHasKey('reason_code', $first['entries'][0]);

        $encoded = json_encode($first, JSON_THROW_ON_ERROR);
        foreach (['remote_id', 'current_value', 'desired_value', 'operation', 'human_reason'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function testHumanOutputHidesManagedAndDerivedInSyncButShowsUnmanagedAndIncompleteEvidence(): void
    {
        $report = new DriftReport(new ResourceObservationCollection(
            self::observation(ResourceType::APPLICATION, 'managed', ObservationKind::IN_SYNC, OwnershipStatus::MANAGED),
            self::observation(ResourceType::APPLICATION, 'unmanaged', ObservationKind::IN_SYNC, OwnershipStatus::UNMANAGED),
            self::observation(ResourceType::DATABASE, 'primary.__derived_default', ObservationKind::IN_SYNC, OwnershipStatus::DERIVED),
            self::observation(
                ResourceType::DATABASE_CLUSTER,
                'primary',
                ObservationKind::UNKNOWN,
                OwnershipStatus::MANAGED,
                EvidenceStatus::INCOMPLETE,
            ),
        ));

        $output = (new DriftHumanRenderer())->render($report);

        self::assertStringNotContainsString('application.managed', $output);
        self::assertStringNotContainsString('database.primary.__derived_default', $output);
        self::assertStringContainsString('application.unmanaged', $output);
        self::assertStringContainsString('In sync (unmanaged)', $output);
        self::assertStringContainsString('Unknown / incomplete evidence', $output);
        self::assertStringContainsString('Evidence: incomplete', $output);
        self::assertStringContainsString('Summary: 4 scoped, 3 in sync, 1 unknown.', $output);
    }

    public function testHumanOutputReportsNoDifferencesWhenEveryVisibleConcernIsInSync(): void
    {
        $report = new DriftReport(new ResourceObservationCollection(
            self::observation(ResourceType::APPLICATION, 'api', ObservationKind::IN_SYNC, OwnershipStatus::MANAGED),
            self::observation(ResourceType::VARIABLE, 'production.APP_ENV', ObservationKind::IN_SYNC, OwnershipStatus::NONE),
        ));

        $output = (new DriftHumanRenderer())->render($report);

        self::assertStringContainsString('No differences observed.', $output);
        self::assertStringContainsString('Summary: 2 scoped, 2 in sync.', $output);
    }

    private static function observation(
        ResourceType $type,
        string $name,
        ObservationKind $kind,
        OwnershipStatus $ownership,
        EvidenceStatus $evidence = EvidenceStatus::COMPLETE,
    ): ResourceObservation {
        return new ResourceObservation(
            new ResourceAddress($type, $name),
            $kind,
            $ownership,
            $kind === ObservationKind::IN_SYNC
                ? ReconciliationStatus::NOT_APPLICABLE
                : ReconciliationStatus::BLOCKED,
            $evidence,
        );
    }
}
