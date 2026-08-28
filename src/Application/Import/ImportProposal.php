<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\Import;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, ImportCandidate> */
final readonly class ImportProposal implements Countable, IteratorAggregate
{
    /** @var list<ImportCandidate> */
    private array $candidates;

    public function __construct(ImportCandidate ...$candidates)
    {
        $this->candidates = array_values($candidates);
    }

    public function count(): int
    {
        return count($this->candidates);
    }

    public function countByStatus(ImportStatus $status): int
    {
        return count(array_filter(
            $this->candidates,
            static fn (ImportCandidate $candidate): bool => $candidate->status === $status,
        ));
    }

    public function getIterator(): Traversable
    {
        yield from $this->candidates;
    }
}
