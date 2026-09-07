<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

final readonly class RecoveryCommandSuggestion
{
    /** @param list<string> $arguments */
    public function __construct(public string $command, public array $arguments)
    {
    }
}
