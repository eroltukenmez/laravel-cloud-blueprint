<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Normalization;

use RuntimeException;

final class BlueprintNormalizationException extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        string $reason,
    ) {
        parent::__construct(sprintf('%s: %s', $path, $reason));
    }
}
