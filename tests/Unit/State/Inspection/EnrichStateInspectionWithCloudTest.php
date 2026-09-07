<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\State\Inspection;

use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReader;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Inspection\EnrichStateInspectionWithCloud;
use LaravelCloudBlueprint\State\Inspection\CollectStateInspectionCloudEvidence;
use LaravelCloudBlueprint\State\Inspection\InspectLocalState;
use LaravelCloudBlueprint\State\Inspection\LoadedState;
use LaravelCloudBlueprint\State\Inspection\StateDiagnosticCode;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use PHPUnit\Framework\TestCase;

final class EnrichStateInspectionWithCloudTest extends TestCase
{
    public function testExactApplicationIsVerifiedWithoutMutation(): void
    {
        $state = $this->applicationState();
        $cloud = new InspectionCloud(applications: [new CloudApplication('app-owned', 'api', null, 'region', null)]);

        $report = $this->enrich($state, $cloud);

        self::assertContains(StateDiagnosticCode::REMOTE_OWNERSHIP_VERIFIED, $this->codes($report));
        self::assertSame([], $cloud->mutations);
    }

    public function testMissingAndSameNameApplicationAreDistinguishedWithoutOwnershipTransfer(): void
    {
        $state = $this->applicationState();
        $missing = $this->enrich($state, new InspectionCloud());
        $replacement = $this->enrich($state, new InspectionCloud(applications: [new CloudApplication('app-other', 'api', null, 'region', null)]));

        self::assertContains(StateDiagnosticCode::REMOTE_IDENTITY_MISSING, $this->codes($missing));
        self::assertContains(StateDiagnosticCode::REMOTE_IDENTITY_REPLACEMENT, $this->codes($replacement));
        self::assertSame('app-owned', $state->resources()[0]->remoteId);
    }

    public function testEnvironmentAndDatabaseParentMismatchesAreDiagnosed(): void
    {
        $application = new StateResource(new ResourceAddress(ResourceType::APPLICATION, 'api'), ResourceType::APPLICATION, 'app-owned');
        $environment = new StateResource(new ResourceAddress(ResourceType::ENVIRONMENT, 'production'), ResourceType::ENVIRONMENT, 'env-owned', $application->address);
        $cluster = new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'), ResourceType::DATABASE_CLUSTER, 'cluster-owned');
        $database = new StateResource(new ResourceAddress(ResourceType::DATABASE, 'primary.app'), ResourceType::DATABASE, 'database-owned', $cluster->address);
        $state = StateDocument::empty()->withResource($application)->withResource($environment)->withResource($cluster)->withResource($database);
        $cloud = new InspectionCloud(
            applications: [new CloudApplication('app-owned', 'api', null, 'region', null)],
            environments: [new CloudEnvironment('env-owned', 'app-other', 'production', 'main')],
            cluster: new CloudDatabaseCluster('cluster-owned', 'primary', 'mysql', 'ready', 'region', new CloudUnknownDatabaseConfiguration()),
            database: new CloudDatabase('database-owned', 'cluster-other', 'app'),
        );

        self::assertSame(2, count(array_filter($this->codes($this->enrich($state, $cloud)), static fn (StateDiagnosticCode $code): bool => $code === StateDiagnosticCode::PARENT_CHILD_IDENTITY_CONFLICT)));
    }

    public function testCompleteClusterTopologyReportsOnlyUnmanagedChildrenAndNeverInfersProvenance(): void
    {
        $cluster = new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'), ResourceType::DATABASE_CLUSTER, 'cluster-owned');
        $derived = new StateResource(new ResourceAddress(ResourceType::DATABASE, 'primary.default'), ResourceType::DATABASE, 'database-derived', $cluster->address, StateOwnershipClassification::DERIVED, StateProvenance::CLUSTER_CREATE_RESPONSE);
        $state = StateDocument::empty()->withResource($cluster)->withResource($derived);
        $cloud = new InspectionCloud(
            cluster: new CloudDatabaseCluster('cluster-owned', 'primary', 'mysql', 'ready', 'region', new CloudUnknownDatabaseConfiguration(), ['database-derived', 'database-unmanaged'], true),
            databases: [new CloudDatabase('database-derived', 'cluster-owned', 'default', 'cluster-owned'), new CloudDatabase('database-unmanaged', 'cluster-owned', 'other', 'cluster-owned')],
            database: new CloudDatabase('database-derived', 'cluster-owned', 'default', 'cluster-owned'),
        );

        $report = $this->enrich($state, $cloud);
        self::assertContains(StateDiagnosticCode::UNMANAGED_REMOTE_CHILD, $this->codes($report));
        self::assertSame(StateOwnershipClassification::DERIVED, $derived->classification);
        self::assertSame(StateProvenance::CLUSTER_CREATE_RESPONSE, $derived->provenance);
        self::assertStringNotContainsString('database-unmanaged', json_encode($report->diagnostics(), JSON_THROW_ON_ERROR));
    }

    public function testIncompleteTopologyAndReadFailureDoNotBecomeAbsence(): void
    {
        $cluster = new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'), ResourceType::DATABASE_CLUSTER, 'cluster-owned');
        $state = StateDocument::empty()->withResource($cluster);
        $incomplete = $this->enrich($state, new InspectionCloud(cluster: new CloudDatabaseCluster('cluster-owned', 'primary', 'mysql', 'ready', 'region', new CloudUnknownDatabaseConfiguration(), [], false)));
        $failure = $this->enrich($state, new InspectionCloud(clusterFailure: true));

        self::assertContains(StateDiagnosticCode::EVIDENCE_INCOMPLETE, $this->codes($incomplete));
        self::assertContains(StateDiagnosticCode::EVIDENCE_INCOMPLETE, $this->codes($failure));
        self::assertNotContains(StateDiagnosticCode::REMOTE_IDENTITY_MISSING, $this->codes($failure));
    }

    public function testSingleEvidencePassReusesListsAndExactDetails(): void
    {
        $application = new StateResource(new ResourceAddress(ResourceType::APPLICATION, 'api'), ResourceType::APPLICATION, 'app-owned');
        $environment = new StateResource(new ResourceAddress(ResourceType::ENVIRONMENT, 'production'), ResourceType::ENVIRONMENT, 'env-owned', $application->address);
        $cluster = new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'), ResourceType::DATABASE_CLUSTER, 'cluster-owned');
        $database = new StateResource(new ResourceAddress(ResourceType::DATABASE, 'primary.app'), ResourceType::DATABASE, 'database-owned', $cluster->address);
        $state = StateDocument::empty()->withResource($application)->withResource($environment)->withResource($cluster)->withResource($database);
        $cloud = new InspectionCloud(
            applications: [new CloudApplication('app-owned', 'api', null, 'region', null)],
            environments: [new CloudEnvironment('env-owned', 'app-owned', 'production', 'main')],
            cluster: new CloudDatabaseCluster('cluster-owned', 'primary', 'mysql', 'ready', 'region', new CloudUnknownDatabaseConfiguration(), ['database-owned'], true),
            databases: [new CloudDatabase('database-owned', 'cluster-owned', 'app', 'cluster-owned')],
            database: new CloudDatabase('database-owned', 'cluster-owned', 'app', 'cluster-owned'),
        );

        $this->enrich($state, $cloud);

        self::assertSame([
            'applications', 'environments:app-owned', 'databaseClusters', 'database:cluster-owned:database-owned',
            'databaseCluster:cluster-owned', 'databases:cluster-owned',
        ], $cloud->calls);
    }

    private function applicationState(): StateDocument
    {
        return StateDocument::empty()->withResource(new StateResource(new ResourceAddress(ResourceType::APPLICATION, 'api'), ResourceType::APPLICATION, 'app-owned'));
    }

    private function enrich(StateDocument $state, InspectionCloud $cloud): \LaravelCloudBlueprint\State\Inspection\StateInspectionReport
    {
        $local = (new InspectLocalState())->inspect(new LoadedState($state, StateVersion::V2));
        $evidence = (new CollectStateInspectionCloudEvidence())->collect($state, $cloud);
        return (new EnrichStateInspectionWithCloud())->enrich($local, $state, $evidence);
    }

    /** @return list<StateDiagnosticCode> */
    private function codes(\LaravelCloudBlueprint\State\Inspection\StateInspectionReport $report): array
    {
        return array_map(static fn ($diagnostic): StateDiagnosticCode => $diagnostic->code, $report->diagnostics());
    }
}

final class InspectionCloud implements StateInspectionCloudReader
{
    /** @var list<string> */ public array $mutations = [];
    /** @var list<string> */ public array $calls = [];
    /**
     * @param list<CloudApplication> $applications
     * @param list<CloudEnvironment> $environments
     * @param list<CloudDatabase> $databases
     */
    public function __construct(private array $applications = [], private array $environments = [], private ?CloudDatabaseCluster $cluster = null, private array $databases = [], private ?CloudDatabase $database = null, private bool $clusterFailure = false) {}
    public function applications(): array { $this->calls[] = 'applications'; return $this->applications; }
    public function environments(string $applicationId): array { $this->calls[] = 'environments:' . $applicationId; return $this->environments; }
    public function databaseClusters(): array { $this->calls[] = 'databaseClusters'; return $this->cluster === null ? [] : [$this->cluster]; }
    public function databaseCluster(string $clusterId): CloudDatabaseCluster { $this->calls[] = 'databaseCluster:' . $clusterId; if ($this->clusterFailure) { throw new \LaravelCloudBlueprint\Cloud\Exception\CloudTransportException('failed', 'GET', ''); } if ($this->cluster === null) { throw new CloudResourceNotFoundException('missing', 'GET', '', 404); } return $this->cluster; }
    public function databases(string $clusterId): array { $this->calls[] = 'databases:' . $clusterId; return $this->databases; }
    public function database(string $clusterId, string $databaseId): CloudDatabase { $this->calls[] = 'database:' . $clusterId . ':' . $databaseId; if ($this->database === null) { throw new CloudResourceNotFoundException('missing', 'GET', '', 404); } return $this->database; }
    public function databaseWithDestructiveRelationships(string $clusterId, string $databaseId): CloudDatabase { return $this->database($clusterId, $databaseId); }
}
