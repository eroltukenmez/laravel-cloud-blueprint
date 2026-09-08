<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Application\BlueprintLoadResult;
use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReader;

/** Coordinates the read-only State inspection use case outside the Console adapter. */
final readonly class StateInspectionCoordinator
{
    public function __construct(
        private InspectLocalState $local = new InspectLocalState(),
        private CollectStateInspectionCloudEvidence $cloud = new CollectStateInspectionCloudEvidence(),
        private EnrichStateInspectionWithCloud $enrichment = new EnrichStateInspectionWithCloud(),
        private AdviseStateRecovery $recovery = new AdviseStateRecovery(),
    ) {
    }

    public function inspect(
        LoadedState $loaded,
        ?StateInspectionCloudReader $cloud = null,
        ?BlueprintLoadResult $blueprint = null,
        ?string $blueprintPath = null,
    ): StateInspectionResult {
        $report = $this->local->inspect($loaded);
        $evidence = new StateInspectionCloudEvidence(false, [], [], [], []);

        if ($cloud !== null) {
            $evidence = $this->cloud->collect($loaded->document, $cloud);
            $report = $this->enrichment->enrich($report, $loaded->document, $evidence);
        }

        $recovery = $blueprint === null || $blueprintPath === null
            ? new StateRecoveryReport(BlueprintRecoveryStatus::AVAILABLE)
            : $this->recovery->advise($report, $loaded->document, $blueprint, $evidence, $blueprintPath);

        return new StateInspectionResult($report, $evidence, $recovery);
    }
}
