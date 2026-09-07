<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Planning\ResourceAddress;

final readonly class StateRecoveryGuidance
{
    public function __construct(
        public ResourceAddress $subjectAddress,
        public RecoveryDisposition $disposition,
        public RecoveryGuidanceKind $kind,
        public bool $restoresDerivedProvenance,
        public ?RecoveryCommandSuggestion $commandSuggestion = null,
        public ?string $remoteName = null,
        public bool $losesDerivedAuthorization = false,
    ) {
    }
}
