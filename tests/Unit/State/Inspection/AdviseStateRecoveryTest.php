<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\State\Inspection;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Inspection\AdviseStateRecovery;
use LaravelCloudBlueprint\State\Inspection\BlueprintRecoveryStatus;
use LaravelCloudBlueprint\State\Inspection\DiagnosticEvidenceSource;
use LaravelCloudBlueprint\State\Inspection\DiagnosticSeverity;
use LaravelCloudBlueprint\State\Inspection\RecoveryDisposition;
use LaravelCloudBlueprint\State\Inspection\RecoveryGuidanceKind;
use LaravelCloudBlueprint\State\Inspection\StateDiagnostic;
use LaravelCloudBlueprint\State\Inspection\StateDiagnosticCode;
use LaravelCloudBlueprint\State\Inspection\StateInspectionCloudEvidence;
use LaravelCloudBlueprint\State\Inspection\StateInspectionReport;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;

final class AdviseStateRecoveryTest extends TestCase
{
    public function testDeclaredUnmanagedChildIsSuggestedOnlyWhenImportProposalProvesItImportable(): void
    {
        $state = $this->clusterState();
        $report = $this->unmanagedChildReport('application');
        $advice = (new AdviseStateRecovery())->advise($report, $state, $this->blueprint(true), $this->evidence('application'), 'cloud.yaml');

        $guidance = $advice->guidance()[0];
        self::assertSame(RecoveryDisposition::EXPLICIT_IMPORT_AVAILABLE, $guidance->disposition);
        self::assertSame(RecoveryGuidanceKind::IMPORT, $guidance->kind);
        self::assertSame(['--file=cloud.yaml'], $guidance->commandSuggestion?->arguments);
        self::assertFalse($guidance->restoresDerivedProvenance);
    }

    public function testUndeclaredUnmanagedChildGetsOnlyNonDerivedManualRunbook(): void
    {
        $advice = (new AdviseStateRecovery())->advise($this->unmanagedChildReport('legacy'), $this->clusterState(), $this->blueprint(false), $this->evidence('legacy'), 'cloud.yaml');

        $guidance = $advice->guidance()[0];
        self::assertSame(RecoveryDisposition::MANUAL_ADOPTION_RUNBOOK, $guidance->disposition);
        self::assertSame(RecoveryGuidanceKind::ADOPTION_RUNBOOK, $guidance->kind);
        self::assertFalse($guidance->restoresDerivedProvenance);
        self::assertNull($guidance->commandSuggestion);
    }

    public function testConflictOrIncompleteEvidenceCannotProduceImportGuidance(): void
    {
        $state = $this->clusterState();
        $conflict = (new AdviseStateRecovery())->advise($this->unmanagedChildReport('application'), $state, $this->blueprint(true), $this->evidence('application', false), 'cloud.yaml');
        $incomplete = new StateInspectionReport(new StateDiagnostic(DiagnosticSeverity::WARNING, StateDiagnosticCode::EVIDENCE_INCOMPLETE, DiagnosticEvidenceSource::CLOUD, RecoveryDisposition::UNAVAILABLE, new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary')));
        $none = (new AdviseStateRecovery())->advise($incomplete, $state, $this->blueprint(true), $this->evidence('application'), 'cloud.yaml');

        self::assertSame([], $conflict->guidance());
        self::assertSame([], $none->guidance());
    }

    public function testImportConflictAndInvalidBlueprintRemainDistinguishableWithoutCommands(): void
    {
        $cluster = new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'), ResourceType::DATABASE_CLUSTER, 'cluster-owned');
        $conflictedState = StateDocument::empty()->withResource($cluster)->withResource(new StateResource(
            new ResourceAddress(ResourceType::DATABASE, 'primary.other'), ResourceType::DATABASE, 'database-unmanaged', $cluster->address,
        ));
        $advisor = new AdviseStateRecovery();
        $conflict = $advisor->advise($this->unmanagedChildReport('application'), $conflictedState, $this->blueprint(true), $this->evidence('application'), 'cloud.yaml');
        $invalid = $advisor->advise($this->unmanagedChildReport('application'), $this->clusterState(), (new BlueprintLoader(new SymfonyYamlDecoder(), new BlueprintValidator(), new BlueprintNormalizer()))->load('version: 2'), $this->evidence('application'), 'cloud.yaml');

        self::assertSame([], $conflict->guidance());
        self::assertSame(BlueprintRecoveryStatus::INVALID, $invalid->blueprintStatus);
        self::assertSame([], $invalid->guidance());
    }

    public function testMissingLeafCanSuggestExplicitUnmanageButParentAndDerivedProvenanceAreNeverRepaired(): void
    {
        $application = new StateResource(new ResourceAddress(ResourceType::APPLICATION, 'api'), ResourceType::APPLICATION, 'app-owned');
        $environment = new StateResource(new ResourceAddress(ResourceType::ENVIRONMENT, 'production'), ResourceType::ENVIRONMENT, 'env-owned', $application->address);
        $state = StateDocument::empty()->withResource($application)->withResource($environment);
        $missingLeaf = new StateInspectionReport(new StateDiagnostic(DiagnosticSeverity::ERROR, StateDiagnosticCode::REMOTE_IDENTITY_MISSING, DiagnosticEvidenceSource::CLOUD, RecoveryDisposition::MANUAL_DECISION_REQUIRED, $environment->address));
        $missingParent = new StateInspectionReport(new StateDiagnostic(DiagnosticSeverity::ERROR, StateDiagnosticCode::REMOTE_IDENTITY_MISSING, DiagnosticEvidenceSource::CLOUD, RecoveryDisposition::MANUAL_DECISION_REQUIRED, $application->address));

        $leaf = (new AdviseStateRecovery())->advise($missingLeaf, $state, $this->blueprint(false), $this->evidence('unused'), 'cloud.yaml')->guidance()[0];
        $parent = (new AdviseStateRecovery())->advise($missingParent, $state, $this->blueprint(false), $this->evidence('unused'), 'cloud.yaml');
        self::assertSame(RecoveryGuidanceKind::UNMANAGE, $leaf->kind);
        self::assertSame(['environment.production'], $leaf->commandSuggestion?->arguments);
        self::assertSame([], $parent->guidance());
    }

    public function testDerivedLeafReleaseGuidanceMarksAuthorizationLoss(): void
    {
        $cluster = new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'), ResourceType::DATABASE_CLUSTER, 'cluster-owned');
        $derived = new StateResource(new ResourceAddress(ResourceType::DATABASE, 'primary.default'), ResourceType::DATABASE, 'database-owned', $cluster->address, StateOwnershipClassification::DERIVED, StateProvenance::CLUSTER_CREATE_RESPONSE);
        $state = StateDocument::empty()->withResource($cluster)->withResource($derived);
        $report = new StateInspectionReport(new StateDiagnostic(DiagnosticSeverity::ERROR, StateDiagnosticCode::REMOTE_IDENTITY_MISSING, DiagnosticEvidenceSource::CLOUD, RecoveryDisposition::MANUAL_DECISION_REQUIRED, $derived->address));

        $guidance = (new AdviseStateRecovery())->advise($report, $state, $this->blueprint(false), $this->evidence('unused'), 'cloud.yaml')->guidance()[0];
        self::assertTrue($guidance->losesDerivedAuthorization);
        self::assertFalse($guidance->restoresDerivedProvenance);
    }

    private function clusterState(): StateDocument
    {
        $cluster = new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'), ResourceType::DATABASE_CLUSTER, 'cluster-owned');
        return StateDocument::empty()->withResource($cluster);
    }

    private function unmanagedChildReport(string $name): StateInspectionReport
    {
        return new StateInspectionReport(new StateDiagnostic(DiagnosticSeverity::WARNING, StateDiagnosticCode::UNMANAGED_REMOTE_CHILD,
            DiagnosticEvidenceSource::CLOUD, RecoveryDisposition::MANUAL_DECISION_REQUIRED,
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'), remoteName: $name));
    }

    private function evidence(string $databaseName, bool $complete = true): StateInspectionCloudEvidence
    {
        $cluster = new CloudDatabaseCluster('cluster-owned', 'primary', 'laravel_mysql_8', 'ready', 'eu-central-1', new CloudUnknownDatabaseConfiguration());
        return new StateInspectionCloudEvidence($complete, [new CloudApplication('app-owned', 'api', null, 'eu-central-1', 'acme/api')], [], [$cluster], ['cluster-owned' => [new CloudDatabase('database-unmanaged', 'cluster-owned', $databaseName)]]);
    }

    private function blueprint(bool $declaresDatabase): \LaravelCloudBlueprint\Application\BlueprintLoadResult
    {
        $databases = $declaresDatabase ? "    databases:\n      application: {}\n" : "    databases: {}\n";
        return (new BlueprintLoader(new SymfonyYamlDecoder(), new BlueprintValidator(), new BlueprintNormalizer()))->load("version: 1\norganization: acme\napplication:\n  name: api\n  region: eu-central-1\n  source:\n    provider: github\n    repository: acme/api\ndatabase_clusters:\n  primary:\n    type: laravel_mysql_8\n    region: eu-central-1\n    config:\n      size: db-flex.m-1vcpu-512mb\n      storage: 5\n      retention_days: 1\n      uses_scheduled_snapshots: false\n      is_public: false\n" . $databases . "environments: {}\n");
    }
}
