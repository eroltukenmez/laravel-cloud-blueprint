<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;

final readonly class DerivedDatabaseObservationEvidence
{
    /** @var list<CloudDatabase> */
    private array $databases;

    /** @var list<CloudDatabase> */
    private array $replacementCandidates;

    /**
     * @param list<CloudDatabase> $databases
     * @param list<string> $relationshipDatabaseIds
     * @param list<CloudDatabase> $replacementCandidates
     */
    public function __construct(
        array $databases,
        public array $relationshipDatabaseIds,
        array $replacementCandidates,
        public EvidenceStatus $listCompleteness,
        public EvidenceStatus $relationshipCompleteness,
        public bool $ownershipConflict = false,
        public bool $blueprintAddressCollision = false,
        public bool $relationshipConflict = false,
    ) {
        $this->databases = $databases;
        $this->replacementCandidates = $replacementCandidates;
    }

    /** @return list<CloudDatabase> */
    public function databases(): array
    {
        return $this->databases;
    }

    /** @return list<CloudDatabase> */
    public function replacementCandidates(): array
    {
        return $this->replacementCandidates;
    }
}
