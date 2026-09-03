<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;

final readonly class EnvironmentParentEvidence
{
    private function __construct(
        public ?ResourceAddress $address,
        public ?string $remoteId,
        public OwnershipStatus $ownership,
        public EvidenceStatus $completeness,
    ) {
        if ($address !== null && $address->type !== ResourceType::APPLICATION) {
            throw new InvalidArgumentException('Environment parent evidence requires an Application address.');
        }
        if (($address === null) !== ($remoteId === null)) {
            throw new InvalidArgumentException('Resolved parent evidence requires both local and remote identity.');
        }
        if ($remoteId !== null && trim($remoteId) === '') {
            throw new InvalidArgumentException('Resolved parent identity must not be empty.');
        }
        if ($remoteId === null && $completeness !== EvidenceStatus::INCOMPLETE) {
            throw new InvalidArgumentException('An unresolved parent requires incomplete evidence.');
        }
    }

    public static function resolved(
        ResourceAddress $address,
        string $remoteId,
        OwnershipStatus $ownership,
    ): self
    {
        return new self($address, $remoteId, $ownership, EvidenceStatus::COMPLETE);
    }

    public static function unresolved(): self
    {
        return new self(null, null, OwnershipStatus::UNKNOWN, EvidenceStatus::INCOMPLETE);
    }

    public function authorizesChildren(): bool
    {
        return $this->ownership === OwnershipStatus::MANAGED;
    }
}
