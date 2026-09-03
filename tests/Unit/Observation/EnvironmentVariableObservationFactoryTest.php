<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Blueprint\EnvironmentVariableReference;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariable;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Observation\EnvironmentVariableObservationEvidence;
use LaravelCloudBlueprint\Observation\EnvironmentVariableObservationFactory;
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

final class EnvironmentVariableObservationFactoryTest extends TestCase
{
    private const CURRENT_SECRET = 'LCB_SECRET_CURRENT_SENTINEL';
    private const DESIRED_SECRET = 'LCB_SECRET_DESIRED_SENTINEL';
    private const FROM_ENV_SECRET = 'LCB_SECRET_FROM_ENV_SENTINEL';

    private EnvironmentVariableObservationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EnvironmentVariableObservationFactory();
    }

    public function testMissingVariableIsADesiredResourceMissing(): void
    {
        $observation = $this->create('desired', self::available());

        self::assertObservation($observation, ObservationKind::DESIRED_RESOURCE_MISSING, ReconciliationStatus::SUPPORTED);
    }

    public function testEqualVariableValueIsInSync(): void
    {
        $observation = $this->create('same', self::available(new CloudEnvironmentVariable('API_TOKEN', 'same')));

        self::assertObservation($observation, ObservationKind::IN_SYNC, ReconciliationStatus::NOT_APPLICABLE);
    }

    public function testDifferentVariableValueReportsOnlyTheFieldName(): void
    {
        $observation = $this->create(self::DESIRED_SECRET, self::available(
            new CloudEnvironmentVariable('API_TOKEN', self::CURRENT_SECRET),
        ));

        self::assertObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, ReconciliationStatus::SUPPORTED);
        self::assertSame(['value'], $observation->changedFields->values());
        self::assertSame(ReasonCode::ENVIRONMENT_VARIABLE_VALUE_DIFFERENCE, $observation->reasonCode);
        self::assertSecretsAbsent($observation);
    }

    public function testUnavailableCollectionIsUnknown(): void
    {
        $observation = $this->create(self::DESIRED_SECRET, EnvironmentVariableObservationEvidence::collectionUnavailable());

        self::assertObservation($observation, ObservationKind::UNKNOWN, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
        self::assertSecretsAbsent($observation);
    }

    public function testUnresolvedParentCannotClaimVariableAbsence(): void
    {
        $observation = $this->create(self::DESIRED_SECRET, EnvironmentVariableObservationEvidence::parentUnresolved());

        self::assertObservation($observation, ObservationKind::UNKNOWN, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    public function testUnresolvedParentCanBeObservedWithoutResolvingSensitiveValues(): void
    {
        $observation = $this->factory->createWithoutValues(
            self::address(),
            EnvironmentVariableObservationEvidence::parentUnresolved(),
        );

        self::assertObservation($observation, ObservationKind::UNKNOWN, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
        self::assertSecretsAbsent($observation);
    }

    public function testLiteralAndFromEnvResolvedValuesNeverEnterOutputOrCollectionSerialization(): void
    {
        $literal = new VariableDefinition('API_TOKEN', new LiteralVariableValue(self::DESIRED_SECRET), true);
        $reference = new VariableDefinition('API_TOKEN', new EnvironmentVariableReference('SOURCE_SECRET'), true);
        $evidence = self::available(new CloudEnvironmentVariable('API_TOKEN', self::CURRENT_SECRET));

        $literalObservation = $this->factory->create(self::address(), $literal, self::DESIRED_SECRET, $evidence);
        $fromEnvObservation = $this->factory->create(self::address(), $reference, self::FROM_ENV_SECRET, $evidence);
        $collection = new ResourceObservationCollection($literalObservation);

        self::assertSecretsAbsent($literalObservation);
        self::assertSecretsAbsent($fromEnvObservation);
        self::assertSecretsAbsent($collection);
    }

    public function testProducerValidationExceptionDoesNotEchoDesiredSecret(): void
    {
        try {
            $this->factory->create(
                new ResourceAddress(ResourceType::ENVIRONMENT, self::DESIRED_SECRET),
                self::definition(),
                self::DESIRED_SECRET,
                EnvironmentVariableObservationEvidence::parentUnresolved(),
            );
            self::fail('Expected a non-variable address to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString(self::CURRENT_SECRET, $exception->getMessage());
            self::assertStringNotContainsString(self::DESIRED_SECRET, $exception->getMessage());
            self::assertStringNotContainsString(self::FROM_ENV_SECRET, $exception->getMessage());
        }
    }

    private function create(
        string $desiredValue,
        EnvironmentVariableObservationEvidence $evidence,
    ): ResourceObservation {
        return $this->factory->create(self::address(), self::definition(), $desiredValue, $evidence);
    }

    private static function address(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::VARIABLE, 'production.API_TOKEN');
    }

    private static function definition(): VariableDefinition
    {
        return new VariableDefinition('API_TOKEN', new LiteralVariableValue(self::DESIRED_SECRET), true);
    }

    private static function available(CloudEnvironmentVariable ...$variables): EnvironmentVariableObservationEvidence
    {
        return EnvironmentVariableObservationEvidence::available(new CloudEnvironmentVariableCollection(...$variables));
    }

    private static function assertObservation(
        ResourceObservation $observation,
        ObservationKind $kind,
        ReconciliationStatus $reconciliation,
        EvidenceStatus $evidence = EvidenceStatus::COMPLETE,
    ): void {
        self::assertSame('variable.production.API_TOKEN', (string) $observation->address);
        self::assertSame(ResourceType::VARIABLE, $observation->resourceType);
        self::assertSame($kind, $observation->observation);
        self::assertSame(OwnershipStatus::NONE, $observation->ownership);
        self::assertSame($reconciliation, $observation->reconciliation);
        self::assertSame($evidence, $observation->evidence);
    }

    private static function assertSecretsAbsent(object $value): void
    {
        $serialized = serialize($value);
        self::assertStringNotContainsString(self::CURRENT_SECRET, $serialized);
        self::assertStringNotContainsString(self::DESIRED_SECRET, $serialized);
        self::assertStringNotContainsString(self::FROM_ENV_SECRET, $serialized);
        self::assertStringNotContainsString(self::CURRENT_SECRET, print_r($value, true));
        self::assertStringNotContainsString(self::DESIRED_SECRET, print_r($value, true));
        self::assertStringNotContainsString(self::FROM_ENV_SECRET, print_r($value, true));
    }
}
