<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State;

use InvalidArgumentException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use OutOfBoundsException;

final readonly class StateDocument
{
    /** @var array<string, StateResource> */
    private array $resources;

    public function __construct(
        public StateVersion $version,
        public int $serial,
        public ?string $organization,
        StateResource ...$resources,
    ) {
        if ($serial < 0) {
            throw new InvalidArgumentException('State serial must not be negative.');
        }

        $indexed = [];
        foreach ($resources as $resource) {
            $address = (string) $resource->address;
            if (isset($indexed[$address])) {
                throw new InvalidArgumentException(sprintf('Duplicate state resource "%s".', $address));
            }
            $indexed[$address] = $resource;
        }
        /** @var array<string, array{string, ResourceType}> $remoteIds */
        $remoteIds = [];
        foreach ($indexed as $address => $resource) {
            $previous = $remoteIds[$resource->remoteId] ?? null;
            if ($previous !== null
                && ($resource->type === ResourceType::DATABASE_CLUSTER
                    || $resource->type === ResourceType::DATABASE
                    || $previous[1] === ResourceType::DATABASE_CLUSTER
                    || $previous[1] === ResourceType::DATABASE)) {
                throw new InvalidArgumentException(sprintf(
                    'Remote identity is owned by both "%s" and "%s".',
                    $previous[0],
                    $address,
                ));
            }
            $remoteIds[$resource->remoteId] = [$address, $resource->type];
            if (($resource->type === ResourceType::ENVIRONMENT || $resource->type === ResourceType::DATABASE)
                && $resource->parent !== null
                && !isset($indexed[(string) $resource->parent])) {
                throw new InvalidArgumentException(sprintf(
                    'State resource "%s" has an unowned parent.',
                    $address,
                ));
            }
        }
        ksort($indexed, SORT_STRING);
        $this->resources = $indexed;
    }

    public static function empty(): self
    {
        return new self(StateVersion::V1, 0, null);
    }

    public function find(ResourceAddress $address): ?StateResource
    {
        return $this->resources[(string) $address] ?? null;
    }

    public function get(ResourceAddress $address): StateResource
    {
        return $this->find($address)
            ?? throw new OutOfBoundsException(sprintf('State resource "%s" does not exist.', (string) $address));
    }

    public function withResource(StateResource $resource): self
    {
        $resources = $this->resources;
        $resources[(string) $resource->address] = $resource;

        return new self($this->version, $this->serial, $this->organization, ...array_values($resources));
    }

    public function withOrganization(string $organization): self
    {
        if (trim($organization) === '') {
            throw new InvalidArgumentException('State organization must not be empty.');
        }

        return new self($this->version, $this->serial, $organization, ...array_values($this->resources));
    }

    public function withSerial(int $serial): self
    {
        return new self($this->version, $serial, $this->organization, ...array_values($this->resources));
    }

    /** @return list<StateResource> */
    public function resources(): array
    {
        return array_values($this->resources);
    }

    public function materiallyEquals(self $other): bool
    {
        if ($this->organization !== $other->organization) {
            return false;
        }

        if (array_keys($this->resources) !== array_keys($other->resources)) {
            return false;
        }

        foreach ($this->resources as $address => $resource) {
            $candidate = $other->resources[$address];
            if ($resource->type !== $candidate->type
                || $resource->remoteId !== $candidate->remoteId
                || ($resource->parent === null ? null : (string) $resource->parent)
                    !== ($candidate->parent === null ? null : (string) $candidate->parent)) {
                return false;
            }
        }

        return true;
    }
}
