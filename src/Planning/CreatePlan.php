<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;

final readonly class CreatePlan
{
    public function __construct(private VariableValueResolver $values)
    {
    }

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

            foreach ($blueprint->environments as $environment) {
                foreach ($environment->variables as $variable) {
                    $address = $this->variableAddress($environment->name, $variable->name);
                    $this->values->resolve($variable, $address);
                    $actions[] = $this->variableAction(
                        $address,
                        PlanOperation::CREATE,
                        'Environment variable does not exist because the environment will be created.',
                    );
                }
            }

            return new ExecutionPlan(...$actions);
        }

        $application = $matches[0];
        $actions = [$this->compareApplication($blueprint, $application)];
        $remoteEnvironments = $cloud->environments($application->id);

        /** @var array<string, CloudEnvironment|null> $matchedEnvironments */
        $matchedEnvironments = [];
        foreach ($blueprint->environments as $environment) {
            [$action, $matched] = $this->compareEnvironment($environment, $remoteEnvironments);
            $actions[] = $action;
            $matchedEnvironments[$environment->name] = $matched;
        }

        foreach ($blueprint->environments as $environment) {
            $remote = $matchedEnvironments[$environment->name];
            if ($remote === null) {
                foreach ($environment->variables as $variable) {
                    $address = $this->variableAddress($environment->name, $variable->name);
                    $this->values->resolve($variable, $address);
                    $actions[] = $this->variableAction(
                        $address,
                        PlanOperation::CREATE,
                        'Environment variable does not exist because the environment will be created.',
                    );
                }
                continue;
            }

            if (count($environment->variables) === 0) {
                continue;
            }

            $details = $cloud->environment($remote->id);
            foreach ($environment->variables as $variable) {
                $actions[] = $this->compareVariable($environment->name, $variable, $details->variables);
            }
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
                'Application repository differs and cannot be updated safely.',
            );
        }

        return $this->applicationAction(
            $blueprint->application->name,
            PlanOperation::NO_CHANGE,
            'Remote application matches desired state.',
            $remote->id,
        );
    }

    /**
     * @param list<CloudEnvironment> $remoteEnvironments
     * @return array{PlanAction, CloudEnvironment|null}
     */
    private function compareEnvironment(
        EnvironmentDefinition $desired,
        array $remoteEnvironments,
    ): array {
        $matches = array_values(array_filter(
            $remoteEnvironments,
            static fn (CloudEnvironment $environment): bool => $environment->name === $desired->name,
        ));

        if (count($matches) > 1) {
            throw new AmbiguousResourceMatchException('environment', $desired->name);
        }

        if ($matches === []) {
            return [$this->environmentAction(
                $desired->name,
                PlanOperation::CREATE,
                'Environment does not exist.',
            ), null];
        }

        $remote = $matches[0];

        if ($remote->branch === null) {
            return [$this->environmentAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote branch information is unavailable.',
                $remote->id,
            ), $remote];
        }

        if ($remote->branch !== $desired->branch) {
            return [$this->environmentAction(
                $desired->name,
                PlanOperation::UPDATE,
                'Remote environment differs from desired state.',
                $remote->id,
                new PlanChange('branch', $remote->branch, $desired->branch),
            ), $remote];
        }

        return [$this->environmentAction(
            $desired->name,
            PlanOperation::NO_CHANGE,
            'Remote environment matches desired state.',
            $remote->id,
        ), $remote];
    }

    private function compareVariable(
        string $environmentName,
        VariableDefinition $desired,
        ?CloudEnvironmentVariableCollection $remoteVariables,
    ): PlanAction {
        $address = $this->variableAddress($environmentName, $desired->name);
        $desiredValue = $this->values->resolve($desired, $address);

        if ($remoteVariables === null) {
            return $this->variableAction(
                $address,
                PlanOperation::UNSUPPORTED,
                'Remote environment variable information is unavailable.',
            );
        }

        $remote = $remoteVariables->find($desired->name);
        if ($remote === null) {
            return $this->variableAction(
                $address,
                PlanOperation::CREATE,
                'Environment variable does not exist.',
            );
        }

        if ($remote->value !== $desiredValue) {
            return $this->variableAction(
                $address,
                PlanOperation::UPDATE,
                'Environment variable differs from desired state.',
            );
        }

        return $this->variableAction(
            $address,
            PlanOperation::NO_CHANGE,
            'Environment variable matches desired state.',
        );
    }

    private function applicationAction(
        string $name,
        PlanOperation $operation,
        string $reason,
        ?string $remoteId = null,
        PlanChange ...$changes,
    ): PlanAction
    {
        return new PlanAction(
            new ResourceAddress(ResourceType::APPLICATION, $name),
            ResourceType::APPLICATION,
            $operation,
            $reason,
            $remoteId,
            ...$changes,
        );
    }

    private function environmentAction(
        string $name,
        PlanOperation $operation,
        string $reason,
        ?string $remoteId = null,
        PlanChange ...$changes,
    ): PlanAction
    {
        return new PlanAction(
            new ResourceAddress(ResourceType::ENVIRONMENT, $name),
            ResourceType::ENVIRONMENT,
            $operation,
            $reason,
            $remoteId,
            ...$changes,
        );
    }

    private function variableAddress(string $environmentName, string $variableName): ResourceAddress
    {
        return new ResourceAddress(ResourceType::VARIABLE, $environmentName . '.' . $variableName);
    }

    private function variableAction(
        ResourceAddress $address,
        PlanOperation $operation,
        string $reason,
    ): PlanAction {
        return new PlanAction($address, ResourceType::VARIABLE, $operation, $reason);
    }
}
