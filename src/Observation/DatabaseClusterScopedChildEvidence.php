<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

final readonly class DatabaseClusterScopedChildEvidence
{
    public function __construct(
        public string $id,
        public string $name,
        public string $requestedClusterId,
        public ?string $relationshipClusterId,
    ) {
        if (trim($this->id) === '' || trim($this->requestedClusterId) === '') {
            throw new \InvalidArgumentException('Scoped Database child identities must not be empty.');
        }
    }

    public function parentProof(string $clusterId): DatabaseClusterParentProof
    {
        if ($this->requestedClusterId !== $clusterId
            || ($this->relationshipClusterId !== null && $this->relationshipClusterId !== $clusterId)) {
            return DatabaseClusterParentProof::CONFLICTING;
        }

        return $this->relationshipClusterId === null
            ? DatabaseClusterParentProof::MISSING
            : DatabaseClusterParentProof::EXACT;
    }
}
