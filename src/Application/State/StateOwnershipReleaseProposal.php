<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\State;

use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\State\StateResource;

final readonly class StateOwnershipReleaseProposal
{
    /** @var list<StateResource> */
    private array $children;

    public function __construct(
        public ResourceAddress $address,
        public ?StateResource $resource,
        StateResource ...$children,
    ) {
        $this->children = array_values($children);
    }

    public function isManaged(): bool
    {
        return $this->resource !== null;
    }

    public function canRelease(): bool
    {
        return $this->resource !== null && $this->children === [];
    }

    /** @return list<StateResource> */
    public function children(): array
    {
        return $this->children;
    }

    public function matches(self $other): bool
    {
        return $this->resourceSignature($this->resource) === $this->resourceSignature($other->resource)
            && array_map($this->resourceSignature(...), $this->children)
                === array_map($this->resourceSignature(...), $other->children);
    }

    /** @return array{string, string, string, string|null}|null */
    private function resourceSignature(?StateResource $resource): ?array
    {
        if ($resource === null) {
            return null;
        }

        return [
            (string) $resource->address,
            $resource->type->value,
            $resource->remoteId,
            $resource->parent === null ? null : (string) $resource->parent,
        ];
    }
}
