<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Environment;

use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;

final readonly class NativeEnvironmentValueProvider implements EnvironmentValueProvider
{
    public function value(string $name): ?string
    {
        $value = getenv($name);
        return is_string($value) ? $value : null;
    }
}
