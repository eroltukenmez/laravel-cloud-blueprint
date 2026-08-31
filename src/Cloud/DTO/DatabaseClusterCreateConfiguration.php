<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

interface DatabaseClusterCreateConfiguration
{
    /** @return array<string, string|int|float|bool> */
    public function payload(): array;
}
