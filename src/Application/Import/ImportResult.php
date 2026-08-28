<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\Import;

use LaravelCloudBlueprint\State\StateDocument;

final readonly class ImportResult
{
    /** @var list<ImportCandidate> */
    public array $adopted;

    public function __construct(
        public ImportProposal $proposal,
        public StateDocument $state,
        ImportCandidate ...$adopted,
    ) {
        $this->adopted = array_values($adopted);
    }

    public function adoptedCount(): int
    {
        return count($this->adopted);
    }

    public function alreadyManagedCount(): int
    {
        return $this->proposal->countByStatus(ImportStatus::ALREADY_MANAGED);
    }
}
