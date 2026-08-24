<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\Validation\ValidationResult;
use LogicException;

final readonly class BlueprintLoadResult
{
    private function __construct(
        public ValidationResult $validation,
        private ?Blueprint $blueprint,
    ) {
    }

    public static function valid(Blueprint $blueprint, ValidationResult $validation): self
    {
        return new self($validation, $blueprint);
    }

    public static function invalid(ValidationResult $validation): self
    {
        return new self($validation, null);
    }

    public function isValid(): bool
    {
        return $this->validation->isValid();
    }

    public function blueprint(): Blueprint
    {
        return $this->blueprint
            ?? throw new LogicException('An invalid blueprint load result has no blueprint.');
    }
}
