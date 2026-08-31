<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\State;

use LaravelCloudBlueprint\State\StateDocument;

final readonly class StateOwnershipReleaseResult
{
    public function __construct(
        public StateOwnershipReleaseProposal $proposal,
        public StateDocument $state,
    ) {
    }
}
