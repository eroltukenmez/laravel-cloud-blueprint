<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;

final readonly class ApplicationObservationEvidence
{
    /** @var list<CloudApplication> */
    private array $applications;

    /** @param list<CloudApplication> $applications */
    public function __construct(
        array $applications,
        public EvidenceStatus $completeness,
        public bool $ownershipConflict = false,
    ) {
        $this->applications = $applications;
    }

    /** @return list<CloudApplication> */
    public function applications(): array
    {
        return $this->applications;
    }
}
