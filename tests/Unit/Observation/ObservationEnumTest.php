<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ReasonCode;
use PHPUnit\Framework\TestCase;

final class ObservationEnumTest extends TestCase
{
    public function testObservationKindsHaveStableValues(): void
    {
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
        ], array_column(ObservationKind::cases(), 'value'));
    }

    public function testOwnershipStatusesHaveStableValues(): void
    {
        self::assertSame([
            'managed',
            'derived',
            'unmanaged',
            'conflict',
            'none',
            'unknown',
        ], array_column(OwnershipStatus::cases(), 'value'));
    }

    public function testReconciliationStatusesHaveStableValues(): void
    {
        self::assertSame([
            'supported',
            'unsupported',
            'blocked',
            'not_applicable',
        ], array_column(ReconciliationStatus::cases(), 'value'));
    }

    public function testEvidenceStatusesHaveStableValues(): void
    {
        self::assertSame(['complete', 'incomplete'], array_column(EvidenceStatus::cases(), 'value'));
    }

    public function testInitialReasonCodesAreDeliberatelyNarrow(): void
    {
        self::assertSame([
            'environment_branch_difference',
            'environment_variable_value_difference',
        ], array_column(ReasonCode::cases(), 'value'));
    }
}
