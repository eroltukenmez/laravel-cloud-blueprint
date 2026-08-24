<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Validation;

final readonly class ValidationError
{
    public function __construct(
        public string $path,
        public ValidationErrorCode $code,
        public string $message,
    ) {
    }
}
