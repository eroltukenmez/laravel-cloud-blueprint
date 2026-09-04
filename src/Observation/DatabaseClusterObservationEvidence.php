<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

final readonly class DatabaseClusterObservationEvidence
{
    /** @var list<DatabaseClusterCandidate> */
    private array $candidates;

    /** @param list<DatabaseClusterCandidate> $candidates */
    public function __construct(
        array $candidates,
        public EvidenceStatus $completeness,
        public bool $ownershipConflict = false,
    ) {
        $this->candidates = $candidates;
    }

    /** @return list<DatabaseClusterCandidate> */
    public function candidates(): array
    {
        return $this->candidates;
    }
}
