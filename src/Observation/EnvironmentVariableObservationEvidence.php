<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;

final readonly class EnvironmentVariableObservationEvidence
{
    private function __construct(
        public EnvironmentVariableEvidenceStatus $status,
        private ?CloudEnvironmentVariableCollection $variables,
    ) {
        if (($status === EnvironmentVariableEvidenceStatus::AVAILABLE) !== ($variables !== null)) {
            throw new InvalidArgumentException('Available variable evidence requires a variable collection.');
        }
    }

    public static function available(CloudEnvironmentVariableCollection $variables): self
    {
        return new self(EnvironmentVariableEvidenceStatus::AVAILABLE, $variables);
    }

    public static function collectionUnavailable(): self
    {
        return new self(EnvironmentVariableEvidenceStatus::COLLECTION_UNAVAILABLE, null);
    }

    public static function parentUnresolved(): self
    {
        return new self(EnvironmentVariableEvidenceStatus::PARENT_UNRESOLVED, null);
    }

    public function variables(): ?CloudEnvironmentVariableCollection
    {
        return $this->variables;
    }
}
