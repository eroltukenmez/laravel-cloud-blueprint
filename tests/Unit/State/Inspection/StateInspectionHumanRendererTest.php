<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\State\Inspection;

use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Inspection\BlueprintRecoveryStatus;
use LaravelCloudBlueprint\State\Inspection\DiagnosticEvidenceSource;
use LaravelCloudBlueprint\State\Inspection\DiagnosticSeverity;
use LaravelCloudBlueprint\State\Inspection\RecoveryCommandSuggestion;
use LaravelCloudBlueprint\State\Inspection\RecoveryDisposition;
use LaravelCloudBlueprint\State\Inspection\RecoveryGuidanceKind;
use LaravelCloudBlueprint\State\Inspection\StateDiagnostic;
use LaravelCloudBlueprint\State\Inspection\StateDiagnosticCode;
use LaravelCloudBlueprint\State\Inspection\StateInspectionHumanRenderer;
use LaravelCloudBlueprint\State\Inspection\StateInspectionReport;
use LaravelCloudBlueprint\State\Inspection\StateRecoveryGuidance;
use LaravelCloudBlueprint\State\Inspection\StateRecoveryReport;
use LaravelCloudBlueprint\State\Inspection\LoadedState;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateVersion;
use PHPUnit\Framework\TestCase;

final class StateInspectionHumanRendererTest extends TestCase
{
    public function testManualAdoptionRunbookExplainsSafeRecoveryWithoutAnExecutableCommand(): void
    {
        $output = $this->render(new StateRecoveryGuidance(
            new ResourceAddress(ResourceType::DATABASE, 'primary.legacy'),
            RecoveryDisposition::MANUAL_ADOPTION_RUNBOOK,
            RecoveryGuidanceKind::ADOPTION_RUNBOOK,
            false,
            remoteName: 'legacy',
        ));

        self::assertStringContainsString('Temporarily declare database.primary.legacy as an ordinary Database in the Blueprint.', $output);
        self::assertStringContainsString('Review and run the normal explicit import workflow after declaration.', $output);
        self::assertStringContainsString('If adoption succeeds, LCB ownership becomes MANAGED.', $output);
        self::assertStringContainsString('Historical origin cannot be reconstructed from current Cloud topology.', $output);
        self::assertStringContainsString('Import does not restore DERIVED / CLUSTER_CREATE_RESPONSE provenance.', $output);
        self::assertStringContainsString('remove the temporary declaration, review the resulting plan, and use the normal guarded apply workflow.', $output);
        self::assertStringNotContainsString('--auto-approve', $output);
        self::assertStringNotContainsString('becomes DERIVED', $output);
        self::assertStringNotContainsString('automatic repair', $output);
        self::assertStringNotContainsString('force-delete', $output);
        self::assertStringNotContainsString('lcb import', $output);
    }

    public function testExplicitImportGuidanceRemainsDistinctAndSafe(): void
    {
        $output = $this->render(new StateRecoveryGuidance(
            new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
            RecoveryDisposition::EXPLICIT_IMPORT_AVAILABLE,
            RecoveryGuidanceKind::IMPORT,
            false,
            new RecoveryCommandSuggestion('lcb import', ['--file=cloud.yaml']),
        ));

        self::assertStringContainsString('lcb import --file=cloud.yaml', $output);
        self::assertStringContainsString('Successful import records the resource as MANAGED.', $output);
        self::assertStringContainsString('Import does not restore DERIVED / CLUSTER_CREATE_RESPONSE provenance.', $output);
        self::assertStringNotContainsString('--auto-approve', $output);
        self::assertStringNotContainsString('Temporarily declare', $output);
    }

    private function render(StateRecoveryGuidance $guidance): string
    {
        $address = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $diagnostic = new StateDiagnostic(
            DiagnosticSeverity::WARNING,
            StateDiagnosticCode::UNMANAGED_REMOTE_CHILD,
            DiagnosticEvidenceSource::CLOUD,
            RecoveryDisposition::MANUAL_DECISION_REQUIRED,
            $address,
            remoteName: $guidance->remoteName,
        );

        return (new StateInspectionHumanRenderer())->render(
            'cloud.yaml',
            new LoadedState(new StateDocument(StateVersion::CURRENT, 1, 'acme'), StateVersion::CURRENT),
            new StateInspectionReport($diagnostic),
            true,
            true,
            new StateRecoveryReport(BlueprintRecoveryStatus::AVAILABLE, $guidance),
            null,
        );
    }
}
