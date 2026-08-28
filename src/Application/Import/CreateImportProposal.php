<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\Import;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;

final readonly class CreateImportProposal
{
    /**
     * @param list<CloudApplication> $applications
     * @param list<CloudEnvironment> $environments
     */
    public function create(
        Blueprint $blueprint,
        StateDocument $state,
        array $applications,
        array $environments,
    ): ImportProposal {
        $applicationAddress = new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name);
        $applicationMatches = array_values(array_filter(
            $applications,
            static fn (CloudApplication $application): bool => $application->name === $blueprint->application->name,
        ));
        $application = count($applicationMatches) === 1 ? $applicationMatches[0] : null;
        $applicationCandidate = $this->applicationCandidate(
            $applicationAddress,
            $blueprint->application->name,
            $applicationMatches,
            $state,
        );

        $candidates = [$applicationCandidate];
        $desiredEnvironments = iterator_to_array($blueprint->environments, false);
        usort(
            $desiredEnvironments,
            static fn ($left, $right): int => strcmp($left->name, $right->name),
        );

        foreach ($desiredEnvironments as $desired) {
            $address = new ResourceAddress(ResourceType::ENVIRONMENT, $desired->name);
            if ($applicationCandidate->status === ImportStatus::CONFLICT) {
                $candidates[] = new ImportCandidate(
                    $address,
                    ResourceType::ENVIRONMENT,
                    null,
                    $desired->name,
                    $applicationAddress,
                    ImportStatus::CONFLICT,
                    'Parent application identity is conflicted.',
                );
                continue;
            }
            if ($application === null) {
                $candidates[] = new ImportCandidate(
                    $address,
                    ResourceType::ENVIRONMENT,
                    null,
                    $desired->name,
                    $applicationAddress,
                    ImportStatus::UNSUPPORTED,
                    'Parent application identity cannot be resolved safely.',
                );
                continue;
            }

            $named = array_values(array_filter(
                $environments,
                static fn (CloudEnvironment $environment): bool => $environment->name === $desired->name,
            ));
            $scoped = array_values(array_filter(
                $named,
                static fn (CloudEnvironment $environment): bool => $environment->applicationId === $application->id,
            ));
            $candidates[] = $this->environmentCandidate(
                $address,
                $applicationAddress,
                $desired->name,
                $named,
                $scoped,
                $state,
            );
        }

        return new ImportProposal(...$candidates);
    }

    /** @param list<CloudApplication> $matches */
    private function applicationCandidate(
        ResourceAddress $address,
        string $name,
        array $matches,
        StateDocument $state,
    ): ImportCandidate {
        if (count($matches) > 1) {
            return $this->candidate($address, ResourceType::APPLICATION, null, $name, null, ImportStatus::CONFLICT,
                'Multiple remote applications match this address.');
        }
        if ($matches === []) {
            return $this->candidate($address, ResourceType::APPLICATION, null, $name, null, ImportStatus::UNSUPPORTED,
                'No remote application matches this address.');
        }

        return $this->candidateForIdentity($address, ResourceType::APPLICATION, $matches[0]->id, $matches[0]->name, null, $state);
    }

    /**
     * @param list<CloudEnvironment> $named
     * @param list<CloudEnvironment> $scoped
     */
    private function environmentCandidate(
        ResourceAddress $address,
        ResourceAddress $parent,
        string $name,
        array $named,
        array $scoped,
        StateDocument $state,
    ): ImportCandidate {
        if (count($scoped) > 1) {
            return $this->candidate($address, ResourceType::ENVIRONMENT, null, $name, $parent, ImportStatus::CONFLICT,
                'Multiple remote environments match this address under the parent application.');
        }
        if ($scoped === [] && $named !== []) {
            return $this->candidate($address, ResourceType::ENVIRONMENT, null, $name, $parent, ImportStatus::CONFLICT,
                'A matching remote environment belongs to a different application.');
        }
        if ($scoped === []) {
            return $this->candidate($address, ResourceType::ENVIRONMENT, null, $name, $parent, ImportStatus::UNSUPPORTED,
                'No remote environment matches this address under the parent application.');
        }

        return $this->candidateForIdentity(
            $address,
            ResourceType::ENVIRONMENT,
            $scoped[0]->id,
            $scoped[0]->name,
            $parent,
            $state,
        );
    }

    private function candidateForIdentity(
        ResourceAddress $address,
        ResourceType $type,
        string $remoteId,
        string $remoteName,
        ?ResourceAddress $parent,
        StateDocument $state,
    ): ImportCandidate {
        $managed = $state->find($address);
        if ($managed !== null) {
            if ($this->sameIdentity($managed, $type, $remoteId, $parent)) {
                return $this->candidate($address, $type, $remoteId, $remoteName, $parent, ImportStatus::ALREADY_MANAGED,
                    'The same remote identity is already managed at this address.');
            }

            return $this->candidate($address, $type, $remoteId, $remoteName, $parent, ImportStatus::CONFLICT,
                'The logical address is already managed with a different or incompatible identity.');
        }

        foreach ($state->resources() as $resource) {
            if ($resource->remoteId === $remoteId) {
                return $this->candidate($address, $type, $remoteId, $remoteName, $parent, ImportStatus::CONFLICT,
                    sprintf('The remote identity is already managed by "%s".', (string) $resource->address));
            }
        }

        return $this->candidate($address, $type, $remoteId, $remoteName, $parent, ImportStatus::IMPORTABLE,
            'The remote identity can be adopted into local state.');
    }

    private function sameIdentity(
        StateResource $managed,
        ResourceType $type,
        string $remoteId,
        ?ResourceAddress $parent,
    ): bool {
        return $managed->type === $type
            && $managed->remoteId === $remoteId
            && ($managed->parent === null ? null : (string) $managed->parent)
                === ($parent === null ? null : (string) $parent);
    }

    private function candidate(
        ResourceAddress $address,
        ResourceType $type,
        ?string $remoteId,
        string $remoteName,
        ?ResourceAddress $parent,
        ImportStatus $status,
        string $reason,
    ): ImportCandidate {
        return new ImportCandidate($address, $type, $remoteId, $remoteName, $parent, $status, $reason);
    }
}
