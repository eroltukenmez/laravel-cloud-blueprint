<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;

final readonly class EnvironmentObservationEvidence
{
    /** @var list<CloudEnvironment> */
    private array $environments;

    /** @param list<CloudEnvironment> $environments */
    public function __construct(
        public EnvironmentParentEvidence $parent,
        array $environments,
        public EvidenceStatus $completeness,
        public bool $ownershipConflict = false,
    ) {
        $this->environments = $environments;
    }

    /** @return list<CloudEnvironment> */
    public function environments(): array
    {
        return $this->environments;
    }
}
