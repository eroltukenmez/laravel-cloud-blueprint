<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;

final readonly class CreatePlan
{
    public function create(Blueprint $blueprint, LaravelCloudClient $cloud): ExecutionPlan
    {
        $organization = $cloud->organization();

        if ($organization->slug !== $blueprint->organization) {
            throw new OrganizationMismatchException($blueprint->organization, $organization->slug);
        }

        $matches = array_values(array_filter(
            $cloud->applications(),
            static fn (CloudApplication $application): bool => $application->name === $blueprint->application->name,
        ));

        if (count($matches) > 1) {
            throw new AmbiguousResourceMatchException('application', $blueprint->application->name);
        }

        if ($matches === []) {
            $actions = [$this->applicationAction(
                $blueprint->application->name,
                PlanOperation::CREATE,
                'Application does not exist.',
            )];

            foreach ($blueprint->environments as $environment) {
                $actions[] = $this->environmentAction(
                    $environment->name,
                    PlanOperation::CREATE,
                    'Environment does not exist because the application will be created.',
                );
            }

            return new ExecutionPlan(...$actions);
        }

        $application = $matches[0];
        $actions = [$this->compareApplication($blueprint, $application)];
        $remoteEnvironments = $cloud->environments($application->id);

        foreach ($blueprint->environments as $environment) {
            $actions[] = $this->compareEnvironment($environment, $remoteEnvironments);
        }

        return new ExecutionPlan(...$actions);
    }

    private function compareApplication(Blueprint $blueprint, CloudApplication $remote): PlanAction
    {
        if ($remote->region !== $blueprint->application->region) {
            return $this->applicationAction(
                $blueprint->application->name,
                PlanOperation::UNSUPPORTED,
                'Remote application region differs from desired region.',
            );
        }

        if ($remote->repository === null) {
            return $this->applicationAction(
                $blueprint->application->name,
                PlanOperation::UNSUPPORTED,
                'Remote application repository information is unavailable.',
            );
        }

        if ($remote->repository !== $blueprint->application->source->repository) {
            return $this->applicationAction(
                $blueprint->application->name,
                PlanOperation::UNSUPPORTED,
                'Remote application repository differs from desired repository.',
            );
        }

        return $this->applicationAction(
            $blueprint->application->name,
            PlanOperation::NO_CHANGE,
            'Remote application matches desired state.',
        );
    }

    /** @param list<CloudEnvironment> $remoteEnvironments */
    private function compareEnvironment(
        EnvironmentDefinition $desired,
        array $remoteEnvironments,
    ): PlanAction {
        $matches = array_values(array_filter(
            $remoteEnvironments,
            static fn (CloudEnvironment $environment): bool => $environment->name === $desired->name,
        ));

        if (count($matches) > 1) {
            throw new AmbiguousResourceMatchException('environment', $desired->name);
        }

        if ($matches === []) {
            return $this->environmentAction(
                $desired->name,
                PlanOperation::CREATE,
                'Environment does not exist.',
            );
        }

        $remote = $matches[0];

        if ($remote->branch === null) {
            return $this->environmentAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote branch information is unavailable.',
            );
        }

        if ($remote->branch !== $desired->branch) {
            return $this->environmentAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote branch differs from desired branch.',
            );
        }

        return $this->environmentAction(
            $desired->name,
            PlanOperation::NO_CHANGE,
            'Remote environment matches desired state.',
        );
    }

    private function applicationAction(string $name, PlanOperation $operation, string $reason): PlanAction
    {
        return new PlanAction(
            new ResourceAddress(ResourceType::APPLICATION, $name),
            ResourceType::APPLICATION,
            $operation,
            $reason,
        );
    }

    private function environmentAction(string $name, PlanOperation $operation, string $reason): PlanAction
    {
        return new PlanAction(
            new ResourceAddress(ResourceType::ENVIRONMENT, $name),
            ResourceType::ENVIRONMENT,
            $operation,
            $reason,
        );
    }
}
