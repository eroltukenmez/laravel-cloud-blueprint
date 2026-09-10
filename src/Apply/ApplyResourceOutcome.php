<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use InvalidArgumentException;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use LaravelCloudBlueprint\Planning\ResourceAddress;

final readonly class ApplyResourceOutcome
{
    public function __construct(
        public ResourceAddress $address,
        public ApplyOutcomeOperation $operation,
        public ApplyOutcome $outcome,
        public ?string $message = null,
        public ?CloudValidationException $validation = null,
        public ?bool $deleted = null,
        public ?bool $confirmed = null,
        public ?bool $stateCheckpointed = null,
    ) {
        $valid = match ($operation) {
            ApplyOutcomeOperation::CREATED => $outcome === ApplyOutcome::CREATED,
            ApplyOutcomeOperation::UPDATED => $outcome === ApplyOutcome::UPDATED,
            ApplyOutcomeOperation::UNCHANGED => $outcome === ApplyOutcome::UNCHANGED,
            ApplyOutcomeOperation::DELETED => $outcome === ApplyOutcome::DELETE_CONFIRMED
                || $outcome === ApplyOutcome::ALREADY_ABSENT,
            ApplyOutcomeOperation::FAILED => $outcome->isFailure(),
        };

        if (!$valid) {
            throw new InvalidArgumentException(sprintf(
                'Apply operation "%s" cannot use outcome "%s".',
                $operation->value,
                $outcome->value,
            ));
        }

    }
}
