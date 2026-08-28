<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\Import;

use InvalidArgumentException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;

final readonly class ImportCandidate
{
    public function __construct(
        public ResourceAddress $address,
        public ResourceType $type,
        public ?string $remoteId,
        public string $remoteName,
        public ?ResourceAddress $parent,
        public ImportStatus $status,
        public string $reason,
    ) {
        if ($address->type !== $type) {
            throw new InvalidArgumentException('Import candidate type must match its address type.');
        }

        if ($type === ResourceType::VARIABLE) {
            throw new InvalidArgumentException('Environment variables cannot be imported into state.');
        }

        if ($status === ImportStatus::IMPORTABLE && ($remoteId === null || trim($remoteId) === '')) {
            throw new InvalidArgumentException('An importable candidate must have a remote ID.');
        }

        if (trim($remoteName) === '') {
            throw new InvalidArgumentException('Import candidate remote name must not be empty.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Import candidate reason must not be empty.');
        }
    }
}
