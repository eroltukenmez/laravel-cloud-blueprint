<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\NeonPostgresConfiguration;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDestructiveReadiness;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;

final readonly class CreatePlan
{
    public function __construct(
        private VariableValueResolver $values,
        private DatabaseClusterStatusPolicy $databaseStatuses = new DatabaseClusterStatusPolicy(),
    ) {
    }

    public function create(Blueprint $blueprint, LaravelCloudClient $cloud, StateDocument $state): ExecutionPlan
    {
        $base = $this->createBasePlan($blueprint, $cloud, $state);
        if (count($blueprint->databaseClusters) === 0 && !$this->hasOwnedDatabaseResources($state)) {
            return $base;
        }
        if (!$cloud instanceof LaravelCloudDatabaseClient) {
            throw new CloudResponseException(
                'The configured Laravel Cloud client does not support Database discovery.',
                'GET',
                '/databases/clusters',
            );
        }

        return $this->withDatabaseActions($base, $blueprint, $cloud, $state);
    }

    private function hasOwnedDatabaseResources(StateDocument $state): bool
    {
        foreach ($state->resources() as $resource) {
            if ($resource->type === ResourceType::DATABASE_CLUSTER || $resource->type === ResourceType::DATABASE) {
                return true;
            }
        }
        return false;
    }

    private function createBasePlan(Blueprint $blueprint, LaravelCloudClient $cloud, StateDocument $state): ExecutionPlan
    {
        $organization = $cloud->organization();
        if ($organization->slug !== $blueprint->organization) {
            throw new OrganizationMismatchException($blueprint->organization, $organization->slug);
        }

        if ($state->organization !== null && $state->organization !== $blueprint->organization) {
            return $this->blockedPlan($blueprint, 'Local state belongs to a different organization.');
        }

        $applications = $cloud->applications();
        $applicationAddress = new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name);
        $managedApplication = $state->find($applicationAddress);

        if ($managedApplication !== null) {
            $invalid = $this->invalidApplicationOwnership($managedApplication, $state);
            if ($invalid !== null) {
                return $this->withOwnedOnlyResources(
                    $this->blockedPlan($blueprint, $invalid),
                    $blueprint,
                    $cloud,
                    $state,
                    $applications,
                );
            }

            $application = $this->findApplicationById($applications, $managedApplication->remoteId);
            if ($application === null) {
                $replacement = $this->applicationsNamed($applications, $blueprint->application->name);
                return $this->withOwnedOnlyResources(
                    $this->blockedPlan(
                        $blueprint,
                        $replacement === []
                            ? 'Managed application remote identity is missing. State must be repaired before reconciliation.'
                            : 'Managed application remote identity is missing and a same-name unmanaged replacement exists. Import or state repair is required.',
                    ),
                    $blueprint,
                    $cloud,
                    $state,
                    $applications,
                );
            }
            if ($application->name !== $blueprint->application->name) {
                return $this->withOwnedOnlyResources(
                    $this->blockedPlan(
                        $blueprint,
                        'Managed application name differs from its blueprint address. Rename or state-move reconciliation is not supported.',
                    ),
                    $blueprint,
                    $cloud,
                    $state,
                    $applications,
                );
            }

            $desiredPlan = $this->planForApplication($blueprint, $cloud, $state, $applications, $application, true);

            return $this->withOwnedOnlyResources($desiredPlan, $blueprint, $cloud, $state, $applications);
        }

        $matches = $this->applicationsNamed($applications, $blueprint->application->name);
        if (count($matches) > 1) {
            throw new AmbiguousResourceMatchException('application', $blueprint->application->name);
        }
        if ($matches === []) {
            return $this->withOwnedOnlyResources(
                $this->createAllPlan($blueprint),
                $blueprint,
                $cloud,
                $state,
                $applications,
            );
        }

        return $this->withOwnedOnlyResources(
            $this->planForApplication($blueprint, $cloud, $state, $applications, $matches[0], false),
            $blueprint,
            $cloud,
            $state,
            $applications,
        );
    }

    /** @param list<CloudApplication> $applications */
    private function withOwnedOnlyResources(
        ExecutionPlan $desiredPlan,
        Blueprint $blueprint,
        LaravelCloudClient $cloud,
        StateDocument $state,
        array $applications,
    ): ExecutionPlan {
        $desiredApplication = (string) new ResourceAddress(
            ResourceType::APPLICATION,
            $blueprint->application->name,
        );
        $desiredEnvironments = [];
        foreach ($blueprint->environments as $environment) {
            $desiredEnvironments[(string) new ResourceAddress(ResourceType::ENVIRONMENT, $environment->name)] = true;
        }

        $ownedApplications = [];
        $ownedEnvironments = [];
        foreach ($state->resources() as $resource) {
            $address = (string) $resource->address;
            if ($resource->type === ResourceType::APPLICATION && $address !== $desiredApplication) {
                $ownedApplications[$address] = $resource;
            }
            if ($resource->type === ResourceType::ENVIRONMENT && !isset($desiredEnvironments[$address])) {
                $ownedEnvironments[$address] = $resource;
            }
        }
        ksort($ownedApplications, SORT_STRING);
        ksort($ownedEnvironments, SORT_STRING);

        $applicationActions = [];
        $environmentActions = [];
        $variableActions = [];
        foreach ($desiredPlan as $action) {
            match ($action->resourceType) {
                ResourceType::APPLICATION => $applicationActions[] = $action,
                ResourceType::ENVIRONMENT => $environmentActions[] = $action,
                ResourceType::VARIABLE => $variableActions[] = $action,
                ResourceType::DATABASE_CLUSTER,
                ResourceType::DATABASE,
                ResourceType::DATABASE_ATTACHMENT => null,
            };
        }

        foreach ($ownedApplications as $resource) {
            $applicationActions[] = $this->ownedOnlyApplicationAction(
                $resource,
                $state,
                $applications,
                $desiredEnvironments,
            );
        }

        /** @var array<string, list<CloudEnvironment>> $environmentCache */
        $environmentCache = [];
        foreach ($ownedEnvironments as $resource) {
            $environmentActions[] = $this->ownedOnlyEnvironmentAction(
                $resource,
                $state,
                $cloud,
                $applications,
                $environmentCache,
            );
        }

        return new ExecutionPlan(...$applicationActions, ...$environmentActions, ...$variableActions);
    }

    /**
     * @param list<CloudApplication> $applications
     * @param array<string, true> $desiredEnvironments
     */
    private function ownedOnlyApplicationAction(
        StateResource $resource,
        StateDocument $state,
        array $applications,
        array $desiredEnvironments,
    ): PlanAction {
        if ($resource->parent !== null) {
            return $this->applicationAction(
                $resource->address->name,
                PlanOperation::UNSUPPORTED,
                'Owned Application state has an invalid parent relationship and is absent from the blueprint.',
            );
        }

        $duplicate = $this->duplicateOwnershipReason($resource, $state);
        if ($duplicate !== null) {
            return $this->applicationAction(
                $resource->address->name,
                PlanOperation::UNSUPPORTED,
                $duplicate . ' This owned Application is absent from the blueprint.',
            );
        }

        foreach ($state->childrenOf($resource->address) as $child) {
            if (isset($desiredEnvironments[(string) $child->address])) {
                return $this->applicationAction(
                    $resource->address->name,
                    PlanOperation::UNSUPPORTED,
                    'This Application is absent from the blueprint but still owns a desired Environment. Parent deletion is structurally inconsistent.',
                );
            }
        }

        if ($this->findApplicationById($applications, $resource->remoteId) !== null) {
            return $this->applicationAction(
                $resource->address->name,
                PlanOperation::DELETE,
                'This State-owned Application is absent from the blueprint. Deletion is planned, but destructive execution is not enabled yet.',
                $resource->remoteId,
            );
        }

        $replacement = $this->applicationsNamed($applications, $resource->address->name);

        return $this->applicationAction(
            $resource->address->name,
            PlanOperation::DELETE,
            $replacement === []
                ? 'This State-owned Application is absent from the blueprint and its exact recorded remote identity is already missing. Deletion reconciliation is planned, but destructive execution is not enabled yet.'
                : 'This State-owned Application is absent from the blueprint and its exact recorded remote identity is missing. A same-name replacement is unmanaged and is not the deletion target; destructive execution is not enabled yet.',
            $resource->remoteId,
        );
    }

    /**
     * @param list<CloudApplication> $applications
     * @param array<string, list<CloudEnvironment>> $environmentCache
     */
    private function ownedOnlyEnvironmentAction(
        StateResource $resource,
        StateDocument $state,
        LaravelCloudClient $cloud,
        array $applications,
        array &$environmentCache,
    ): PlanAction {
        $invalid = $this->invalidOwnedOnlyEnvironmentParent($resource, $state);
        if ($invalid !== null) {
            return $this->environmentAction($resource->address->name, PlanOperation::UNSUPPORTED, $invalid);
        }

        $duplicate = $this->duplicateOwnershipReason($resource, $state);
        if ($duplicate !== null) {
            return $this->environmentAction(
                $resource->address->name,
                PlanOperation::UNSUPPORTED,
                $duplicate . ' This owned Environment is absent from the blueprint.',
            );
        }

        $parentAddress = $resource->parent;
        if ($parentAddress === null) {
            return $this->environmentAction(
                $resource->address->name,
                PlanOperation::UNSUPPORTED,
                'Owned Environment state has an invalid parent relationship and is absent from the blueprint.',
            );
        }
        $parent = $state->get($parentAddress);
        $remoteParent = $this->findApplicationById($applications, $parent->remoteId);
        if ($remoteParent === null) {
            return $this->environmentAction(
                $resource->address->name,
                PlanOperation::DELETE,
                'This State-owned Environment is absent from the blueprint and its exact parent Application identity is already missing. Deletion cannot execute without a valid owned parent.',
                $resource->remoteId,
                $resource->parent,
            );
        }

        $remoteEnvironments = $this->cachedEnvironments($cloud, $remoteParent->id, $environmentCache);
        $remoteEnvironment = $this->findEnvironmentById($remoteEnvironments, $resource->remoteId);
        if ($remoteEnvironment !== null) {
            return $this->environmentAction(
                $resource->address->name,
                PlanOperation::DELETE,
                $this->environmentDeleteReason($remoteEnvironment),
                $resource->remoteId,
                $resource->parent,
                $remoteEnvironment->dependencies,
            );
        }

        foreach ($applications as $application) {
            if ($application->id === $remoteParent->id) {
                continue;
            }
            if ($this->findEnvironmentById(
                $this->cachedEnvironments($cloud, $application->id, $environmentCache),
                $resource->remoteId,
            ) !== null) {
                return $this->environmentAction(
                    $resource->address->name,
                    PlanOperation::UNSUPPORTED,
                    'This Environment is owned by LCB and absent from the blueprint, but its recorded remote identity belongs to an unexpected Application. Automatic removal is not supported.',
                );
            }
        }

        $replacement = $this->environmentsNamed($remoteEnvironments, $resource->address->name);

        return $this->environmentAction(
            $resource->address->name,
            PlanOperation::DELETE,
            $replacement === []
                ? 'This State-owned Environment is absent from the blueprint and its exact recorded remote identity is already missing. Approved apply can reconcile local State without a DELETE request.'
                : 'This State-owned Environment is absent from the blueprint and its exact recorded remote identity is missing. A same-name replacement is unmanaged and will not be deleted; approved apply can reconcile only the stale State identity.',
            $resource->remoteId,
            $resource->parent,
            EnvironmentDependencies::authoritativeAbsence(),
        );
    }

    private function invalidOwnedOnlyEnvironmentParent(StateResource $resource, StateDocument $state): ?string
    {
        if ($resource->parent === null || $resource->parent->type !== ResourceType::APPLICATION) {
            return 'Owned Environment state has an invalid parent relationship and is absent from the blueprint.';
        }

        $parent = $state->find($resource->parent);
        if ($parent === null || $parent->type !== ResourceType::APPLICATION || $parent->parent !== null) {
            return 'Owned Environment state has an unresolvable parent ownership relationship and is absent from the blueprint.';
        }

        return null;
    }

    private function environmentDeleteReason(CloudEnvironment $environment): string
    {
        $base = 'This State-owned Environment is absent from the blueprint. Guarded deletion requires explicit approval and locked rediscovery.';

        return match ($environment->dependencies->readiness()) {
            EnvironmentDestructiveReadiness::BLOCKED => $base . sprintf(
                ' Dependency discovery found: %s.',
                implode(', ', array_map(
                    static fn ($category): string => $category->value,
                    $environment->dependencies->blockingCategories(),
                )),
            ),
            EnvironmentDestructiveReadiness::UNKNOWN => $base . ' Dependency discovery is incomplete or contains unknown relationships.'
                . ($environment->dependencies->missingRelationships === []
                    ? ''
                    : sprintf(
                        ' Missing dependency relationships: %s.',
                        implode(', ', $environment->dependencies->missingRelationships),
                    ))
                . ($environment->dependencies->unknownRelationships === []
                    ? ''
                    : sprintf(
                        ' Unknown dependency relationships: %s.',
                        implode(', ', $environment->dependencies->unknownRelationships),
                    )),
            EnvironmentDestructiveReadiness::SAFE => $base . ' Dependency discovery is complete and found no known blockers.'
                . ($environment->dependencies->informationalCategories() === []
                    ? ''
                    : sprintf(
                        ' Expected child dependencies: %s.',
                        implode(', ', array_map(
                            static fn ($category): string => $category->value,
                            $environment->dependencies->informationalCategories(),
                        )),
                    )),
        };
    }

    /**
     * @param array<string, list<CloudEnvironment>> $cache
     * @return list<CloudEnvironment>
     */
    private function cachedEnvironments(LaravelCloudClient $cloud, string $applicationId, array &$cache): array
    {
        return $cache[$applicationId] ??= $cloud->environments($applicationId);
    }

    /** @param list<CloudApplication> $applications */
    private function planForApplication(
        Blueprint $blueprint,
        LaravelCloudClient $cloud,
        StateDocument $state,
        array $applications,
        CloudApplication $application,
        bool $applicationIsManaged,
    ): ExecutionPlan {
        $actions = [$this->compareApplication($blueprint, $application, $applicationIsManaged)];
        $remoteEnvironments = $cloud->environments($application->id);
        /** @var array<string, array{PlanAction, CloudEnvironment|null}> $resolved */
        $resolved = [];

        foreach ($blueprint->environments as $environment) {
            $resolved[$environment->name] = $this->resolveEnvironment(
                $environment,
                $blueprint,
                $state,
                $application,
                $applicationIsManaged,
                $remoteEnvironments,
                $applications,
                $cloud,
            );
            $actions[] = $resolved[$environment->name][0];
        }

        foreach ($blueprint->environments as $environment) {
            [$environmentAction, $remote] = $resolved[$environment->name];
            if ($remote === null) {
                foreach ($environment->variables as $variable) {
                    $address = $this->variableAddress($environment->name, $variable->name);
                    if ($environmentAction->operation === PlanOperation::CREATE) {
                        $this->values->resolve($variable, $address);
                        $actions[] = $this->variableAction($address, PlanOperation::CREATE,
                            'Environment variable does not exist because the environment will be created.');
                    } else {
                        $actions[] = $this->variableAction($address, PlanOperation::UNSUPPORTED,
                            'Environment variable cannot be reconciled while its environment identity is unresolved.');
                    }
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

    private function createAllPlan(Blueprint $blueprint): ExecutionPlan
    {
        $actions = [$this->applicationAction($blueprint->application->name, PlanOperation::CREATE,
            'Application does not exist.')];
        foreach ($blueprint->environments as $environment) {
            $actions[] = $this->environmentAction($environment->name, PlanOperation::CREATE,
                'Environment does not exist because the application will be created.');
        }
        foreach ($blueprint->environments as $environment) {
            foreach ($environment->variables as $variable) {
                $address = $this->variableAddress($environment->name, $variable->name);
                $this->values->resolve($variable, $address);
                $actions[] = $this->variableAction($address, PlanOperation::CREATE,
                    'Environment variable does not exist because the environment will be created.');
            }
        }

        return new ExecutionPlan(...$actions);
    }

    private function blockedPlan(Blueprint $blueprint, string $reason): ExecutionPlan
    {
        $actions = [$this->applicationAction($blueprint->application->name, PlanOperation::UNSUPPORTED, $reason)];
        foreach ($blueprint->environments as $environment) {
            $actions[] = $this->environmentAction($environment->name, PlanOperation::UNSUPPORTED,
                'Environment cannot be reconciled while its parent application identity is unresolved.');
        }
        foreach ($blueprint->environments as $environment) {
            foreach ($environment->variables as $variable) {
                $actions[] = $this->variableAction(
                    $this->variableAddress($environment->name, $variable->name),
                    PlanOperation::UNSUPPORTED,
                    'Environment variable cannot be reconciled while its parent application identity is unresolved.',
                );
            }
        }

        return new ExecutionPlan(...$actions);
    }

    private function compareApplication(Blueprint $blueprint, CloudApplication $remote, bool $managed): PlanAction
    {
        if ($remote->region !== $blueprint->application->region) {
            return $this->applicationAction($blueprint->application->name, PlanOperation::UNSUPPORTED,
                'Remote application region differs from desired region.', $remote->id);
        }
        if ($remote->repository === null) {
            return $this->applicationAction($blueprint->application->name, PlanOperation::UNSUPPORTED,
                'Remote application repository information is unavailable.', $remote->id);
        }
        if ($remote->repository !== $blueprint->application->source->repository) {
            return $this->applicationAction($blueprint->application->name, PlanOperation::UNSUPPORTED,
                'Application repository differs and cannot be updated safely.', $remote->id);
        }

        return $this->applicationAction(
            $blueprint->application->name,
            PlanOperation::NO_CHANGE,
            $managed
                ? 'Managed remote application matches desired state.'
                : 'Matching remote application is unmanaged; use import to establish ownership before future mutation.',
            $remote->id,
        );
    }

    /**
     * @param list<CloudEnvironment> $remoteEnvironments
     * @param list<CloudApplication> $applications
     * @return array{PlanAction, CloudEnvironment|null}
     */
    private function resolveEnvironment(
        EnvironmentDefinition $desired,
        Blueprint $blueprint,
        StateDocument $state,
        CloudApplication $application,
        bool $applicationIsManaged,
        array $remoteEnvironments,
        array $applications,
        LaravelCloudClient $cloud,
    ): array {
        $address = new ResourceAddress(ResourceType::ENVIRONMENT, $desired->name);
        $managed = $state->find($address);

        if ($managed !== null) {
            $invalid = $this->invalidEnvironmentOwnership($managed, $state, $blueprint);
            if ($invalid !== null) {
                return [$this->environmentAction($desired->name, PlanOperation::UNSUPPORTED, $invalid), null];
            }
            $remote = $this->findEnvironmentById($remoteEnvironments, $managed->remoteId);
            if ($remote === null) {
                foreach ($applications as $candidateApplication) {
                    if ($candidateApplication->id === $application->id) {
                        continue;
                    }
                    if ($this->findEnvironmentById($cloud->environments($candidateApplication->id), $managed->remoteId) !== null) {
                        return [$this->environmentAction($desired->name, PlanOperation::UNSUPPORTED,
                            'Managed environment belongs to an unexpected remote application.'), null];
                    }
                }

                $replacement = $this->environmentsNamed($remoteEnvironments, $desired->name);
                return [$this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    $replacement === []
                        ? 'Managed environment remote identity is missing. State must be repaired before reconciliation.'
                        : 'Managed environment remote identity is missing and a same-name unmanaged replacement exists. Import or state repair is required.',
                ), null];
            }

            if ($remote->applicationId !== $application->id) {
                return [$this->environmentAction($desired->name, PlanOperation::UNSUPPORTED,
                    'Managed environment belongs to an unexpected remote application.'), null];
            }
            if ($remote->name !== $desired->name) {
                return [$this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    'Managed environment name differs from its blueprint address. Rename or state-move reconciliation is not supported.',
                    $remote->id,
                ), $remote];
            }

            return [$this->compareEnvironment($desired, $remote, true), $remote];
        }

        $matches = $this->environmentsNamed($remoteEnvironments, $desired->name);
        if (count($matches) > 1) {
            throw new AmbiguousResourceMatchException('environment', $desired->name);
        }
        if ($matches === []) {
            if (!$applicationIsManaged) {
                return [$this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    'Matching remote application is unmanaged. Import it before creating owned environments.',
                ), null];
            }
            return [$this->environmentAction($desired->name, PlanOperation::CREATE, 'Environment does not exist.'), null];
        }

        return [$this->compareEnvironment($desired, $matches[0], false), $matches[0]];
    }

    private function compareEnvironment(EnvironmentDefinition $desired, CloudEnvironment $remote, bool $managed): PlanAction
    {
        if ($remote->branch === null) {
            return $this->environmentAction($desired->name, PlanOperation::UNSUPPORTED,
                'Remote branch information is unavailable.', $remote->id);
        }
        if ($remote->branch !== $desired->branch) {
            if (!$managed) {
                return $this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    'Matching remote environment is unmanaged. Import it before reconciling its branch.',
                    $remote->id,
                );
            }
            return $this->environmentAction(
                $desired->name,
                PlanOperation::UPDATE,
                'Managed remote environment differs from desired state.',
                $remote->id,
                null,
                null,
                new PlanChange('branch', $remote->branch, $desired->branch),
            );
        }

        return $this->environmentAction(
            $desired->name,
            PlanOperation::NO_CHANGE,
            $managed
                ? 'Managed remote environment matches desired state.'
                : 'Matching remote environment is unmanaged; use import to establish ownership before future mutation.',
            $remote->id,
        );
    }

    private function compareVariable(
        string $environmentName,
        VariableDefinition $desired,
        ?CloudEnvironmentVariableCollection $remoteVariables,
    ): PlanAction {
        $address = $this->variableAddress($environmentName, $desired->name);
        $desiredValue = $this->values->resolve($desired, $address);
        if ($remoteVariables === null) {
            return $this->variableAction($address, PlanOperation::UNSUPPORTED,
                'Remote environment variable information is unavailable.');
        }
        $remote = $remoteVariables->find($desired->name);
        if ($remote === null) {
            return $this->variableAction($address, PlanOperation::CREATE, 'Environment variable does not exist.');
        }
        if ($remote->value !== $desiredValue) {
            return $this->variableAction($address, PlanOperation::UPDATE,
                'Environment variable differs from desired state.');
        }
        return $this->variableAction($address, PlanOperation::NO_CHANGE,
            'Environment variable matches desired state.');
    }

    private function invalidApplicationOwnership(StateResource $resource, StateDocument $state): ?string
    {
        if ($resource->type !== ResourceType::APPLICATION || $resource->parent !== null) {
            return 'Application state ownership has an invalid resource type or parent relationship.';
        }
        return $this->duplicateOwnershipReason($resource, $state);
    }

    private function invalidEnvironmentOwnership(StateResource $resource, StateDocument $state, Blueprint $blueprint): ?string
    {
        $expectedParent = new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name);
        if ($resource->type !== ResourceType::ENVIRONMENT
            || $resource->parent === null
            || (string) $resource->parent !== (string) $expectedParent) {
            return 'Environment state ownership has an invalid resource type or parent relationship.';
        }
        return $this->duplicateOwnershipReason($resource, $state);
    }

    private function duplicateOwnershipReason(StateResource $managed, StateDocument $state): ?string
    {
        foreach ($state->resources() as $resource) {
            if ((string) $resource->address !== (string) $managed->address && $resource->remoteId === $managed->remoteId) {
                return sprintf('Remote identity is also owned by conflicting state address "%s".', (string) $resource->address);
            }
        }
        return null;
    }

    /** @param list<CloudApplication> $applications */
    private function findApplicationById(array $applications, string $id): ?CloudApplication
    {
        foreach ($applications as $application) {
            if ($application->id === $id) {
                return $application;
            }
        }
        return null;
    }

    /**
     * @param list<CloudApplication> $applications
     * @return list<CloudApplication>
     */
    private function applicationsNamed(array $applications, string $name): array
    {
        return array_values(array_filter($applications,
            static fn (CloudApplication $application): bool => $application->name === $name));
    }

    /** @param list<CloudEnvironment> $environments */
    private function findEnvironmentById(array $environments, string $id): ?CloudEnvironment
    {
        foreach ($environments as $environment) {
            if ($environment->id === $id) {
                return $environment;
            }
        }
        return null;
    }

    /**
     * @param list<CloudEnvironment> $environments
     * @return list<CloudEnvironment>
     */
    private function environmentsNamed(array $environments, string $name): array
    {
        return array_values(array_filter($environments,
            static fn (CloudEnvironment $environment): bool => $environment->name === $name));
    }

    private function withDatabaseActions(
        ExecutionPlan $base,
        Blueprint $blueprint,
        LaravelCloudDatabaseClient $cloud,
        StateDocument $state,
    ): ExecutionPlan {
        $applicationActions = [];
        $environmentActions = [];
        $variableActions = [];
        foreach ($base as $action) {
            match ($action->resourceType) {
                ResourceType::APPLICATION => $applicationActions[] = $action,
                ResourceType::ENVIRONMENT => $environmentActions[] = $action,
                ResourceType::VARIABLE => $variableActions[] = $action,
                ResourceType::DATABASE_CLUSTER,
                ResourceType::DATABASE,
                ResourceType::DATABASE_ATTACHMENT => null,
            };
        }

        $remoteClusters = $cloud->databaseClusters();
        $clusterActions = [];
        $databaseActions = [];
        /** @var array<string, array<string, CloudDatabase>> $resolvedDatabases */
        $resolvedDatabases = [];

        foreach ($blueprint->databaseClusters as $desiredCluster) {
            $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, $desiredCluster->name);
            $managedCluster = $state->find($clusterAddress);
            $matches = $managedCluster === null
                ? array_values(array_filter(
                    $remoteClusters,
                    static fn (CloudDatabaseCluster $cluster): bool => $cluster->name === $desiredCluster->name,
                ))
                : array_values(array_filter(
                    $remoteClusters,
                    static fn (CloudDatabaseCluster $cluster): bool => $cluster->id === $managedCluster->remoteId,
                ));

            if ($matches === []) {
                $clusterActions[] = $this->databaseClusterAction(
                    $desiredCluster->name,
                    $managedCluster === null ? PlanOperation::CREATE : PlanOperation::UNSUPPORTED,
                    $managedCluster === null
                        ? 'Database Cluster does not exist and will be created and state-owned.'
                        : ($this->hasClusterNamed($remoteClusters, $desiredCluster->name)
                            ? 'Owned Database Cluster remote identity is missing and a same-name unmanaged replacement exists. Automatic adoption or creation is not supported.'
                            : 'Owned Database Cluster remote identity is missing. State repair is required; replacement creation is not supported.'),
                );
                if ($managedCluster === null) {
                    foreach ($desiredCluster->databases as $desiredDatabase) {
                        $databaseActions[] = $this->databaseAction(
                            $desiredCluster->name . '.' . $desiredDatabase->name,
                            PlanOperation::CREATE,
                            'Logical Database will be created after its new parent Database Cluster is checkpointed.',
                        );
                    }
                } else {
                    $this->unresolvedLogicalDatabaseActions($databaseActions, $desiredCluster,
                        'Logical Database cannot be resolved because its owned parent Database Cluster does not exist.');
                }
                continue;
            }
            if (count($matches) > 1) {
                $clusterActions[] = $this->databaseClusterAction(
                    $desiredCluster->name,
                    PlanOperation::UNSUPPORTED,
                    'Multiple matching remote Database Clusters exist; selection would be ambiguous.',
                );
                $this->unresolvedLogicalDatabaseActions($databaseActions, $desiredCluster,
                    'Logical Database cannot be resolved because its parent Database Cluster match is ambiguous.');
                continue;
            }

            $remoteCluster = $matches[0];
            if ($remoteCluster->name !== $desiredCluster->name) {
                $clusterActions[] = $this->databaseClusterAction(
                    $desiredCluster->name,
                    PlanOperation::UNSUPPORTED,
                    'Owned Database Cluster name differs from its blueprint address. Rename or state-move reconciliation is not supported.',
                );
                $this->unresolvedLogicalDatabaseActions($databaseActions, $desiredCluster,
                    'Logical Database cannot be compared while its owned parent Database Cluster identity conflicts.');
                continue;
            }
            $clusterAction = $this->compareDatabaseCluster($desiredCluster, $remoteCluster, $managedCluster !== null);
            $clusterActions[] = $clusterAction;
            if ($clusterAction->operation !== PlanOperation::NO_CHANGE) {
                $this->unresolvedLogicalDatabaseActions($databaseActions, $desiredCluster,
                    'Logical Database cannot be compared while its parent Database Cluster is unresolved or unsupported.');
                continue;
            }

            $remoteDatabases = $cloud->databases($remoteCluster->id);
            foreach ($desiredCluster->databases as $desiredDatabase) {
                $name = $desiredCluster->name . '.' . $desiredDatabase->name;
                $databaseAddress = new ResourceAddress(ResourceType::DATABASE, $name);
                $managedDatabase = $state->find($databaseAddress);
                $databaseMatches = $managedDatabase === null
                    ? array_values(array_filter(
                        $remoteDatabases,
                        static fn (CloudDatabase $database): bool => $database->name === $desiredDatabase->name,
                    ))
                    : array_values(array_filter(
                        $remoteDatabases,
                        static fn (CloudDatabase $database): bool => $database->id === $managedDatabase->remoteId,
                    ));
                if ($databaseMatches === []) {
                    $createAllowed = $managedDatabase === null && $managedCluster !== null;
                    $databaseActions[] = $this->databaseAction(
                        $name,
                        $createAllowed ? PlanOperation::CREATE : PlanOperation::UNSUPPORTED,
                        $managedDatabase === null
                            ? ($managedCluster === null
                                ? 'Logical Database is missing under an unmanaged Cluster. Import the parent Cluster before creating children.'
                                : 'Logical Database does not exist and will be created in its owned parent Cluster.')
                            : ($this->hasDatabaseNamed($remoteDatabases, $desiredDatabase->name)
                                ? 'Owned logical Database remote identity is missing and a same-name unmanaged replacement exists. Automatic adoption or creation is not supported.'
                                : 'Owned logical Database remote identity is missing from its expected Cluster. State repair is required.'),
                    );
                    continue;
                }
                if (count($databaseMatches) > 1) {
                    $databaseActions[] = $this->databaseAction($name, PlanOperation::UNSUPPORTED,
                        'Multiple matching logical Databases exist within the Cluster; selection would be ambiguous.');
                    continue;
                }

                if ($databaseMatches[0]->name !== $desiredDatabase->name) {
                    $databaseActions[] = $this->databaseAction($name, PlanOperation::UNSUPPORTED,
                        'Owned logical Database name differs from its blueprint address. Rename or state-move reconciliation is not supported.');
                    continue;
                }

                $databaseActions[] = $this->databaseAction(
                    $name,
                    PlanOperation::NO_CHANGE,
                    $managedDatabase === null
                        ? 'Matching remote logical Database exists but is unmanaged; future mutation requires import and state ownership.'
                        : 'Owned remote logical Database matches desired identity.',
                );
                $resolvedDatabases[$desiredCluster->name][$desiredDatabase->name] = $databaseMatches[0];
            }
        }

        $this->ownedOnlyDatabaseActions($clusterActions, $databaseActions, $blueprint, $state);

        $attachmentActions = $this->databaseAttachmentActions(
            $blueprint,
            $cloud,
            $applicationActions,
            $environmentActions,
            $resolvedDatabases,
        );

        return new ExecutionPlan(
            ...$applicationActions,
            ...$environmentActions,
            ...$clusterActions,
            ...$databaseActions,
            ...$attachmentActions,
            ...$variableActions,
        );
    }

    /** @param list<CloudDatabaseCluster> $clusters */
    private function hasClusterNamed(array $clusters, string $name): bool
    {
        foreach ($clusters as $cluster) {
            if ($cluster->name === $name) {
                return true;
            }
        }
        return false;
    }

    /** @param list<CloudDatabase> $databases */
    private function hasDatabaseNamed(array $databases, string $name): bool
    {
        foreach ($databases as $database) {
            if ($database->name === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<PlanAction> $clusterActions
     * @param list<PlanAction> $databaseActions
     */
    private function ownedOnlyDatabaseActions(
        array &$clusterActions,
        array &$databaseActions,
        Blueprint $blueprint,
        StateDocument $state,
    ): void {
        $desiredClusters = [];
        $desiredDatabases = [];
        foreach ($blueprint->databaseClusters as $cluster) {
            $desiredClusters[(string) new ResourceAddress(ResourceType::DATABASE_CLUSTER, $cluster->name)] = true;
            foreach ($cluster->databases as $database) {
                $desiredDatabases[(string) new ResourceAddress(
                    ResourceType::DATABASE,
                    $cluster->name . '.' . $database->name,
                )] = true;
            }
        }

        foreach ($state->resources() as $resource) {
            $address = (string) $resource->address;
            if ($resource->type === ResourceType::DATABASE_CLUSTER && !isset($desiredClusters[$address])) {
                $hasDesiredChild = false;
                foreach ($state->childrenOf($resource->address) as $child) {
                    if (isset($desiredDatabases[(string) $child->address])) {
                        $hasDesiredChild = true;
                        break;
                    }
                }
                $clusterActions[] = $this->databaseClusterAction(
                    $resource->address->name,
                    $hasDesiredChild ? PlanOperation::UNSUPPORTED : PlanOperation::DELETE,
                    $hasDesiredChild
                        ? 'This Database Cluster is absent from the blueprint but still owns a desired logical Database. Parent deletion is structurally inconsistent.'
                        : 'This State-owned Database Cluster is absent from the blueprint. Deletion is planned child-first, but destructive execution is not enabled yet.',
                    $resource->remoteId,
                );
            }
            if ($resource->type === ResourceType::DATABASE && !isset($desiredDatabases[$address])) {
                $databaseActions[] = $this->databaseAction(
                    $resource->address->name,
                    PlanOperation::DELETE,
                    'This State-owned logical Database is absent from the blueprint. Deletion is planned, but destructive execution and attachment mutation are not enabled yet.',
                    $resource->remoteId,
                    $resource->parent,
                );
            }
        }
    }

    private function compareDatabaseCluster(
        DatabaseClusterDefinition $desired,
        CloudDatabaseCluster $remote,
        bool $managed,
    ): PlanAction {
        $statusReason = $this->databaseStatuses->unsupportedReason($remote->status);
        if ($statusReason !== null) {
            return $this->databaseClusterAction($desired->name, PlanOperation::UNSUPPORTED, $statusReason);
        }
        if ($remote->type !== $desired->type->value) {
            return $this->databaseClusterAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote Database Cluster type differs or is not safely supported.',
                null,
                null,
                new PlanChange('type', $remote->type, $desired->type->value),
            );
        }
        if ($remote->region !== $desired->region) {
            return $this->databaseClusterAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote Database Cluster region differs. Database Cluster updates are not supported yet.',
                null,
                null,
                new PlanChange('region', $remote->region, $desired->region),
            );
        }

        $changes = $this->databaseConfigurationChanges($desired, $remote);
        if ($changes === null) {
            return $this->databaseClusterAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote Database Cluster configuration is not safely comparable for this supported provider.',
            );
        }
        if ($changes !== []) {
            return $this->databaseClusterAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote Database Cluster configuration differs. Database Cluster updates are not supported yet.',
                null,
                null,
                ...$changes,
            );
        }

        return $this->databaseClusterAction(
            $desired->name,
            PlanOperation::NO_CHANGE,
            $managed
                ? 'Owned remote Database Cluster matches desired state.'
                : 'Matching remote Database Cluster exists but is unmanaged; future mutation requires import and state ownership.',
        );
    }

    /** @return list<PlanChange>|null */
    private function databaseConfigurationChanges(
        DatabaseClusterDefinition $desired,
        CloudDatabaseCluster $remote,
    ): ?array {
        if ($desired->configuration instanceof LaravelMySqlConfiguration) {
            if (!$remote->configuration instanceof CloudLaravelMySqlConfiguration) {
                return null;
            }
            return $this->configurationChanges([
                'size' => [$remote->configuration->size, $desired->configuration->size],
                'storage' => [$remote->configuration->storage, $desired->configuration->storage],
                'retention_days' => [$remote->configuration->retentionDays, $desired->configuration->retentionDays],
                'uses_scheduled_snapshots' => [$remote->configuration->usesScheduledSnapshots, $desired->configuration->usesScheduledSnapshots],
                'is_public' => [$remote->configuration->isPublic, $desired->configuration->isPublic],
            ]);
        }
        if ($desired->configuration instanceof NeonPostgresConfiguration) {
            if (!$remote->configuration instanceof CloudNeonPostgresConfiguration) {
                return null;
            }
            return $this->configurationChanges([
                'cu_min' => [$remote->configuration->minimumComputeUnits, $desired->configuration->minimumComputeUnits],
                'cu_max' => [$remote->configuration->maximumComputeUnits, $desired->configuration->maximumComputeUnits],
                'suspend_seconds' => [$remote->configuration->suspendSeconds, $desired->configuration->suspendSeconds],
                'retention_days' => [$remote->configuration->retentionDays, $desired->configuration->retentionDays],
            ]);
        }

        return null;
    }

    /**
     * @param array<string, array{string|int|float|bool, string|int|float|bool}> $values
     * @return list<PlanChange>
     */
    private function configurationChanges(array $values): array
    {
        $changes = [];
        foreach ($values as $field => [$before, $after]) {
            if ($before === $after || (is_float($before) && is_float($after) && $before == $after)) {
                continue;
            }
            $changes[] = new PlanChange($field, $this->displayValue($before), $this->displayValue($after));
        }
        return $changes;
    }

    private function displayValue(string|int|float|bool $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @param list<PlanAction> $actions */
    private function unresolvedLogicalDatabaseActions(
        array &$actions,
        DatabaseClusterDefinition $cluster,
        string $reason,
    ): void {
        foreach ($cluster->databases as $database) {
            $actions[] = $this->databaseAction(
                $cluster->name . '.' . $database->name,
                PlanOperation::UNSUPPORTED,
                $reason,
            );
        }
    }

    /**
     * @param list<PlanAction> $applicationActions
     * @param list<PlanAction> $environmentActions
     * @param array<string, array<string, CloudDatabase>> $resolvedDatabases
     * @return list<PlanAction>
     */
    private function databaseAttachmentActions(
        Blueprint $blueprint,
        LaravelCloudDatabaseClient $cloud,
        array $applicationActions,
        array $environmentActions,
        array $resolvedDatabases,
    ): array {
        $requiresAttachments = false;
        foreach ($blueprint->environments as $environment) {
            $requiresAttachments = $requiresAttachments || $environment->database !== null;
        }
        if (!$requiresAttachments) {
            return [];
        }

        $applicationId = $applicationActions[0]->remoteId ?? null;
        $remoteEnvironments = $applicationId === null ? [] : $cloud->environments($applicationId);
        $environmentActionsByName = [];
        foreach ($environmentActions as $action) {
            $environmentActionsByName[$action->address->name] = $action;
        }

        $actions = [];
        foreach ($blueprint->environments as $environment) {
            $reference = $environment->database;
            if ($reference === null) {
                continue;
            }
            $database = $resolvedDatabases[$reference->cluster][$reference->database] ?? null;
            if ($database === null) {
                $actions[] = $this->databaseAttachmentAction($environment->name, PlanOperation::UNSUPPORTED,
                    'Environment Database attachment cannot be resolved because the desired logical Database is unresolved.');
                continue;
            }

            $environmentAction = $environmentActionsByName[$environment->name] ?? null;
            $environmentId = $environmentAction?->remoteId;
            $remoteEnvironment = $environmentId === null
                ? null
                : $this->findEnvironmentById($remoteEnvironments, $environmentId);
            if ($remoteEnvironment === null) {
                $actions[] = $this->databaseAttachmentAction($environment->name, PlanOperation::UNSUPPORTED,
                    'Environment Database attachment cannot be compared while the Environment identity is unresolved.');
                continue;
            }
            if ($remoteEnvironment->databaseId === $database->id) {
                $actions[] = $this->databaseAttachmentAction($environment->name, PlanOperation::NO_CHANGE,
                    'Environment is attached to the desired logical Database.');
                continue;
            }

            $actions[] = $this->databaseAttachmentAction(
                $environment->name,
                PlanOperation::UNSUPPORTED,
                $remoteEnvironment->databaseId === null
                    ? 'Environment has no Database attachment. Attachment updates are not supported yet.'
                    : 'Environment Database attachment differs. Attachment updates are not supported yet.',
            );
        }

        return $actions;
    }

    private function databaseClusterAction(
        string $name,
        PlanOperation $operation,
        string $reason,
        ?string $remoteId = null,
        ?ResourceAddress $parent = null,
        PlanChange ...$changes,
    ): PlanAction {
        return new PlanAction(
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, $name),
            ResourceType::DATABASE_CLUSTER,
            $operation,
            $reason,
            $remoteId,
            ...($parent === null ? $changes : [$parent, ...$changes]),
        );
    }

    private function databaseAction(
        string $name,
        PlanOperation $operation,
        string $reason,
        ?string $remoteId = null,
        ?ResourceAddress $parent = null,
    ): PlanAction
    {
        return new PlanAction(
            new ResourceAddress(ResourceType::DATABASE, $name),
            ResourceType::DATABASE,
            $operation,
            $reason,
            $remoteId,
            ...($parent === null ? [] : [$parent]),
        );
    }

    private function databaseAttachmentAction(string $name, PlanOperation $operation, string $reason): PlanAction
    {
        return new PlanAction(
            new ResourceAddress(ResourceType::DATABASE_ATTACHMENT, $name),
            ResourceType::DATABASE_ATTACHMENT,
            $operation,
            $reason,
        );
    }

    private function applicationAction(
        string $name,
        PlanOperation $operation,
        string $reason,
        ?string $remoteId = null,
        ?ResourceAddress $parent = null,
        PlanChange ...$changes,
    ): PlanAction {
        return new PlanAction(new ResourceAddress(ResourceType::APPLICATION, $name), ResourceType::APPLICATION,
            $operation, $reason, $remoteId, ...($parent === null ? $changes : [$parent, ...$changes]));
    }

    private function environmentAction(
        string $name,
        PlanOperation $operation,
        string $reason,
        ?string $remoteId = null,
        ?ResourceAddress $parent = null,
        ?\LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies $dependencies = null,
        PlanChange ...$changes,
    ): PlanAction {
        return new PlanAction(new ResourceAddress(ResourceType::ENVIRONMENT, $name), ResourceType::ENVIRONMENT,
            $operation,
            $reason,
            $remoteId,
            ...array_values(array_filter([$parent, $dependencies, ...$changes])),
        );
    }

    private function variableAddress(string $environmentName, string $variableName): ResourceAddress
    {
        return new ResourceAddress(ResourceType::VARIABLE, $environmentName . '.' . $variableName);
    }

    private function variableAction(ResourceAddress $address, PlanOperation $operation, string $reason): PlanAction
    {
        return new PlanAction($address, ResourceType::VARIABLE, $operation, $reason);
    }
}
