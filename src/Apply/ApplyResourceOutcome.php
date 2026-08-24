<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Planning\ResourceAddress;

final readonly class ApplyResourceOutcome
{
    public function __construct(
        public ResourceAddress $address,
        public ApplyOutcomeOperation $operation,
        public ?string $message = null,
    ) {
    }
}
