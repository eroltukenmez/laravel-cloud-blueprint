<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State;

use InvalidArgumentException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;

final readonly class StateResource
{
    public function __construct(
        public ResourceAddress $address,
        public ResourceType $type,
        public string $remoteId,
        public ?ResourceAddress $parent = null,
    ) {
        if (!in_array($type, [ResourceType::APPLICATION, ResourceType::ENVIRONMENT], true)) {
            throw new InvalidArgumentException('Only Application and Environment resources may be persisted in state.');
        }

        if ($address->type !== $type) {
            throw new InvalidArgumentException('State resource type must match its address type.');
        }

        if (trim($remoteId) === '') {
            throw new InvalidArgumentException('State resource remote ID must not be empty.');
        }

        if ($parent !== null && (string) $parent === (string) $address) {
            throw new InvalidArgumentException('A state resource cannot be its own parent.');
        }
    }
}
