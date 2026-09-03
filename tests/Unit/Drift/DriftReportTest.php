<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Drift;

use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariable;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Drift\CreateDriftReport;
use LaravelCloudBlueprint\Drift\DriftReport;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Observation\ResourceObservationCollection;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DriftReportTest extends TestCase
{
    public function testSummaryCountsEverySettledKindExactlyOnce(): void
    {
        $observations = [];
        foreach (ObservationKind::cases() as $index => $kind) {
            $observations[] = new ResourceObservation(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'environment-' . $index),
                $kind,
                OwnershipStatus::MANAGED,
                $kind === ObservationKind::IN_SYNC
                    ? ReconciliationStatus::NOT_APPLICABLE
                    : ReconciliationStatus::BLOCKED,
                $kind === ObservationKind::UNKNOWN ? EvidenceStatus::INCOMPLETE : EvidenceStatus::COMPLETE,
                $kind === ObservationKind::CONFIGURATION_DIFFERENCE
                    ? new \LaravelCloudBlueprint\Observation\ChangedFields('branch')
                    : null,
            );
        }

        $report = new DriftReport(new ResourceObservationCollection(...array_reverse($observations)));

        self::assertSame(count(ObservationKind::cases()), $report->summary->total());
        foreach (ObservationKind::cases() as $kind) {
            self::assertSame(1, $report->summary->count($kind));
        }
        self::assertArrayNotHasKey('remote_drift', $report->summary->toArray());
    }

    public function testReportUsesOneReadOnlyPlanningDiscoveryPassAndExcludesUnrelatedCloudResources(): void
    {
        $cloud = new ReadOnlyDriftCloudClient([
            new CloudApplication('unrelated-id', 'unrelated', null, 'other-region', null),
        ]);
        $state = StateDocument::empty();

        $report = self::reports()->create(self::blueprint(), $cloud, $state);
        $addresses = array_map(
            static fn (ResourceObservation $observation): string => (string) $observation->address,
            iterator_to_array($report->observations, false),
        );

        self::assertSame([
            'application.my-api',
            'environment.production',
        ], $addresses);
        self::assertSame(2, $report->summary->total());
        self::assertSame(2, $report->summary->count(ObservationKind::DESIRED_RESOURCE_MISSING));
        self::assertSame(['organization', 'applications'], $cloud->reads);
        self::assertSame(0, $cloud->mutationCalls);
        self::assertTrue($state->materiallyEquals(StateDocument::empty()));
    }

    public function testRepeatedReportsHaveDeterministicOrderingAndNoRecorderLeakage(): void
    {
        $service = self::reports();

        $first = $service->create(self::blueprint(), new ReadOnlyDriftCloudClient(), StateDocument::empty());
        $second = $service->create(self::blueprint(), new ReadOnlyDriftCloudClient(), StateDocument::empty());

        self::assertEquals($first, $second);
        self::assertSame(2, $second->observations->count());
    }

    public function testVariableValuesAndRemoteOnlyKeysNeverEnterTheReportDomain(): void
    {
        $desiredSentinel = 'desired-secret-sentinel-9173';
        $remoteSentinel = 'remote-secret-sentinel-2846';
        $remoteOnlySentinel = 'remote-only-secret-sentinel-5521';
        $blueprint = new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition(
                'my-api',
                'eu-central-1',
                new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api'),
            ),
            new EnvironmentDefinitionCollection(new EnvironmentDefinition(
                'production',
                'main',
                new VariableDefinitionCollection(new VariableDefinition(
                    'APP_SECRET',
                    new LiteralVariableValue($desiredSentinel),
                    true,
                )),
            )),
        );
        $cloud = new ReadOnlyDriftCloudClient(
            [new CloudApplication('app-id', 'my-api', null, 'eu-central-1', 'acme/my-api')],
            [new CloudEnvironment('env-id', 'app-id', 'production', 'main')],
            new CloudEnvironmentDetails(
                'env-id',
                'production',
                new CloudEnvironmentVariableCollection(
                    new CloudEnvironmentVariable('APP_SECRET', $remoteSentinel),
                    new CloudEnvironmentVariable('REMOTE_ONLY', $remoteOnlySentinel),
                ),
            ),
        );

        $report = self::reports()->create($blueprint, $cloud, StateDocument::empty());
        $serialized = serialize($report);

        self::assertSame(3, $report->summary->total());
        self::assertSame(1, $report->summary->count(ObservationKind::CONFIGURATION_DIFFERENCE));
        self::assertStringNotContainsString($desiredSentinel, $serialized);
        self::assertStringNotContainsString($remoteSentinel, $serialized);
        self::assertStringNotContainsString($remoteOnlySentinel, $serialized);
        self::assertSame(0, $cloud->mutationCalls);
    }

    public function testStateV1OwnedApplicationAbsentFromBlueprintIsReportedWithoutWritingOrProvenanceInference(): void
    {
        $legacy = new StateDocument(
            StateVersion::V1,
            7,
            'acme',
            new StateResource(
                new ResourceAddress(ResourceType::APPLICATION, 'legacy-api'),
                ResourceType::APPLICATION,
                'legacy-id',
            ),
        );
        $cloud = new ReadOnlyDriftCloudClient([
            new CloudApplication('desired-id', 'my-api', null, 'eu-central-1', 'acme/my-api'),
            new CloudApplication('legacy-id', 'legacy-api', null, 'eu-central-1', 'acme/legacy-api'),
        ]);

        $report = self::reports()->create(self::blueprint(), $cloud, $legacy);
        $byAddress = [];
        foreach ($report->observations as $observation) {
            $byAddress[(string) $observation->address] = $observation;
        }

        self::assertSame(ObservationKind::DESIRED_RESOURCE_ABSENT, $byAddress['application.legacy-api']->observation);
        self::assertSame(OwnershipStatus::MANAGED, $byAddress['application.legacy-api']->ownership);
        self::assertSame(ReconciliationStatus::UNSUPPORTED, $byAddress['application.legacy-api']->reconciliation);
        self::assertSame(StateVersion::V1, $legacy->version);
        self::assertSame(7, $legacy->serial);
        self::assertSame(0, $cloud->mutationCalls);
    }

    public function testAmbiguousScopedCandidateBecomesAnObservationInsteadOfAbortingTheReport(): void
    {
        $cloud = new ReadOnlyDriftCloudClient([
            new CloudApplication('first-id', 'my-api', null, 'eu-central-1', 'acme/my-api'),
            new CloudApplication('second-id', 'my-api', null, 'eu-central-1', 'acme/my-api'),
        ]);

        $report = self::reports()->create(self::blueprint(), $cloud, StateDocument::empty());
        $application = iterator_to_array($report->observations, false)[0];

        self::assertSame('application.my-api', (string) $application->address);
        self::assertSame(ObservationKind::IDENTITY_CONFLICT, $application->observation);
        self::assertSame(OwnershipStatus::UNMANAGED, $application->ownership);
        self::assertSame(0, $cloud->mutationCalls);
    }

    private static function reports(): CreateDriftReport
    {
        return new CreateDriftReport(new CreatePlan(
            new VariableValueResolver(new EmptyDriftEnvironment()),
        ));
    }

    private static function blueprint(): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition(
                'my-api',
                'eu-central-1',
                new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api'),
            ),
            new EnvironmentDefinitionCollection(
                new EnvironmentDefinition('production', 'main', new VariableDefinitionCollection()),
            ),
        );
    }
}

final class ReadOnlyDriftCloudClient implements LaravelCloudClient
{
    /** @var list<string> */
    public array $reads = [];
    public int $mutationCalls = 0;

    /** @param list<CloudApplication> $applications */
    public function __construct(
        private array $applications = [],
        /** @var list<CloudEnvironment> */
        private array $environments = [],
        private ?CloudEnvironmentDetails $details = null,
    ) {
    }

    public function organization(): CloudOrganization
    {
        $this->reads[] = 'organization';
        return new CloudOrganization('org-id', 'Acme', 'acme');
    }

    public function applications(): array
    {
        $this->reads[] = 'applications';
        return $this->applications;
    }

    public function environments(string $applicationId): array
    {
        $this->reads[] = 'environments:' . $applicationId;
        return $this->environments;
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        $this->reads[] = 'environment:' . $environmentId;
        return $this->details ?? throw new LogicException('Unexpected environment detail discovery.');
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        ++$this->mutationCalls;
        throw new LogicException('Drift reporting must remain read-only.');
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        ++$this->mutationCalls;
        throw new LogicException('Drift reporting must remain read-only.');
    }

    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment
    {
        ++$this->mutationCalls;
        throw new LogicException('Drift reporting must remain read-only.');
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        ++$this->mutationCalls;
        throw new LogicException('Drift reporting must remain read-only.');
    }
}

final readonly class EmptyDriftEnvironment implements EnvironmentValueProvider
{
    public function value(string $name): ?string
    {
        return null;
    }
}
