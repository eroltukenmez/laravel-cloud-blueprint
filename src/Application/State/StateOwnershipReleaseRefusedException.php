<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\State;

use RuntimeException;

final class StateOwnershipReleaseRefusedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly StateOwnershipReleaseProposal $proposal,
    ) {
        parent::__construct($message);
    }
}
