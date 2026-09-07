<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Drift;

use LaravelCloudBlueprint\Drift\DriftCheckEvaluator;
use LaravelCloudBlueprint\Drift\DriftReport;
use LaravelCloudBlueprint\Observation\ChangedFields;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Observation\ResourceObservationCollection;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use PHPUnit\Framework\TestCase;

final class DriftCheckEvaluatorTest extends TestCase
{
    public function testAllInSyncCompleteObservationsPass(): void
    {
        $result = $this->evaluate(self::observation('app', ObservationKind::IN_SYNC));

        self::assertTrue($result->passed());
        self::assertSame(0, $result->failingCount());
    }

    public function testEmptyReportPasses(): void
    {
        $result = $this->evaluate();

        self::assertTrue($result->passed());
        self::assertSame(0, $result->failingCount());
    }

    public function testEveryNonInSyncKindFails(): void
    {
        foreach (ObservationKind::cases() as $kind) {
            $observation = self::observation((string) $kind->value, $kind);
            $result = $this->evaluate($observation);

            self::assertSame($kind === ObservationKind::IN_SYNC, $result->passed(), $kind->value);
            self::assertSame($kind === ObservationKind::IN_SYNC ? 0 : 1, $result->failingCount(), $kind->value);
        }
    }

    public function testDomainRejectsIncompleteInSyncEvidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::observation('incomplete', ObservationKind::IN_SYNC, EvidenceStatus::INCOMPLETE);
    }

    public function testMixedFailuresRetainCollectionOrderAndResultInvariants(): void
    {
        $result = $this->evaluate(
            self::observation('zeta', ObservationKind::CONFIGURATION_DIFFERENCE),
            self::observation('alpha', ObservationKind::IN_SYNC),
            self::observation('beta', ObservationKind::UNKNOWN),
        );

        self::assertFalse($result->passed());
        self::assertSame(2, $result->failingCount());
        self::assertSame(['application.beta', 'application.zeta'], array_map(
            static fn (ResourceObservation $observation): string => (string) $observation->address,
            iterator_to_array($result->failingObservations, false),
        ));
        self::assertFalse($result->passed());
        self::assertSame(2, $result->failingCount());
        self::assertSame(2, $result->failingObservations->count());
    }

    private function evaluate(ResourceObservation ...$observations): \LaravelCloudBlueprint\Drift\DriftCheckResult
    {
        return new DriftCheckEvaluator()->evaluate(new DriftReport(new ResourceObservationCollection(...$observations)));
    }

    private static function observation(
        string $name,
        ObservationKind $kind,
        ?EvidenceStatus $evidence = null,
    ): ResourceObservation {
        $evidence ??= $kind === ObservationKind::UNKNOWN
            ? EvidenceStatus::INCOMPLETE
            : EvidenceStatus::COMPLETE;

        return new ResourceObservation(
            new ResourceAddress(ResourceType::APPLICATION, $name),
            $kind,
            OwnershipStatus::UNMANAGED,
            ReconciliationStatus::UNSUPPORTED,
            $evidence,
            $kind === ObservationKind::CONFIGURATION_DIFFERENCE ? new ChangedFields('branch') : null,
        );
    }
}
