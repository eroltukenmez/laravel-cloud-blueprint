<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Observation\ChangedFields;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReasonCode;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ResourceObservationTest extends TestCase
{
    /**
     * @return iterable<string, array{ObservationKind, EvidenceStatus, ChangedFields}>
     */
    public static function validKinds(): iterable
    {
        yield 'in sync' => [ObservationKind::IN_SYNC, EvidenceStatus::COMPLETE, ChangedFields::none()];
        yield 'desired resource missing' => [ObservationKind::DESIRED_RESOURCE_MISSING, EvidenceStatus::COMPLETE, ChangedFields::none()];
        yield 'desired resource absent' => [ObservationKind::DESIRED_RESOURCE_ABSENT, EvidenceStatus::COMPLETE, ChangedFields::none()];
        yield 'configuration difference' => [ObservationKind::CONFIGURATION_DIFFERENCE, EvidenceStatus::COMPLETE, new ChangedFields('branch')];
        yield 'identity missing' => [ObservationKind::IDENTITY_MISSING, EvidenceStatus::COMPLETE, ChangedFields::none()];
        yield 'identity replacement' => [ObservationKind::IDENTITY_REPLACEMENT, EvidenceStatus::COMPLETE, ChangedFields::none()];
        yield 'identity conflict' => [ObservationKind::IDENTITY_CONFLICT, EvidenceStatus::COMPLETE, ChangedFields::none()];
        yield 'lifecycle condition' => [ObservationKind::LIFECYCLE_CONDITION, EvidenceStatus::COMPLETE, ChangedFields::none()];
        yield 'unknown' => [ObservationKind::UNKNOWN, EvidenceStatus::INCOMPLETE, ChangedFields::none()];
    }

    #[DataProvider('validKinds')]
    public function testEveryObservationKindCanBeRepresented(
        ObservationKind $kind,
        EvidenceStatus $evidence,
        ChangedFields $changedFields,
    ): void {
        $observation = self::observation($kind, $evidence, $changedFields);

        self::assertSame($kind, $observation->observation);
        self::assertSame($evidence, $observation->evidence);
        self::assertSame($changedFields->values(), $observation->changedFields->values());
        self::assertSame(ResourceType::ENVIRONMENT, $observation->resourceType);
    }

    /** @return iterable<string, array{ObservationKind}> */
    public static function completeOnlyKinds(): iterable
    {
        yield 'in sync' => [ObservationKind::IN_SYNC];
        yield 'configuration difference' => [ObservationKind::CONFIGURATION_DIFFERENCE];
        yield 'identity missing' => [ObservationKind::IDENTITY_MISSING];
        yield 'identity replacement' => [ObservationKind::IDENTITY_REPLACEMENT];
    }

    #[DataProvider('completeOnlyKinds')]
    public function testConclusiveObservationRejectsIncompleteEvidence(ObservationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires complete evidence');

        self::observation(
            $kind,
            EvidenceStatus::INCOMPLETE,
            $kind === ObservationKind::CONFIGURATION_DIFFERENCE
                ? new ChangedFields('branch')
                : ChangedFields::none(),
        );
    }

    public function testUnknownRejectsCompleteEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires incomplete evidence');

        self::observation(ObservationKind::UNKNOWN, EvidenceStatus::COMPLETE);
    }

    public function testChangedFieldsAreAcceptedOnlyForConfigurationDifferences(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid only for a configuration difference');

        self::observation(ObservationKind::IN_SYNC, EvidenceStatus::COMPLETE, new ChangedFields('branch'));
    }

    public function testConfigurationDifferenceRequiresAChangedField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one changed field');

        self::observation(ObservationKind::CONFIGURATION_DIFFERENCE, EvidenceStatus::COMPLETE);
    }

    public function testChangedFieldsAreSortedAndVariableValueIsSafe(): void
    {
        $fields = new ChangedFields('value', 'branch', 'retention_days');

        self::assertSame(['branch', 'retention_days', 'value'], $fields->values());
        self::assertSame(3, $fields->count());
        self::assertSame($fields->values(), iterator_to_array($fields, false));
    }

    public function testDuplicateChangedFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be unique');

        new ChangedFields('value', 'value');
    }

    #[DataProvider('unsafeFieldNames')]
    public function testUnsafeChangedFieldNameIsRejected(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('safe snake_case names');

        new ChangedFields($field);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeFieldNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Value'];
        yield 'space' => ['secret value'];
        yield 'dot' => ['configuration.value'];
        yield 'hyphen' => ['retention-days'];
        yield 'control character' => ["value\nsecret"];
    }

    public function testOwnershipAndReconciliationAreIndependent(): void
    {
        $derived = self::observation(
            ObservationKind::IN_SYNC,
            EvidenceStatus::COMPLETE,
            ownership: OwnershipStatus::DERIVED,
            reconciliation: ReconciliationStatus::NOT_APPLICABLE,
        );
        $unmanaged = self::observation(
            ObservationKind::CONFIGURATION_DIFFERENCE,
            EvidenceStatus::COMPLETE,
            new ChangedFields('branch'),
            OwnershipStatus::UNMANAGED,
            ReconciliationStatus::BLOCKED,
        );

        self::assertSame(OwnershipStatus::DERIVED, $derived->ownership);
        self::assertSame(ReconciliationStatus::NOT_APPLICABLE, $derived->reconciliation);
        self::assertSame(OwnershipStatus::UNMANAGED, $unmanaged->ownership);
        self::assertSame(ReconciliationStatus::BLOCKED, $unmanaged->reconciliation);
        self::assertNotSame(OwnershipStatus::CONFLICT, $derived->ownership);
        self::assertNotSame(OwnershipStatus::CONFLICT, $unmanaged->ownership);
    }

    public function testVariableObservationHasNoSensitiveValueBearingChannel(): void
    {
        $current = 'LCB_SECRET_CURRENT_SENTINEL';
        $desired = 'LCB_SECRET_DESIRED_SENTINEL';
        $observation = new ResourceObservation(
            new ResourceAddress(ResourceType::VARIABLE, 'production.API_TOKEN'),
            ObservationKind::CONFIGURATION_DIFFERENCE,
            OwnershipStatus::NONE,
            ReconciliationStatus::SUPPORTED,
            EvidenceStatus::COMPLETE,
            new ChangedFields('value'),
            ReasonCode::ENVIRONMENT_VARIABLE_VALUE_DIFFERENCE,
        );

        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(ResourceObservation::class))->getProperties(),
        );
        self::assertSame([
            'resourceType',
            'changedFields',
            'address',
            'observation',
            'ownership',
            'reconciliation',
            'evidence',
            'reasonCode',
        ], $properties);
        self::assertSame(['value'], $observation->changedFields->values());
        self::assertStringNotContainsString($current, serialize($observation));
        self::assertStringNotContainsString($desired, serialize($observation));

        try {
            new ChangedFields($current . $desired);
            self::fail('Expected sentinel-bearing field name to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString($current, $exception->getMessage());
            self::assertStringNotContainsString($desired, $exception->getMessage());
        }
    }

    private static function observation(
        ObservationKind $kind,
        EvidenceStatus $evidence,
        ?ChangedFields $changedFields = null,
        OwnershipStatus $ownership = OwnershipStatus::MANAGED,
        ReconciliationStatus $reconciliation = ReconciliationStatus::SUPPORTED,
    ): ResourceObservation {
        return new ResourceObservation(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
            $kind,
            $ownership,
            $reconciliation,
            $evidence,
            $changedFields,
            $kind === ObservationKind::CONFIGURATION_DIFFERENCE
                ? ReasonCode::ENVIRONMENT_BRANCH_DIFFERENCE
                : null,
        );
    }
}
