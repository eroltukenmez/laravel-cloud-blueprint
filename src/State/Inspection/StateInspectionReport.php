<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

final readonly class StateInspectionReport
{
    /** @var list<StateDiagnostic> */
    private array $diagnostics;

    public function __construct(StateDiagnostic ...$diagnostics)
    {
        usort($diagnostics, static function (StateDiagnostic $left, StateDiagnostic $right): int {
            $address = ($left->address === null ? '' : (string) $left->address)
                <=> ($right->address === null ? '' : (string) $right->address);

            return $address !== 0 ? $address : $left->code->value <=> $right->code->value;
        });

        $this->diagnostics = $diagnostics;
    }

    /** @return list<StateDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
