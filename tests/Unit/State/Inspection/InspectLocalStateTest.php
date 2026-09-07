<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\State\Inspection;

use LaravelCloudBlueprint\Infrastructure\State\LocalFileStateStore;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Inspection\InspectLocalState;
use LaravelCloudBlueprint\State\Inspection\LoadedState;
use LaravelCloudBlueprint\State\Inspection\RecoveryDisposition;
use LaravelCloudBlueprint\State\Inspection\StateDiagnosticCode;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use PHPUnit\Framework\TestCase;

final class InspectLocalStateTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/lcb-inspection-' . bin2hex(random_bytes(8));
        $this->path = $this->directory . '/.lcb/state.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/.lcb/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory . '/.lcb')) {
            rmdir($this->directory . '/.lcb');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testEmptyStateHasNoDiagnostics(): void
    {
        self::assertSame([], $this->inspect(StateDocument::empty())->diagnostics());
    }

    public function testManagedOwnershipIsReportedWithTypedClassification(): void
    {
        $report = $this->inspect(StateDocument::empty()->withResource(new StateResource(
            new ResourceAddress(ResourceType::APPLICATION, 'api'),
            ResourceType::APPLICATION,
            'app_internal_only',
        )));

        $diagnostic = $report->diagnostics()[0];
        self::assertSame(StateDiagnosticCode::LOCAL_OWNERSHIP_VALID, $diagnostic->code);
        self::assertSame(StateOwnershipClassification::MANAGED, $diagnostic->classification);
        self::assertNull($diagnostic->provenance);
        self::assertSame(RecoveryDisposition::NONE, $diagnostic->recoveryDisposition);
    }

    public function testDerivedOwnershipRequiresAndReportsPersistedProvenance(): void
    {
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $report = $this->inspect(StateDocument::empty()
            ->withResource(new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster_internal'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.default'),
                ResourceType::DATABASE,
                'database_internal',
                $cluster,
                StateOwnershipClassification::DERIVED,
                StateProvenance::CLUSTER_CREATE_RESPONSE,
            )));

        $diagnostic = $report->diagnostics()[0];
        self::assertSame('database.primary.default', (string) $diagnostic->address);
        self::assertSame(StateOwnershipClassification::DERIVED, $diagnostic->classification);
        self::assertSame(StateProvenance::CLUSTER_CREATE_RESPONSE, $diagnostic->provenance);
    }

    public function testVersionOneMetadataIsRetainedWithoutWritingOrInventingDerivedProvenance(): void
    {
        $v1 = '{"version":1,"serial":7,"organization":"acme","resources":{' .
            '"database_cluster.primary":{"type":"database_cluster","remote_id":"cluster_internal"},' .
            '"database.primary.production":{"type":"database","remote_id":"database_internal",' .
            '"parent":"database_cluster.primary"}}}';
        self::assertTrue(mkdir(dirname($this->path), 0777, true));
        self::assertNotFalse(file_put_contents($this->path, $v1));

        $loaded = (new LocalFileStateStore($this->path))->loadWithMetadata();
        $report = (new InspectLocalState())->inspect($loaded);

        self::assertSame(StateVersion::V1, $loaded->sourceVersion);
        self::assertSame(StateOwnershipClassification::MANAGED, $loaded->document->get(
            new ResourceAddress(ResourceType::DATABASE, 'primary.production'),
        )->classification);
        self::assertNull($loaded->document->get(new ResourceAddress(ResourceType::DATABASE, 'primary.production'))->provenance);
        self::assertSame($v1, file_get_contents($this->path));
        self::assertSame([
            StateDiagnosticCode::LEGACY_STATE_FORMAT,
            StateDiagnosticCode::HISTORICAL_PROVENANCE_UNAVAILABLE,
            StateDiagnosticCode::LOCAL_OWNERSHIP_VALID,
        ], array_map(static fn ($diagnostic): StateDiagnosticCode => $diagnostic->code, $report->diagnostics()));
    }

    public function testClusterWithoutDerivedChildDoesNotInferHistoricalProvenance(): void
    {
        $report = $this->inspect(StateDocument::empty()->withResource(new StateResource(
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
            ResourceType::DATABASE_CLUSTER,
            'cluster_internal',
        )));

        self::assertSame([StateDiagnosticCode::LOCAL_OWNERSHIP_VALID], array_map(
            static fn ($diagnostic): StateDiagnosticCode => $diagnostic->code,
            $report->diagnostics(),
        ));
    }

    public function testLoadableDuplicateRemoteIdentityIsDiagnosedInAddressOrder(): void
    {
        $report = $this->inspect(StateDocument::empty()
            ->withResource(new StateResource(new ResourceAddress(ResourceType::APPLICATION, 'api'), ResourceType::APPLICATION, 'same_internal'))
            ->withResource(new StateResource(new ResourceAddress(ResourceType::ENVIRONMENT, 'production'), ResourceType::ENVIRONMENT, 'same_internal', new ResourceAddress(ResourceType::APPLICATION, 'api'))));

        self::assertSame([
            'application.api',
            'environment.production',
        ], array_map(static fn ($diagnostic): string => (string) $diagnostic->address, $report->diagnostics()));
        self::assertSame([
            StateDiagnosticCode::IDENTITY_CONFLICT,
            StateDiagnosticCode::IDENTITY_CONFLICT,
        ], array_map(static fn ($diagnostic): StateDiagnosticCode => $diagnostic->code, $report->diagnostics()));
    }

    public function testRenderableDiagnosticsDoNotContainRemoteIdentities(): void
    {
        $report = $this->inspect(StateDocument::empty()->withResource(new StateResource(
            new ResourceAddress(ResourceType::APPLICATION, 'api'), ResourceType::APPLICATION, 'app_secret_identity',
        )));

        $encoded = json_encode($report->diagnostics(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('app_secret_identity', $encoded);
        self::assertStringContainsString('application', $encoded);
    }

    private function inspect(StateDocument $document): \LaravelCloudBlueprint\State\Inspection\StateInspectionReport
    {
        return (new InspectLocalState())->inspect(new LoadedState($document, StateVersion::V2));
    }
}
