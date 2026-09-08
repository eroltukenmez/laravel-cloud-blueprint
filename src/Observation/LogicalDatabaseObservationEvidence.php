<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;

final readonly class LogicalDatabaseObservationEvidence
{
    /** @var list<CloudDatabase> */
    private array $databases;

    /** @param list<CloudDatabase> $databases */
    public function __construct(
        public DatabaseParentEvidence $parent,
        array $databases,
        public EvidenceStatus $completeness,
        public bool $ownershipConflict = false,
        public bool $observeRelationships = true,
        public ?DatabaseClusterTopologyEvidence $topology = null,
    ) {
        $this->databases = $databases;
    }

    /** @return list<CloudDatabase> */
    public function databases(): array
    {
        return $this->databases;
    }
}
