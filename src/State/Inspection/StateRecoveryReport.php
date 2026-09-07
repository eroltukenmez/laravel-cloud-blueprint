<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

final readonly class StateRecoveryReport
{
    /** @var list<StateRecoveryGuidance> */
    private array $guidance;

    public function __construct(public BlueprintRecoveryStatus $blueprintStatus, StateRecoveryGuidance ...$guidance)
    {
        usort($guidance, static fn (StateRecoveryGuidance $left, StateRecoveryGuidance $right): int =>
            ((string) $left->subjectAddress <=> (string) $right->subjectAddress)
            ?: ($left->kind->value <=> $right->kind->value));
        $this->guidance = $guidance;
    }

    /** @return list<StateRecoveryGuidance> */
    public function guidance(): array
    {
        return $this->guidance;
    }
}
