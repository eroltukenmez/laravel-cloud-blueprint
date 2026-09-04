<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class UpdateEnvironmentDatabaseAttachmentRequest
{
    public function __construct(public ?string $databaseId)
    {
        if ($databaseId !== null && trim($databaseId) === '') {
            throw new \InvalidArgumentException('A Database attachment ID must not be empty.');
        }
    }
}
