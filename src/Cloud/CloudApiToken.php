<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud;

use InvalidArgumentException;

final readonly class CloudApiToken
{
    public function __construct(private string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Laravel Cloud API token must not be empty.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
