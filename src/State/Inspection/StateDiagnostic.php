<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;

final readonly class StateDiagnostic
{
    public function __construct(
        public DiagnosticSeverity $severity,
        public StateDiagnosticCode $code,
        public DiagnosticEvidenceSource $evidenceSource,
        public RecoveryDisposition $recoveryDisposition,
        public ?ResourceAddress $address = null,
        public ?StateOwnershipClassification $classification = null,
        public ?StateProvenance $provenance = null,
    ) {
    }
}
