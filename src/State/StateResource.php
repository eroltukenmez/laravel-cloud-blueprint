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
        if (!in_array($type, [
            ResourceType::APPLICATION,
            ResourceType::ENVIRONMENT,
            ResourceType::DATABASE_CLUSTER,
            ResourceType::DATABASE,
        ], true)) {
            throw new InvalidArgumentException('This resource type cannot be persisted in state.');
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

        if (($type === ResourceType::APPLICATION || $type === ResourceType::DATABASE_CLUSTER) && $parent !== null) {
            throw new InvalidArgumentException('Top-level state resources must not have a parent.');
        }
        if ($type === ResourceType::ENVIRONMENT
            && ($parent === null || $parent->type !== ResourceType::APPLICATION)) {
            throw new InvalidArgumentException('Environment state resources require an Application parent.');
        }
        if ($type === ResourceType::DATABASE
            && ($parent === null || $parent->type !== ResourceType::DATABASE_CLUSTER)) {
            throw new InvalidArgumentException('Logical Database state resources require a Database Cluster parent.');
        }
    }
}
