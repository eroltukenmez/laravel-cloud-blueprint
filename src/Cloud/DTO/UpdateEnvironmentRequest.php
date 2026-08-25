<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class UpdateEnvironmentRequest
{
    public function __construct(public string $branch)
    {
    }
}
