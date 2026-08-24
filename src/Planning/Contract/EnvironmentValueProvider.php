<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning\Contract;

interface EnvironmentValueProvider
{
    public function value(string $name): ?string;
}
