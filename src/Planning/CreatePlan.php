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
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseLifecycleClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDestructiveReadiness;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseClusterLifecycleReadiness;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencies;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotType;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Observation\ApplicationObservationEvidence;
use LaravelCloudBlueprint\Observation\ApplicationObservationFactory;
use LaravelCloudBlueprint\Observation\DatabaseClusterCandidate;
use LaravelCloudBlueprint\Observation\DatabaseClusterObservationEvidence;
use LaravelCloudBlueprint\Observation\DatabaseClusterObservationFactory;
use LaravelCloudBlueprint\Observation\DatabaseParentEvidence;
use LaravelCloudBlueprint\Observation\DerivedDatabaseObservationEvidence;
use LaravelCloudBlueprint\Observation\DerivedDatabaseObservationFactory;
use LaravelCloudBlueprint\Observation\EnvironmentObservationEvidence;
use LaravelCloudBlueprint\Observation\EnvironmentObservationFactory;
use LaravelCloudBlueprint\Observation\EnvironmentParentEvidence;
use LaravelCloudBlueprint\Observation\EnvironmentVariableObservationEvidence;
use LaravelCloudBlueprint\Observation\EnvironmentVariableObservationFactory;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\LogicalDatabaseObservationEvidence;
use LaravelCloudBlueprint\Observation\LogicalDatabaseObservationFactory;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Observation\ResourceObservationCollection;
use LaravelCloudBlueprint\Observation\ResourceObservationRecorder;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;

final readonly class CreatePlan
{
    public function __construct(
        private VariableValueResolver $values,
        private DatabaseClusterStatusPolicy $databaseStatuses = new DatabaseClusterStatusPolicy(),
        private ApplicationObservationFactory $applicationObservations = new ApplicationObservationFactory(),
        private EnvironmentObservationFactory $environmentObservations = new EnvironmentObservationFactory(),
        private EnvironmentVariableObservationFactory $variableObservations = new EnvironmentVariableObservationFactory(),
        private DatabaseClusterObservationFactory $databaseClusterObservations = new DatabaseClusterObservationFactory(),
        private LogicalDatabaseObservationFactory $databaseObservations = new LogicalDatabaseObservationFactory(),
        private DerivedDatabaseObservationFactory $derivedDatabaseObservations = new DerivedDatabaseObservationFactory(),
        private ResourceObservationRecorder $observationRecorder = new ResourceObservationRecorder(),
    ) {
    }

    public function create(Blueprint $blueprint, LaravelCloudClient $cloud, StateDocument $state): ExecutionPlan
    {
        $this->observationRecorder->reset();

        return $this->createPlan($blueprint, $cloud, $state);
    }

    public function collectObservations(
        Blueprint $blueprint,
        LaravelCloudClient $cloud,
        StateDocument $state,
    ): ResourceObservationCollection {
        $this->observationRecorder->reset(reporting: true);
        $this->createPlan($blueprint, $cloud, $state);

        return $this->observationRecorder->collection();
    }

    private function createPlan(Blueprint $blueprint, LaravelCloudClient $cloud, StateDocument $state): ExecutionPlan
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
        $invalid = $managedApplication === null
            ? null
            : $this->invalidApplicationOwnership($managedApplication, $state);
        $observation = $this->observationRecorder->record($this->applicationObservations->create(
            $blueprint->application,
            $managedApplication,
            new ApplicationObservationEvidence(
                $applications,
                EvidenceStatus::COMPLETE,
                $invalid !== null,
            ),
        ));

        if ($managedApplication !== null) {
            if ($invalid !== null) {
                return $this->withOwnedOnlyResources(
                    $this->blockedPlan(
                        $blueprint,
                        $this->applicationConflictReason($observation, $invalid),
                    ),
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
                        $this->missingApplicationReason($observation, $replacement !== []),
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
                        $this->applicationNameConflictReason($observation),
                    ),
                    $blueprint,
                    $cloud,
                    $state,
                    $applications,
                );
            }

            $desiredPlan = $this->planForApplication(
                $blueprint,
                $cloud,
                $state,
                $applications,
                $application,
                true,
                $observation,
            );

            return $this->withOwnedOnlyResources($desiredPlan, $blueprint, $cloud, $state, $applications);
        }

        $matches = $this->applicationsNamed($applications, $blueprint->application->name);
        if (count($matches) > 1) {
            $this->requireObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::UNMANAGED);
            if ($this->observationRecorder->isReporting()) {
                return $this->withOwnedOnlyResources(
                    $this->blockedPlan($blueprint, 'Application discovery is ambiguous.'),
                    $blueprint,
                    $cloud,
                    $state,
                    $applications,
                );
            }
            throw new AmbiguousResourceMatchException('application', $blueprint->application->name);
        }
        if ($matches === []) {
            $this->requireObservation($observation, ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE);
            return $this->withOwnedOnlyResources(
                $this->createAllPlan($blueprint),
                $blueprint,
                $cloud,
                $state,
                $applications,
            );
        }

        return $this->withOwnedOnlyResources(
            $this->planForApplication(
                $blueprint,
                $cloud,
                $state,
                $applications,
                $matches[0],
                false,
                $observation,
            ),
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
        $this->recordSimpleObservation(
            $resource->address,
            ObservationKind::DESIRED_RESOURCE_ABSENT,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::UNSUPPORTED,
            EvidenceStatus::COMPLETE,
        );
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
        $this->recordSimpleObservation(
            $resource->address,
            ObservationKind::DESIRED_RESOURCE_ABSENT,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::BLOCKED,
            EvidenceStatus::INCOMPLETE,
        );
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
            $this->recordSimpleObservation(
                $resource->address,
                ObservationKind::DESIRED_RESOURCE_ABSENT,
                OwnershipStatus::MANAGED,
                $remoteEnvironment->dependencies->readiness() === EnvironmentDestructiveReadiness::SAFE
                    ? ReconciliationStatus::SUPPORTED
                    : ReconciliationStatus::BLOCKED,
                $remoteEnvironment->dependencies->readiness() === EnvironmentDestructiveReadiness::UNKNOWN
                    ? EvidenceStatus::INCOMPLETE
                    : EvidenceStatus::COMPLETE,
            );
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

        $this->recordSimpleObservation(
            $resource->address,
            ObservationKind::DESIRED_RESOURCE_ABSENT,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::SUPPORTED,
            EvidenceStatus::COMPLETE,
        );

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
        ResourceObservation $applicationObservation,
    ): ExecutionPlan {
        $actions = [$this->applicationActionFromObservation(
            $blueprint,
            $application,
            $applicationObservation,
        )];
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
                        $this->recordSimpleObservation(
                            $address,
                            ObservationKind::DESIRED_RESOURCE_MISSING,
                            OwnershipStatus::NONE,
                            ReconciliationStatus::SUPPORTED,
                            EvidenceStatus::COMPLETE,
                        );
                        $actions[] = $this->variableAction($address, PlanOperation::CREATE,
                            'Environment variable does not exist because the environment will be created.');
                    } else {
                        $observation = $this->observationRecorder->record($this->variableObservations->createWithoutValues(
                            $address,
                            EnvironmentVariableObservationEvidence::parentUnresolved(),
                        ));
                        $this->requireObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::NONE);
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
            $this->recordSimpleObservation(
                new ResourceAddress(ResourceType::ENVIRONMENT, $environment->name),
                ObservationKind::DESIRED_RESOURCE_MISSING,
                OwnershipStatus::NONE,
                ReconciliationStatus::SUPPORTED,
                EvidenceStatus::COMPLETE,
            );
            $actions[] = $this->environmentAction($environment->name, PlanOperation::CREATE,
                'Environment does not exist because the application will be created.');
        }
        foreach ($blueprint->environments as $environment) {
            foreach ($environment->variables as $variable) {
                $address = $this->variableAddress($environment->name, $variable->name);
                $this->values->resolve($variable, $address);
                $this->recordSimpleObservation(
                    $address,
                    ObservationKind::DESIRED_RESOURCE_MISSING,
                    OwnershipStatus::NONE,
                    ReconciliationStatus::SUPPORTED,
                    EvidenceStatus::COMPLETE,
                );
                $actions[] = $this->variableAction($address, PlanOperation::CREATE,
                    'Environment variable does not exist because the environment will be created.');
            }
        }

        return new ExecutionPlan(...$actions);
    }

    private function blockedPlan(Blueprint $blueprint, string $reason): ExecutionPlan
    {
        $this->recordUnresolvedDesiredChildren($blueprint);
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

    private function applicationActionFromObservation(
        Blueprint $blueprint,
        CloudApplication $remote,
        ResourceObservation $observation,
    ): PlanAction
    {
        if ($observation->observation === ObservationKind::CONFIGURATION_DIFFERENCE
            && in_array($observation->reconciliation, [
                ReconciliationStatus::UNSUPPORTED,
                ReconciliationStatus::BLOCKED,
            ], true)
            && in_array('region', $observation->changedFields->values(), true)) {
            return $this->applicationAction($blueprint->application->name, PlanOperation::UNSUPPORTED,
                'Remote application region differs from desired region.', $remote->id);
        }
        if ($observation->observation === ObservationKind::UNKNOWN
            && $observation->evidence === EvidenceStatus::INCOMPLETE) {
            return $this->applicationAction($blueprint->application->name, PlanOperation::UNSUPPORTED,
                'Remote application repository information is unavailable.', $remote->id);
        }
        if ($observation->observation === ObservationKind::CONFIGURATION_DIFFERENCE
            && in_array($observation->reconciliation, [
                ReconciliationStatus::UNSUPPORTED,
                ReconciliationStatus::BLOCKED,
            ], true)
            && in_array('repository', $observation->changedFields->values(), true)) {
            return $this->applicationAction($blueprint->application->name, PlanOperation::UNSUPPORTED,
                'Application repository differs and cannot be updated safely.', $remote->id);
        }

        $this->requireObservation(
            $observation,
            ObservationKind::IN_SYNC,
            $observation->ownership,
        );
        if (!in_array($observation->ownership, [OwnershipStatus::MANAGED, OwnershipStatus::UNMANAGED], true)) {
            throw new \LogicException('In-sync Application observation must have managed or unmanaged ownership.');
        }
        if ($observation->reconciliation !== ReconciliationStatus::NOT_APPLICABLE) {
            throw new \LogicException('In-sync Application observation must not require reconciliation.');
        }

        return $this->applicationAction(
            $blueprint->application->name,
            PlanOperation::NO_CHANGE,
            $observation->ownership === OwnershipStatus::MANAGED
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
        $parentAddress = new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name);
        $parent = EnvironmentParentEvidence::resolved(
            $parentAddress,
            $application->id,
            $applicationIsManaged ? OwnershipStatus::MANAGED : OwnershipStatus::UNMANAGED,
        );
        $invalid = $managed === null ? null : $this->invalidEnvironmentOwnership($managed, $state, $blueprint);

        if ($invalid !== null) {
            $observation = $this->observationRecorder->record($this->environmentObservations->create(
                $desired,
                $managed,
                new EnvironmentObservationEvidence($parent, $remoteEnvironments, EvidenceStatus::COMPLETE, true),
            ));
            $this->requireObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT);
            return [$this->environmentAction($desired->name, PlanOperation::UNSUPPORTED, $invalid), null];
        }

        $scopedEnvironments = $remoteEnvironments;
        if ($managed !== null && $this->findEnvironmentById($remoteEnvironments, $managed->remoteId) === null) {
            foreach ($applications as $candidateApplication) {
                if ($candidateApplication->id === $application->id) {
                    continue;
                }
                $candidateEnvironments = $cloud->environments($candidateApplication->id);
                $scopedEnvironments = [...$scopedEnvironments, ...$candidateEnvironments];
                if ($this->findEnvironmentById($candidateEnvironments, $managed->remoteId) !== null) {
                    break;
                }
            }
        }
        $observation = $this->observationRecorder->record($this->environmentObservations->create(
            $desired,
            $managed,
            new EnvironmentObservationEvidence($parent, $scopedEnvironments, EvidenceStatus::COMPLETE),
        ));

        if ($managed !== null) {
            $remote = $this->findEnvironmentById($scopedEnvironments, $managed->remoteId);
            if ($remote === null) {
                $replacement = $this->environmentsNamed($remoteEnvironments, $desired->name);
                $this->requireObservation(
                    $observation,
                    $replacement === [] ? ObservationKind::IDENTITY_MISSING : ObservationKind::IDENTITY_REPLACEMENT,
                    OwnershipStatus::MANAGED,
                );
                return [$this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    $replacement === []
                        ? 'Managed environment remote identity is missing. State must be repaired before reconciliation.'
                        : 'Managed environment remote identity is missing and a same-name unmanaged replacement exists. Import or state repair is required.',
                ), null];
            }

            if ($remote->applicationId !== $application->id) {
                $this->requireObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED);
                return [$this->environmentAction($desired->name, PlanOperation::UNSUPPORTED,
                    'Managed environment belongs to an unexpected remote application.'), null];
            }
            if ($remote->name !== $desired->name) {
                $this->requireObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED);
                return [$this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    'Managed environment name differs from its blueprint address. Rename or state-move reconciliation is not supported.',
                    $remote->id,
                ), $remote];
            }

            return [$this->environmentActionFromObservation($desired, $remote, $observation), $remote];
        }

        $matches = $this->environmentsNamed($remoteEnvironments, $desired->name);
        if (count($matches) > 1) {
            $this->requireObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::UNMANAGED);
            if ($this->observationRecorder->isReporting()) {
                return [$this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    'Environment discovery is ambiguous.',
                ), null];
            }
            throw new AmbiguousResourceMatchException('environment', $desired->name);
        }
        if ($matches === []) {
            if (!$applicationIsManaged) {
                $this->requireObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::NONE);
                return [$this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    'Matching remote application is unmanaged. Import it before creating owned environments.',
                ), null];
            }
            $this->requireObservation($observation, ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE);
            return [$this->environmentAction($desired->name, PlanOperation::CREATE, 'Environment does not exist.'), null];
        }

        return [$this->environmentActionFromObservation($desired, $matches[0], $observation), $matches[0]];
    }

    private function environmentActionFromObservation(
        EnvironmentDefinition $desired,
        CloudEnvironment $remote,
        ResourceObservation $observation,
    ): PlanAction
    {
        if ($observation->observation === ObservationKind::UNKNOWN
            && $observation->evidence === EvidenceStatus::INCOMPLETE) {
            return $this->environmentAction($desired->name, PlanOperation::UNSUPPORTED,
                'Remote branch information is unavailable.', $remote->id);
        }
        if ($observation->observation === ObservationKind::CONFIGURATION_DIFFERENCE) {
            if ($remote->branch === null) {
                throw new \LogicException('Complete branch-difference evidence must contain the remote branch.');
            }
            if ($observation->reconciliation === ReconciliationStatus::BLOCKED
                && $observation->ownership === OwnershipStatus::UNMANAGED) {
                return $this->environmentAction(
                    $desired->name,
                    PlanOperation::UNSUPPORTED,
                    'Matching remote environment is unmanaged. Import it before reconciling its branch.',
                    $remote->id,
                );
            }
            if ($observation->reconciliation !== ReconciliationStatus::SUPPORTED
                || $observation->ownership !== OwnershipStatus::MANAGED) {
                throw new \LogicException('Branch-difference observation has incompatible reconciliation semantics.');
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

        $this->requireObservation(
            $observation,
            ObservationKind::IN_SYNC,
            $observation->ownership,
        );
        if (!in_array($observation->ownership, [OwnershipStatus::MANAGED, OwnershipStatus::UNMANAGED], true)) {
            throw new \LogicException('In-sync Environment observation must have managed or unmanaged ownership.');
        }
        if ($observation->reconciliation !== ReconciliationStatus::NOT_APPLICABLE) {
            throw new \LogicException('In-sync Environment observation must not require reconciliation.');
        }

        return $this->environmentAction(
            $desired->name,
            PlanOperation::NO_CHANGE,
            $observation->ownership === OwnershipStatus::MANAGED
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
        $observation = $this->observationRecorder->record($this->variableObservations->create(
            $address,
            $desired,
            $desiredValue,
            $remoteVariables === null
                ? EnvironmentVariableObservationEvidence::collectionUnavailable()
                : EnvironmentVariableObservationEvidence::available($remoteVariables),
        ));
        if ($remoteVariables === null) {
            $this->requireObservation($observation, ObservationKind::UNKNOWN, OwnershipStatus::NONE);
            return $this->variableAction($address, PlanOperation::UNSUPPORTED,
                'Remote environment variable information is unavailable.');
        }
        $remote = $remoteVariables->find($desired->name);
        if ($remote === null) {
            $this->requireObservation($observation, ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE);
            return $this->variableAction($address, PlanOperation::CREATE, 'Environment variable does not exist.');
        }
        if ($observation->observation === ObservationKind::CONFIGURATION_DIFFERENCE
            && $observation->reconciliation === ReconciliationStatus::SUPPORTED) {
            return $this->variableAction($address, PlanOperation::UPDATE,
                'Environment variable differs from desired state.');
        }
        $this->requireObservation($observation, ObservationKind::IN_SYNC, OwnershipStatus::NONE);
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

    private function applicationConflictReason(ResourceObservation $observation, string $reason): string
    {
        $this->requireObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT);
        return $reason;
    }

    private function missingApplicationReason(ResourceObservation $observation, bool $hasReplacement): string
    {
        $this->requireObservation(
            $observation,
            $hasReplacement ? ObservationKind::IDENTITY_REPLACEMENT : ObservationKind::IDENTITY_MISSING,
            OwnershipStatus::MANAGED,
        );

        return $hasReplacement
            ? 'Managed application remote identity is missing and a same-name unmanaged replacement exists. Import or state repair is required.'
            : 'Managed application remote identity is missing. State must be repaired before reconciliation.';
    }

    private function applicationNameConflictReason(ResourceObservation $observation): string
    {
        $this->requireObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED);
        return 'Managed application name differs from its blueprint address. Rename or state-move reconciliation is not supported.';
    }

    private function requireObservation(
        ResourceObservation $observation,
        ObservationKind $kind,
        OwnershipStatus $ownership,
    ): void {
        if ($observation->observation !== $kind || $observation->ownership !== $ownership) {
            throw new \LogicException('Typed observation is incompatible with the discovered planning evidence.');
        }
    }

    private function recordSimpleObservation(
        ResourceAddress $address,
        ObservationKind $kind,
        OwnershipStatus $ownership,
        ReconciliationStatus $reconciliation,
        EvidenceStatus $evidence,
    ): void {
        $this->observationRecorder->record(new ResourceObservation(
            $address,
            $kind,
            $ownership,
            $reconciliation,
            $evidence,
        ));
    }

    private function recordUnresolvedDesiredChildren(Blueprint $blueprint): void
    {
        foreach ($blueprint->environments as $environment) {
            $this->recordSimpleObservation(
                new ResourceAddress(ResourceType::ENVIRONMENT, $environment->name),
                ObservationKind::UNKNOWN,
                OwnershipStatus::UNKNOWN,
                ReconciliationStatus::BLOCKED,
                EvidenceStatus::INCOMPLETE,
            );
            foreach ($environment->variables as $variable) {
                $this->recordSimpleObservation(
                    $this->variableAddress($environment->name, $variable->name),
                    ObservationKind::UNKNOWN,
                    OwnershipStatus::NONE,
                    ReconciliationStatus::BLOCKED,
                    EvidenceStatus::INCOMPLETE,
                );
            }
        }
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
        $clusterCandidates = array_map(
            fn (CloudDatabaseCluster $cluster): DatabaseClusterCandidate => new DatabaseClusterCandidate(
                $cluster,
                $this->databaseStatuses->destructiveReadiness($cluster->status),
            ),
            $remoteClusters,
        );
        $clusterActions = [];
        $databaseActions = [];
        /** @var array<string, array<string, CloudDatabase>> $resolvedDatabases */
        $resolvedDatabases = [];

        foreach ($blueprint->databaseClusters as $desiredCluster) {
            $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, $desiredCluster->name);
            $managedCluster = $state->find($clusterAddress);
            $clusterObservation = $this->observationRecorder->record($this->databaseClusterObservations->create(
                $desiredCluster,
                $managedCluster,
                new DatabaseClusterObservationEvidence($clusterCandidates, EvidenceStatus::COMPLETE),
            ));
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
                $this->requireObservation(
                    $clusterObservation,
                    $managedCluster === null
                        ? ObservationKind::DESIRED_RESOURCE_MISSING
                        : ($this->hasClusterNamed($remoteClusters, $desiredCluster->name)
                            ? ObservationKind::IDENTITY_REPLACEMENT
                            : ObservationKind::IDENTITY_MISSING),
                    $managedCluster === null ? OwnershipStatus::NONE : OwnershipStatus::MANAGED,
                );
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
                        $this->recordSimpleObservation(
                            new ResourceAddress(
                                ResourceType::DATABASE,
                                $desiredCluster->name . '.' . $desiredDatabase->name,
                            ),
                            ObservationKind::DESIRED_RESOURCE_MISSING,
                            OwnershipStatus::NONE,
                            ReconciliationStatus::SUPPORTED,
                            EvidenceStatus::COMPLETE,
                        );
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
                $this->requireObservation(
                    $clusterObservation,
                    ObservationKind::IDENTITY_CONFLICT,
                    $managedCluster === null ? OwnershipStatus::UNMANAGED : OwnershipStatus::CONFLICT,
                );
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
                $this->requireObservation($clusterObservation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED);
                $clusterActions[] = $this->databaseClusterAction(
                    $desiredCluster->name,
                    PlanOperation::UNSUPPORTED,
                    'Owned Database Cluster name differs from its blueprint address. Rename or state-move reconciliation is not supported.',
                );
                $this->unresolvedLogicalDatabaseActions($databaseActions, $desiredCluster,
                    'Logical Database cannot be compared while its owned parent Database Cluster identity conflicts.');
                continue;
            }
            $clusterAction = $this->databaseClusterActionFromObservation(
                $desiredCluster,
                $remoteCluster,
                $clusterObservation,
            );
            $clusterActions[] = $clusterAction;
            if ($clusterAction->operation !== PlanOperation::NO_CHANGE) {
                $this->unresolvedLogicalDatabaseActions($databaseActions, $desiredCluster,
                    'Logical Database cannot be compared while its parent Database Cluster is unresolved or unsupported.');
                continue;
            }

            $remoteDatabases = $cloud->databases($remoteCluster->id);
            $databaseParent = DatabaseParentEvidence::resolved(
                $clusterAddress,
                $remoteCluster->id,
                $managedCluster === null ? OwnershipStatus::UNMANAGED : OwnershipStatus::MANAGED,
            );
            foreach ($desiredCluster->databases as $desiredDatabase) {
                $name = $desiredCluster->name . '.' . $desiredDatabase->name;
                $databaseAddress = new ResourceAddress(ResourceType::DATABASE, $name);
                $managedDatabase = $state->find($databaseAddress);
                if ($managedDatabase?->isDerived() === true) {
                    $parentState = $managedDatabase->parent === null ? null : $state->find($managedDatabase->parent);
                    if ($parentState === null) {
                        throw new \LogicException('Derived Database State must have its owned Cluster parent.');
                    }
                    $observation = $this->observationRecorder->record($this->derivedDatabaseObservations->create(
                        $managedDatabase,
                        $parentState,
                        new DerivedDatabaseObservationEvidence(
                            $remoteDatabases,
                            $remoteCluster->databaseIds,
                            [],
                            EvidenceStatus::COMPLETE,
                            $remoteCluster->childDiscoveryComplete
                                ? EvidenceStatus::COMPLETE
                                : EvidenceStatus::INCOMPLETE,
                            blueprintAddressCollision: true,
                        ),
                    ));
                    $this->requireObservation($observation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::DERIVED);
                    $databaseActions[] = $this->databaseAction(
                        $name,
                        PlanOperation::UNSUPPORTED,
                        'A derived logical Database occupies a Blueprint-declared address. Derived-resource reconciliation is not implemented.',
                        $managedDatabase->remoteId,
                        $managedDatabase->parent,
                        null,
                        $managedDatabase->classification,
                        $managedDatabase->provenance,
                    );
                    continue;
                }
                $databaseObservation = $this->databaseObservations->create(
                    $desiredCluster->name,
                    $desiredDatabase,
                    $managedDatabase,
                    new LogicalDatabaseObservationEvidence(
                        $databaseParent,
                        $remoteDatabases,
                        EvidenceStatus::COMPLETE,
                        observeRelationships: false,
                    ),
                );
                $this->observationRecorder->record($this->observationRecorder->isReporting()
                    ? $this->databaseObservations->create(
                        $desiredCluster->name,
                        $desiredDatabase,
                        $managedDatabase,
                        new LogicalDatabaseObservationEvidence(
                            $databaseParent,
                            $remoteDatabases,
                            EvidenceStatus::COMPLETE,
                        ),
                    )
                    : $databaseObservation);
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
                    $this->requireObservation(
                        $databaseObservation,
                        $managedDatabase === null
                            ? ($createAllowed ? ObservationKind::DESIRED_RESOURCE_MISSING : ObservationKind::UNKNOWN)
                            : ($this->hasDatabaseNamed($remoteDatabases, $desiredDatabase->name)
                                ? ObservationKind::IDENTITY_REPLACEMENT
                                : ObservationKind::IDENTITY_MISSING),
                        $managedDatabase === null ? OwnershipStatus::NONE : OwnershipStatus::MANAGED,
                    );
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
                    $this->requireObservation(
                        $databaseObservation,
                        ObservationKind::IDENTITY_CONFLICT,
                        $managedDatabase === null ? OwnershipStatus::UNMANAGED : OwnershipStatus::CONFLICT,
                    );
                    $databaseActions[] = $this->databaseAction($name, PlanOperation::UNSUPPORTED,
                        'Multiple matching logical Databases exist within the Cluster; selection would be ambiguous.');
                    continue;
                }

                if ($databaseMatches[0]->name !== $desiredDatabase->name) {
                    $this->requireObservation($databaseObservation, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED);
                    $databaseActions[] = $this->databaseAction($name, PlanOperation::UNSUPPORTED,
                        'Owned logical Database name differs from its blueprint address. Rename or state-move reconciliation is not supported.');
                    continue;
                }

                if ($databaseObservation->observation === ObservationKind::IDENTITY_CONFLICT) {
                    $databaseActions[] = $this->databaseAction(
                        $name,
                        PlanOperation::UNSUPPORTED,
                        'Owned logical Database belongs to an unexpected Database Cluster. Automatic reparenting is not supported.',
                    );
                    continue;
                }
                $this->requireObservation(
                    $databaseObservation,
                    ObservationKind::IN_SYNC,
                    $managedDatabase === null ? OwnershipStatus::UNMANAGED : OwnershipStatus::MANAGED,
                );

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

        $this->ownedOnlyDatabaseActions($clusterActions, $databaseActions, $blueprint, $state, $cloud);

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
        LaravelCloudDatabaseClient $cloud,
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
                $dependencies = $hasDesiredChild
                    ? null
                    : $this->databaseClusterDependencies($resource, $state, $cloud);
                $readiness = $dependencies?->readiness();
                $this->recordSimpleObservation(
                    $resource->address,
                    ObservationKind::DESIRED_RESOURCE_ABSENT,
                    OwnershipStatus::MANAGED,
                    $hasDesiredChild || $readiness?->value !== 'safe'
                        ? ReconciliationStatus::BLOCKED
                        : ReconciliationStatus::UNSUPPORTED,
                    !$hasDesiredChild && $readiness?->value === 'unknown'
                        ? EvidenceStatus::INCOMPLETE
                        : EvidenceStatus::COMPLETE,
                );
                $clusterActions[] = $this->databaseClusterAction(
                    $resource->address->name,
                    $hasDesiredChild ? PlanOperation::UNSUPPORTED : PlanOperation::DELETE,
                    $hasDesiredChild
                        ? 'This Database Cluster is absent from the blueprint but still owns a desired logical Database. Parent deletion is structurally inconsistent.'
                        : match ($dependencies?->readiness()->value) {
                            'safe' => 'This State-owned Database Cluster is absent from the blueprint and is eligible for guarded child-first deletion.',
                            'blocked' => 'This State-owned Database Cluster is absent from the blueprint, but guarded deletion is blocked by discovered dependencies or lifecycle state.',
                            default => 'This State-owned Database Cluster is absent from the blueprint, but guarded deletion readiness is unknown because one or more prerequisites are incomplete.',
                        },
                    $resource->remoteId,
                    null,
                    $dependencies,
                    ...array_filter([$this->parentLifecycleDependency($resource, $state)]),
                );
            }
            if ($resource->type === ResourceType::DATABASE && !isset($desiredDatabases[$address])) {
                if ($resource->isDerived()) {
                    $databaseActions[] = $this->derivedDatabaseAction($resource, $state, $cloud);
                    continue;
                }
                $dependencies = $this->logicalDatabaseDependencies($resource, $state, $cloud);
                $this->recordSimpleObservation(
                    $resource->address,
                    ObservationKind::DESIRED_RESOURCE_ABSENT,
                    OwnershipStatus::MANAGED,
                    $dependencies->readiness()->value === 'safe'
                        ? ReconciliationStatus::SUPPORTED
                        : ReconciliationStatus::BLOCKED,
                    $dependencies->readiness()->value === 'unknown'
                        ? EvidenceStatus::INCOMPLETE
                        : EvidenceStatus::COMPLETE,
                );
                $databaseActions[] = $this->databaseAction(
                    $resource->address->name,
                    PlanOperation::DELETE,
                    match ($dependencies->readiness()->value) {
                        'safe' => 'This State-owned logical Database is absent from the blueprint. Dependency discovery is complete and it is eligible for guarded deletion.',
                        'blocked' => 'This State-owned logical Database is absent from the blueprint, but guarded deletion is blocked by discovered dependencies.',
                        default => 'This State-owned logical Database is absent from the blueprint, but deletion readiness is unknown because dependency discovery is incomplete.',
                    },
                    $resource->remoteId,
                    $resource->parent,
                    $dependencies,
                );
            }
        }
    }

    private function derivedDatabaseAction(
        StateResource $resource,
        StateDocument $state,
        LaravelCloudDatabaseClient $cloud,
    ): PlanAction {
        $parent = $resource->parent === null ? null : $state->find($resource->parent);
        if ($parent === null || $parent->type !== ResourceType::DATABASE_CLUSTER) {
            return $this->databaseAction(
                $resource->address->name,
                PlanOperation::UNSUPPORTED,
                'Derived logical Database ownership has an invalid parent and cannot be reconciled safely.',
                $resource->remoteId,
                $resource->parent,
                null,
                $resource->classification,
                $resource->provenance,
            );
        }

        $databases = [];
        $relationshipIds = [];
        $listCompleteness = EvidenceStatus::INCOMPLETE;
        $relationshipCompleteness = EvidenceStatus::INCOMPLETE;
        $relationshipConflict = false;
        try {
            $remoteCluster = $cloud->databaseCluster($parent->remoteId);
            $relationshipMatches = array_values(array_filter(
                $remoteCluster->databaseIds,
                static fn (string $databaseId): bool => $databaseId === $resource->remoteId,
            ));
            $relationshipIds = $remoteCluster->databaseIds;
            if ($remoteCluster->id !== $parent->remoteId) {
                $relationshipConflict = true;
                $relationshipCompleteness = EvidenceStatus::COMPLETE;
            } elseif ($remoteCluster->childDiscoveryComplete) {
                $relationshipCompleteness = EvidenceStatus::COMPLETE;
                $relationshipConflict = count($relationshipMatches) !== 1;
            }
            if ($relationshipCompleteness === EvidenceStatus::COMPLETE && !$relationshipConflict) {
                $databases = $cloud->databases($parent->remoteId);
                $listCompleteness = EvidenceStatus::COMPLETE;
            }
        } catch (CloudException) {
            // Completeness remains explicit; Cloud failure is not proof of absence.
        }
        $observation = $this->observationRecorder->record($this->derivedDatabaseObservations->create(
            $resource,
            $parent,
            new DerivedDatabaseObservationEvidence(
                $databases,
                $relationshipIds,
                [],
                $listCompleteness,
                $relationshipCompleteness,
                relationshipConflict: $relationshipConflict,
            ),
        ));
        if ($observation->observation === ObservationKind::UNKNOWN) {
            return $this->databaseAction(
                $resource->address->name,
                PlanOperation::UNSUPPORTED,
                'Derived logical Database discovery is incomplete under its exact State-owned Cluster; automatic recreation or adoption is forbidden.',
                $resource->remoteId,
                $resource->parent,
                null,
                $resource->classification,
                $resource->provenance,
            );
        }
        if ($observation->observation === ObservationKind::IDENTITY_MISSING
            || ($observation->observation === ObservationKind::IDENTITY_CONFLICT
                && $observation->ownership === OwnershipStatus::CONFLICT)) {
            return $this->databaseAction(
                $resource->address->name,
                PlanOperation::UNSUPPORTED,
                'Derived logical Database identity is missing or ambiguous under its exact State-owned Cluster; automatic recreation or adoption is forbidden.',
                $resource->remoteId,
                $resource->parent,
                null,
                $resource->classification,
                $resource->provenance,
            );
        }
        if ($observation->observation === ObservationKind::IDENTITY_CONFLICT) {
            return $this->databaseAction(
                $resource->address->name,
                PlanOperation::UNSUPPORTED,
                'Derived logical Database parent identity conflicts with its exact State-owned Cluster.',
                $resource->remoteId,
                $resource->parent,
                null,
                $resource->classification,
                $resource->provenance,
            );
        }

        $this->requireObservation($observation, ObservationKind::IN_SYNC, OwnershipStatus::DERIVED);

        return $this->databaseAction(
            $resource->address->name,
            PlanOperation::NO_CHANGE,
            'Cloud-created default Database is retained as derived infrastructure and is eligible only within guarded parent destruction.',
            $resource->remoteId,
            $resource->parent,
            null,
            $resource->classification,
            $resource->provenance,
            DatabaseDestructiveRole::PARENT_LIFECYCLE_DEPENDENCY,
        );
    }

    private function logicalDatabaseDependencies(
        StateResource $resource,
        StateDocument $state,
        LaravelCloudDatabaseClient $cloud,
    ): DatabaseDependencies {
        $parent = $resource->parent === null ? null : $state->find($resource->parent);
        if ($parent === null) {
            return new DatabaseDependencies(0, 0, 0, true, false, [], ['database']);
        }

        try {
            $database = $cloud->databaseWithDestructiveRelationships($parent->remoteId, $resource->remoteId);
        } catch (CloudResourceNotFoundException) {
            return new DatabaseDependencies(0, 0, 0, false, true);
        } catch (CloudException) {
            return new DatabaseDependencies(0, 0, 0, false, false, [], ['exact_database']);
        }

        $parentConflict = $database->relationshipClusterId !== null
            && $database->relationshipClusterId !== $parent->remoteId;

        return new DatabaseDependencies(
            count($database->environmentIds),
            0,
            0,
            $parentConflict,
            $database->destructiveRelationshipsComplete && !$parentConflict,
            $database->unknownRelationships,
            $database->missingRelationships,
        );
    }

    private function databaseClusterDependencies(
        StateResource $resource,
        StateDocument $state,
        LaravelCloudDatabaseClient $cloud,
    ): DatabaseDependencies {
        try {
            $cluster = $cloud->databaseCluster($resource->remoteId);
        } catch (CloudResourceNotFoundException) {
            if ($state->childrenOf($resource->address) === []) {
                return new DatabaseDependencies(0, 0, 0, false, true);
            }
            return new DatabaseDependencies(0, 0, 0, false, false, [], ['exact_database_cluster']);
        } catch (CloudException) {
            return new DatabaseDependencies(0, 0, 0, false, false, [], ['exact_database_cluster']);
        }

        $childrenById = [];
        $ownershipConflict = false;
        foreach ($state->childrenOf($resource->address) as $child) {
            if ($child->type === ResourceType::DATABASE) {
                $identityKey = 'id:' . $child->remoteId;
                if (isset($childrenById[$identityKey])) {
                    $ownershipConflict = true;
                }
                $childrenById[$identityKey] = $child;
            }
        }
        $databaseOwners = [];
        foreach ($state->resources() as $owned) {
            if ($owned->type === ResourceType::DATABASE) {
                $identityKey = 'id:' . $owned->remoteId;
                if (isset($databaseOwners[$identityKey])) {
                    $ownershipConflict = true;
                }
                $databaseOwners[$identityKey] = $owned;
            }
        }

        $missing = $cluster->missingRelationships;
        $unknown = $cluster->unknownRelationships;
        $remoteById = [];
        $conflictedRemoteIds = [];
        $databaseListComplete = true;
        try {
            foreach ($cloud->databases($resource->remoteId) as $database) {
                $identityKey = 'id:' . $database->id;
                if (isset($remoteById[$identityKey])) {
                    $ownershipConflict = true;
                    $conflictedRemoteIds[$identityKey] = true;
                    continue;
                }
                $remoteById[$identityKey] = $database;
            }
        } catch (CloudException) {
            $missing[] = 'databases';
            $databaseListComplete = false;
        }

        $ownedCount = 0;
        $derivedParentDependencyCount = 0;
        $unmanagedCount = 0;
        $relationshipIds = [];
        foreach ($cluster->databaseIds as $databaseId) {
            $identityKey = 'id:' . $databaseId;
            if (isset($relationshipIds[$identityKey])) {
                $ownershipConflict = true;
                continue;
            }
            $relationshipIds[$identityKey] = true;
            if (!$databaseListComplete) {
                continue;
            }
            $remote = $remoteById[$identityKey] ?? null;
            $child = $childrenById[$identityKey] ?? null;

            $classification = match (true) {
                isset($conflictedRemoteIds[$identityKey]) => DatabaseClusterChildClassification::CONFLICT,
                $remote === null => DatabaseClusterChildClassification::CONFLICT,
                $remote->clusterId !== $resource->remoteId => DatabaseClusterChildClassification::CONFLICT,
                $remote->relationshipClusterId !== null
                    && $remote->relationshipClusterId !== $resource->remoteId => DatabaseClusterChildClassification::CONFLICT,
                $child === null && isset($databaseOwners[$identityKey]) => DatabaseClusterChildClassification::CONFLICT,
                $child === null => DatabaseClusterChildClassification::UNMANAGED,
                $child->classification === StateOwnershipClassification::DERIVED
                    && $child->provenance === StateProvenance::CLUSTER_CREATE_RESPONSE
                    && $child->parent !== null
                    && (string) $child->parent === (string) $resource->address
                    && ($remote->relationshipClusterId === null
                        || $remote->relationshipClusterId === $resource->remoteId)
                    => DatabaseClusterChildClassification::DERIVED_PARENT_DEPENDENCY,
                $child->classification === StateOwnershipClassification::DERIVED
                    => DatabaseClusterChildClassification::CONFLICT,
                default => DatabaseClusterChildClassification::BLUEPRINT_OWNED,
            };

            match ($classification) {
                DatabaseClusterChildClassification::BLUEPRINT_OWNED => ++$ownedCount,
                DatabaseClusterChildClassification::DERIVED_PARENT_DEPENDENCY => ++$derivedParentDependencyCount,
                DatabaseClusterChildClassification::UNMANAGED => ++$unmanagedCount,
                DatabaseClusterChildClassification::CONFLICT => $ownershipConflict = true,
            };
        }
        if ($databaseListComplete) {
            foreach ($remoteById as $identityKey => $_remote) {
                if (!isset($relationshipIds[$identityKey])) {
                    $ownershipConflict = true;
                }
            }
            foreach ($childrenById as $identityKey => $child) {
                if ($child->classification === StateOwnershipClassification::DERIVED
                    && !isset($relationshipIds[$identityKey])) {
                    $ownershipConflict = true;
                }
            }
        }

        $snapshots = [];
        $snapshotDiscoveryComplete = true;
        if (!$cloud instanceof LaravelCloudDatabaseLifecycleClient) {
            $missing[] = 'snapshots';
            $snapshotDiscoveryComplete = false;
        } else {
            try {
                $snapshots = $cloud->databaseSnapshots($resource->remoteId);
            } catch (CloudException) {
                $missing[] = 'snapshots';
                $snapshotDiscoveryComplete = false;
            }
        }
        $manualSnapshotCount = 0;
        $scheduledSnapshotCount = 0;
        foreach ($snapshots as $snapshot) {
            if ($snapshot->type === DatabaseSnapshotType::MANUAL) {
                ++$manualSnapshotCount;
            } else {
                ++$scheduledSnapshotCount;
            }
            if ($snapshot->status === null) {
                $unknown[] = 'snapshot_status';
            }
        }

        $retainedRecovery = false;
        $recoveryEvidenceComplete = true;
        if ($cluster->configuration instanceof CloudLaravelMySqlConfiguration) {
            $retainedRecovery = $cluster->configuration->retentionDays > 0
                || $cluster->configuration->usesScheduledSnapshots;
        } elseif ($cluster->configuration instanceof CloudNeonPostgresConfiguration) {
            $retainedRecovery = $cluster->configuration->retentionDays > 0;
        } elseif ($cluster->configuration instanceof CloudUnknownDatabaseConfiguration) {
            $unknown[] = 'retained_recovery';
            $recoveryEvidenceComplete = false;
        }

        $lifecycle = $this->databaseStatuses->destructiveReadiness($cluster->status);
        if ($lifecycle === DatabaseClusterLifecycleReadiness::UNKNOWN) {
            $unknown[] = 'cluster_lifecycle';
        }

        $missing = array_values(array_unique($missing));
        $unknown = array_values(array_unique($unknown));

        return new DatabaseDependencies(
            0,
            $ownedCount,
            $unmanagedCount,
            $ownershipConflict,
            $cluster->childDiscoveryComplete && $databaseListComplete && !$ownershipConflict,
            $unknown,
            $missing,
            count($snapshots),
            $retainedRecovery,
            $manualSnapshotCount,
            $scheduledSnapshotCount,
            $snapshotDiscoveryComplete,
            $recoveryEvidenceComplete,
            $lifecycle,
            $derivedParentDependencyCount,
        );
    }

    private function databaseClusterActionFromObservation(
        DatabaseClusterDefinition $desired,
        CloudDatabaseCluster $remote,
        ResourceObservation $observation,
    ): PlanAction {
        $statusReason = $this->databaseStatuses->unsupportedReason($remote->status);
        if ($statusReason !== null) {
            if ($observation->observation === ObservationKind::UNKNOWN) {
                $this->requireObservation($observation, ObservationKind::UNKNOWN, $observation->ownership);
            } else {
                $this->requireObservation($observation, ObservationKind::LIFECYCLE_CONDITION, $observation->ownership);
            }
            return $this->databaseClusterAction($desired->name, PlanOperation::UNSUPPORTED, $statusReason);
        }
        if ($observation->observation === ObservationKind::CONFIGURATION_DIFFERENCE
            && in_array('type', $observation->changedFields->values(), true)) {
            return $this->databaseClusterAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote Database Cluster type differs or is not safely supported.',
                null,
                null,
                null,
                new PlanChange('type', $remote->type, $desired->type->value),
            );
        }
        if ($observation->observation === ObservationKind::CONFIGURATION_DIFFERENCE
            && in_array('region', $observation->changedFields->values(), true)) {
            return $this->databaseClusterAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote Database Cluster region differs. Database Cluster updates are not supported yet.',
                null,
                null,
                null,
                new PlanChange('region', $remote->region, $desired->region),
            );
        }

        $changes = $this->databaseConfigurationChanges($desired, $remote);
        if ($changes === null) {
            $this->requireObservation($observation, ObservationKind::UNKNOWN, $observation->ownership);
            return $this->databaseClusterAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote Database Cluster configuration is not safely comparable for this supported provider.',
            );
        }
        if ($changes !== []) {
            $this->requireObservation($observation, ObservationKind::CONFIGURATION_DIFFERENCE, $observation->ownership);
            return $this->databaseClusterAction(
                $desired->name,
                PlanOperation::UNSUPPORTED,
                'Remote Database Cluster configuration differs. Database Cluster updates are not supported yet.',
                null,
                null,
                null,
                ...$changes,
            );
        }

        $this->requireObservation($observation, ObservationKind::IN_SYNC, $observation->ownership);
        if (!in_array($observation->ownership, [OwnershipStatus::MANAGED, OwnershipStatus::UNMANAGED], true)) {
            throw new \LogicException('In-sync Database Cluster observation must be managed or unmanaged.');
        }

        return $this->databaseClusterAction(
            $desired->name,
            PlanOperation::NO_CHANGE,
            $observation->ownership === OwnershipStatus::MANAGED
                ? 'Owned remote Database Cluster matches desired state.'
                : 'Matching remote Database Cluster exists but is unmanaged; future mutation requires import and state ownership.',
            $observation->ownership === OwnershipStatus::MANAGED ? $remote->id : null,
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
            $this->recordSimpleObservation(
                new ResourceAddress(ResourceType::DATABASE, $cluster->name . '.' . $database->name),
                ObservationKind::UNKNOWN,
                OwnershipStatus::UNKNOWN,
                ReconciliationStatus::BLOCKED,
                EvidenceStatus::INCOMPLETE,
            );
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
        ?DatabaseDependencies $dependencies = null,
        PlanChange|DatabaseParentLifecycleDependency ...$details,
    ): PlanAction {
        return new PlanAction(
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, $name),
            ResourceType::DATABASE_CLUSTER,
            $operation,
            $reason,
            $remoteId,
            ...array_values(array_filter([$parent, $dependencies, ...$details])),
        );
    }

    private function parentLifecycleDependency(StateResource $cluster, StateDocument $state): ?DatabaseParentLifecycleDependency
    {
        $derived = array_values(array_filter(
            $state->childrenOf($cluster->address),
            static fn (StateResource $child): bool => $child->type === ResourceType::DATABASE && $child->isDerived(),
        ));
        if (count($derived) !== 1
            || $derived[0]->provenance !== StateProvenance::CLUSTER_CREATE_RESPONSE) {
            return null;
        }

        return new DatabaseParentLifecycleDependency(
            $derived[0]->address,
            $derived[0]->classification,
            $derived[0]->provenance,
            DatabaseDestructiveRole::PARENT_LIFECYCLE_DEPENDENCY,
        );
    }

    private function databaseAction(
        string $name,
        PlanOperation $operation,
        string $reason,
        ?string $remoteId = null,
        ?ResourceAddress $parent = null,
        ?DatabaseDependencies $dependencies = null,
        ?StateOwnershipClassification $classification = null,
        ?StateProvenance $provenance = null,
        ?DatabaseDestructiveRole $destructiveRole = null,
    ): PlanAction
    {
        return new PlanAction(
            new ResourceAddress(ResourceType::DATABASE, $name),
            ResourceType::DATABASE,
            $operation,
            $reason,
            $remoteId,
            ...array_values(array_filter([$parent, $dependencies, $classification, $provenance, $destructiveRole])),
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
