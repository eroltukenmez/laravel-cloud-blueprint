<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use Closure;
use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Apply\Exception\StateIdentityConflictException;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\NeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseMutationClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudEnvironmentMutationClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseClusterRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CreateNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDestructiveReadiness;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentVariableInput;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;

final readonly class CreateOnlyApply
{
    public function __construct(
        private VariableValueResolver $values,
        private DatabaseClusterReadiness $databaseReadiness = new DatabaseClusterReadiness(),
        private EnvironmentDeletionVerification $deletionVerification = new EnvironmentDeletionVerification(),
    ) {
    }

    public function execute(
        Blueprint $blueprint,
        ExecutionPlan $plan,
        LaravelCloudClient $cloud,
        StateStore $states,
        ?Closure $lockedBlueprintLoader = null,
    ): ApplyResult {
        $this->assertSupported($plan);
        if ($plan->countByOperation(PlanOperation::DELETE) > 0
            && !$cloud instanceof LaravelCloudEnvironmentMutationClient) {
            throw new ApplyRefusedException(
                'The configured Cloud client cannot delete Environment resources. No resources were modified.',
            );
        }
        $variableGroups = $this->variableGroups($blueprint, $plan);

        $transaction = $states->begin();

        try {
            $state = $transaction->load();
            if ($lockedBlueprintLoader !== null) {
                $lockedBlueprint = $lockedBlueprintLoader();
                if (!$lockedBlueprint instanceof Blueprint) {
                    throw new ApplyRefusedException('Locked Blueprint reload did not return a valid Blueprint. No resources were modified.');
                }
                $blueprint = $lockedBlueprint;
            }
            if ($this->hasDatabaseCreate($plan)) {
                if (!$cloud instanceof LaravelCloudDatabaseMutationClient) {
                    throw new ApplyRefusedException('The configured Cloud client cannot create Database resources. No resources were modified.');
                }
                $plannedDatabaseCreates = $this->databaseCreateActions($plan);
                try {
                    $freshPlan = (new CreatePlan($this->values))->create($blueprint, $cloud, $state);
                } catch (CloudException $exception) {
                    $action = $this->firstDatabaseCreate($plan);
                    return new ApplyResult(
                        $exception instanceof CloudTransportException ? ApplyStatus::PARTIAL_FAILURE : ApplyStatus::FAILED,
                        new ApplyResourceOutcome(
                            $action->address,
                            ApplyOutcomeOperation::FAILED,
                            'Locked Database revalidation failed before mutation: ' . $exception->getMessage(),
                            $exception instanceof CloudValidationException ? $exception : null,
                        ),
                    );
                }
                foreach ($plannedDatabaseCreates as $plannedCreate) {
                    $fresh = $this->actionAt($freshPlan, $plannedCreate->address);
                    if ($state->find($plannedCreate->address) === null
                        && ($fresh === null || $fresh->operation !== PlanOperation::CREATE)) {
                        return new ApplyResult(
                            ApplyStatus::FAILED,
                            new ApplyResourceOutcome(
                                $plannedCreate->address,
                                ApplyOutcomeOperation::FAILED,
                                'Database CREATE assumptions changed during locked revalidation; no POST was sent. Import any newly discovered resource before retrying.',
                            ),
                        );
                    }
                }
                foreach ($freshPlan as $freshAction) {
                    if ($freshAction->operation !== PlanOperation::CREATE
                        && $freshAction->operation !== PlanOperation::UPDATE) {
                        continue;
                    }
                    $approvedAction = $this->actionAt($plan, $freshAction->address);
                    if ($approvedAction === null || $approvedAction->operation !== $freshAction->operation) {
                        return new ApplyResult(
                            ApplyStatus::FAILED,
                            new ApplyResourceOutcome(
                                $freshAction->address,
                                ApplyOutcomeOperation::FAILED,
                                'A new actionable change appeared during locked Database revalidation; no mutation was sent. Run plan again for review.',
                            ),
                        );
                    }
                }
                $plan = $freshPlan;
                $this->assertSupported($plan);
            }
            $this->verifyState($blueprint, $plan, $state);
            $variableGroups = $this->variableGroups($blueprint, $plan);
            $outcomes = [];
            $applicationId = null;
            $environmentIds = [];
            $databaseClusterIds = [];
            /** @var array<string, CloudEnvironment> $implicitEnvironments */
            $implicitEnvironments = [];

            foreach ($plan as $action) {
                if ($action->resourceType === ResourceType::VARIABLE
                    || $action->resourceType === ResourceType::DATABASE_ATTACHMENT) {
                    continue;
                }

                if ($action->operation === PlanOperation::DELETE) {
                    if ($action->resourceType !== ResourceType::ENVIRONMENT) {
                        throw new ApplyRefusedException(sprintf(
                            '%s DELETE apply is not supported. No resources were modified.',
                            ucfirst($action->resourceType->value),
                        ));
                    }
                    if (!$cloud instanceof LaravelCloudEnvironmentMutationClient) {
                        throw new ApplyRefusedException('Environment DELETE capability changed during apply.');
                    }
                    $deleteResult = $this->deleteEnvironmentResource(
                        $blueprint,
                        $action,
                        $cloud,
                        $transaction,
                        $state,
                        $outcomes,
                    );
                    $state = $deleteResult['state'];
                    $outcomes = $deleteResult['outcomes'];
                    if ($deleteResult['failure'] !== null) {
                        return $deleteResult['failure'];
                    }
                    continue;
                }

                if ($action->operation === PlanOperation::NO_CHANGE) {
                    $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::UNCHANGED);
                    if ($action->resourceType === ResourceType::APPLICATION) {
                        $applicationId = $action->remoteId;
                    } elseif ($action->remoteId !== null) {
                        $environmentIds[$action->address->name] = $action->remoteId;
                    }
                    if ($action->resourceType === ResourceType::DATABASE_CLUSTER) {
                        $databaseClusterIds[$action->address->name] = $state->get($action->address)->remoteId;
                    }
                    continue;
                }

                if ($action->operation === PlanOperation::UPDATE) {
                    if ($this->isDatabaseResource($action->resourceType)) {
                        throw new ApplyRefusedException('Database UPDATE apply is not supported. No resources were modified.');
                    }
                    if ($action->resourceType === ResourceType::APPLICATION) {
                        throw new ApplyRefusedException('Application UPDATE apply is not supported. No resources were modified.');
                    }

                    $environmentId = $action->remoteId;
                    if ($environmentId === null) {
                        throw new ApplyRefusedException(sprintf(
                            'Environment update "%s" has no remote identity. No resources were modified.',
                            (string) $action->address,
                        ));
                    }
                    $environmentIds[$action->address->name] = $environmentId;
                    $desired = $blueprint->environments->get($action->address->name);

                    try {
                        $cloud->updateEnvironment($environmentId, new UpdateEnvironmentRequest($desired->branch));
                    } catch (CloudException $exception) {
                        return $this->updateFailure($outcomes, $action, $exception);
                    }

                    $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::UPDATED);
                    continue;
                }

                if ($action->resourceType === ResourceType::DATABASE_CLUSTER
                    || $action->resourceType === ResourceType::DATABASE) {
                    if (!$cloud instanceof LaravelCloudDatabaseMutationClient) {
                        throw new ApplyRefusedException('The configured Cloud client cannot create Database resources.');
                    }
                    $databaseResult = $this->createDatabaseResource(
                        $blueprint,
                        $action,
                        $cloud,
                        $transaction,
                        $state,
                        $databaseClusterIds,
                        $outcomes,
                    );
                    $state = $databaseResult['state'];
                    $databaseClusterIds = $databaseResult['cluster_ids'];
                    $outcomes = $databaseResult['outcomes'];
                    if ($databaseResult['failure'] !== null) {
                        return $databaseResult['failure'];
                    }
                    continue;
                }

                try {
                    if ($action->resourceType === ResourceType::APPLICATION) {
                        $created = $cloud->createApplication(new CreateApplicationRequest(
                            $blueprint->application->name,
                            $blueprint->application->source->repository,
                            $blueprint->application->region,
                            $blueprint->application->source->provider,
                        ));
                        $applicationId = $created->id;
                        $resource = new StateResource($action->address, ResourceType::APPLICATION, $created->id);
                    } else {
                        if ($applicationId === null) {
                            throw new StateStorageException('Unable to resolve the parent application ID.');
                        }
                        $desired = $blueprint->environments->get($action->address->name);
                        $implicit = $implicitEnvironments[$desired->name] ?? null;
                        if ($implicit === null) {
                            $created = $cloud->createEnvironment(
                                $applicationId,
                                new CreateEnvironmentRequest($desired->name, $desired->branch),
                            );
                            $environmentId = $created->id;
                        } else {
                            $environmentId = $implicit->id;
                        }
                        $environmentIds[$desired->name] = $environmentId;
                        $resource = new StateResource(
                            $action->address,
                            ResourceType::ENVIRONMENT,
                            $environmentId,
                            new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name),
                        );
                    }
                } catch (CloudException $exception) {
                    $outcomes[] = new ApplyResourceOutcome(
                        $action->address,
                        ApplyOutcomeOperation::FAILED,
                        $exception->getMessage(),
                        $exception instanceof CloudValidationException ? $exception : null,
                    );
                    $status = $this->confirmedMutationCount($outcomes) === 0 && !$exception instanceof CloudTransportException
                        ? ApplyStatus::FAILED
                        : ApplyStatus::PARTIAL_FAILURE;
                    return new ApplyResult($status, ...$outcomes);
                }

                try {
                    if ($state->organization === null) {
                        $state = $state->withOrganization($blueprint->organization);
                    }
                    $state = $transaction->save($state->withResource($resource));
                } catch (StateStorageException $exception) {
                    $outcomes[] = new ApplyResourceOutcome(
                        $action->address,
                        ApplyOutcomeOperation::FAILED,
                        'Remote resource was created but state checkpoint failed: ' . $exception->getMessage(),
                    );
                    return new ApplyResult(ApplyStatus::PARTIAL_FAILURE, ...$outcomes);
                }

                $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::CREATED);

                if ($action->resourceType === ResourceType::APPLICATION) {
                    $environmentCreateActions = $this->environmentCreateActions($plan);
                    if ($environmentCreateActions === []) {
                        continue;
                    }

                    try {
                        $remoteEnvironments = $cloud->environments($applicationId);
                    } catch (CloudException $exception) {
                        return $this->implicitEnvironmentFailure(
                            $outcomes,
                            $environmentCreateActions[0],
                            'Unable to reconcile environments created with the application: ' . $exception->getMessage(),
                            $exception instanceof CloudValidationException ? $exception : null,
                        );
                    }

                    foreach ($environmentCreateActions as $environmentAction) {
                        $matches = array_values(array_filter(
                            $remoteEnvironments,
                            static fn (CloudEnvironment $environment): bool => $environment->name === $environmentAction->address->name,
                        ));
                        if (count($matches) > 1) {
                            return $this->implicitEnvironmentFailure(
                                $outcomes,
                                $environmentAction,
                                sprintf(
                                    'Multiple environments named "%s" were discovered after application creation.',
                                    $environmentAction->address->name,
                                ),
                            );
                        }
                        if ($matches === []) {
                            continue;
                        }

                        $implicit = $matches[0];
                        $desired = $blueprint->environments->get($environmentAction->address->name);
                        if ($implicit->branch === null) {
                            return $this->implicitEnvironmentFailure(
                                $outcomes,
                                $environmentAction,
                                sprintf(
                                    'Branch information is unavailable for implicitly created environment "%s".',
                                    $desired->name,
                                ),
                            );
                        }
                        if ($implicit->branch !== $desired->branch) {
                            return $this->implicitEnvironmentFailure(
                                $outcomes,
                                $environmentAction,
                                sprintf(
                                    'Implicitly created environment "%s" uses a different branch.',
                                    $desired->name,
                                ),
                            );
                        }

                        // This is not general import: adoption is limited to a
                        // verified side effect of this LCB-managed application CREATE.
                        $implicitEnvironments[$desired->name] = $implicit;
                    }
                }
            }

            foreach ($variableGroups as $environmentName => $group) {
                $mutationActions = array_values(array_filter(
                    $group,
                    static fn (VariableApplyAction $item): bool => $item->action->operation === PlanOperation::CREATE
                        || $item->action->operation === PlanOperation::UPDATE,
                ));

                if ($mutationActions === []) {
                    foreach ($group as $item) {
                        $outcomes[] = new ApplyResourceOutcome($item->action->address, ApplyOutcomeOperation::UNCHANGED);
                    }
                    continue;
                }

                $environmentId = $environmentIds[$environmentName] ?? null;
                if ($environmentId === null) {
                    return $this->variableFailure(
                        $outcomes,
                        $group,
                        sprintf('Unable to resolve the remote identity for environment "%s".', $environmentName),
                    );
                }

                try {
                    $inputs = [];
                    foreach ($mutationActions as $item) {
                        $inputs[] = new EnvironmentVariableInput(
                            $item->definition->name,
                            $this->values->resolve($item->definition, $item->action->address),
                        );
                    }
                    $request = new SetEnvironmentVariablesRequest(...$inputs);
                } catch (MissingEnvironmentValueException $exception) {
                    return $this->variableFailure($outcomes, $group, $exception->getMessage());
                }

                try {
                    $cloud->setEnvironmentVariables($environmentId, $request);
                } catch (CloudException $exception) {
                    unset($request, $inputs);
                    return $this->variableFailure(
                        $outcomes,
                        $group,
                        $exception->getMessage(),
                        $exception instanceof CloudTransportException,
                        $exception instanceof CloudValidationException ? $exception : null,
                    );
                }
                unset($request, $inputs);

                foreach ($group as $item) {
                    $outcomes[] = new ApplyResourceOutcome(
                        $item->action->address,
                        $item->action->operation === PlanOperation::CREATE
                            ? ApplyOutcomeOperation::CREATED
                            : ($item->action->operation === PlanOperation::UPDATE
                                ? ApplyOutcomeOperation::UPDATED
                                : ApplyOutcomeOperation::UNCHANGED),
                    );
                }
            }

            return new ApplyResult(ApplyStatus::SUCCESS, ...$outcomes);
        } finally {
            $transaction->release();
        }
    }

    public function assertSupported(ExecutionPlan $plan): void
    {
        if ($plan->countByOperation(PlanOperation::UNSUPPORTED) > 0) {
            throw new ApplyRefusedException('The plan contains unsupported changes. No resources were modified.');
        }

        foreach ($plan as $action) {
            if ($action->operation === PlanOperation::DELETE) {
                if ($action->resourceType !== ResourceType::ENVIRONMENT) {
                    throw new ApplyRefusedException(sprintf(
                        '%s DELETE apply is not supported because destructive execution is not enabled for this resource type. No resources were modified.',
                        ucfirst($action->resourceType->value),
                    ));
                }

                if ($action->environmentDependencies === null) {
                    throw new ApplyRefusedException(sprintf(
                        'Environment deletion "%s" is refused because dependency discovery is unavailable. No resources were modified.',
                        (string) $action->address,
                    ));
                }

                $readiness = $action->environmentDependencies->readiness();
                if ($readiness === EnvironmentDestructiveReadiness::BLOCKED) {
                    throw new ApplyRefusedException(sprintf(
                        'Environment deletion "%s" is blocked by existing dependencies (%s). No resources were modified.',
                        (string) $action->address,
                        implode(', ', array_map(
                            static fn ($cat): string => $cat->value,
                            $action->environmentDependencies->blockingCategories(),
                        )),
                    ));
                }
                if ($readiness === EnvironmentDestructiveReadiness::UNKNOWN) {
                    throw new ApplyRefusedException(sprintf(
                        'Environment deletion "%s" is refused because dependency discovery is incomplete. No resources were modified.',
                        (string) $action->address,
                    ));
                }
            }

            if ($action->resourceType === ResourceType::DATABASE_ATTACHMENT
                && $action->operation !== PlanOperation::NO_CHANGE) {
                throw new ApplyRefusedException('Database attachment mutation is not supported. No resources were modified.');
            }
            if (($action->resourceType === ResourceType::DATABASE_CLUSTER
                    || $action->resourceType === ResourceType::DATABASE)
                && $action->operation !== PlanOperation::NO_CHANGE
                && $action->operation !== PlanOperation::CREATE) {
                throw new ApplyRefusedException('Only Database CREATE actions are supported. No resources were modified.');
            }

            if ($action->operation === PlanOperation::UPDATE && $action->resourceType === ResourceType::APPLICATION) {
                throw new ApplyRefusedException('Application UPDATE apply is not supported. No resources were modified.');
            }

            if ($action->operation === PlanOperation::UPDATE && $action->resourceType === ResourceType::ENVIRONMENT) {
                if (count($action->changes) !== 1 || $action->changes[0]->field !== 'branch') {
                    throw new ApplyRefusedException(sprintf(
                        'Environment update "%s" contains unsupported field changes. No resources were modified.',
                        (string) $action->address,
                    ));
                }
                if ($action->remoteId === null) {
                    throw new ApplyRefusedException(sprintf(
                        'Environment update "%s" has no remote identity. No resources were modified.',
                        (string) $action->address,
                    ));
                }
            }
        }

        $this->assertNoCreateAndUpdateForSameEnvironment($plan);
    }

    private function hasDatabaseCreate(ExecutionPlan $plan): bool
    {
        foreach ($plan as $action) {
            if (($action->resourceType === ResourceType::DATABASE_CLUSTER
                    || $action->resourceType === ResourceType::DATABASE)
                && $action->operation === PlanOperation::CREATE) {
                return true;
            }
        }
        return false;
    }

    private function firstDatabaseCreate(ExecutionPlan $plan): PlanAction
    {
        foreach ($plan as $action) {
            if (($action->resourceType === ResourceType::DATABASE_CLUSTER
                    || $action->resourceType === ResourceType::DATABASE)
                && $action->operation === PlanOperation::CREATE) {
                return $action;
            }
        }
        throw new \LogicException('Database CREATE plan contains no Database CREATE action.');
    }

    /** @return list<PlanAction> */
    private function databaseCreateActions(ExecutionPlan $plan): array
    {
        return array_values(array_filter(
            iterator_to_array($plan, false),
            static fn (PlanAction $action): bool => ($action->resourceType === ResourceType::DATABASE_CLUSTER
                    || $action->resourceType === ResourceType::DATABASE)
                && $action->operation === PlanOperation::CREATE,
        ));
    }

    private function actionAt(ExecutionPlan $plan, ResourceAddress $address): ?PlanAction
    {
        foreach ($plan as $action) {
            if ((string) $action->address === (string) $address) {
                return $action;
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $clusterIds
     * @param list<ApplyResourceOutcome> $outcomes
     * @return array{
     *   state: StateDocument,
     *   cluster_ids: array<string, string>,
     *   outcomes: list<ApplyResourceOutcome>,
     *   failure: ApplyResult|null
     * }
     */
    private function createDatabaseResource(
        Blueprint $blueprint,
        PlanAction $action,
        LaravelCloudDatabaseMutationClient $cloud,
        StateTransaction $transaction,
        StateDocument $state,
        array $clusterIds,
        array $outcomes,
    ): array {
        if ($state->find($action->address) !== null) {
            return $this->databaseCreateFailure(
                $action,
                'Local state began owning this Database address after planning; no create request was sent.',
                $state,
                $clusterIds,
                $outcomes,
            );
        }

        if ($action->resourceType === ResourceType::DATABASE_CLUSTER) {
            $desired = $blueprint->databaseClusters->get($action->address->name);
            try {
                foreach ($cloud->databaseClusters() as $remote) {
                    if ($remote->name === $desired->name) {
                        return $this->databaseCreateFailure(
                            $action,
                            'A matching unmanaged Database Cluster appeared during locked revalidation; import is required.',
                            $state,
                            $clusterIds,
                            $outcomes,
                        );
                    }
                }
                $created = $cloud->createDatabaseCluster(new CreateDatabaseClusterRequest(
                    $desired->name,
                    $desired->type->value,
                    $desired->region,
                    $this->databaseCreateConfiguration($desired->configuration),
                ));
            } catch (CloudException $exception) {
                return $this->databaseCloudFailure($action, $exception, $state, $clusterIds, $outcomes);
            }

            if ($created->name !== $desired->name
                || $created->type !== $desired->type->value
                || $created->region !== $desired->region) {
                return $this->databaseCreateFailure(
                    $action,
                    'Database Cluster create returned an incompatible identity; the remote outcome requires inspection and explicit import.',
                    $state,
                    $clusterIds,
                    $outcomes,
                    true,
                );
            }

            try {
                $state = $this->checkpointDatabaseResource(
                    $blueprint,
                    $transaction,
                    $state,
                    new StateResource($action->address, ResourceType::DATABASE_CLUSTER, $created->id),
                );
            } catch (StateStorageException $exception) {
                return $this->databaseCreateFailure(
                    $action,
                    'Remote Database Cluster was created but its local ownership checkpoint failed; inspect Cloud and use import before retrying.',
                    $state,
                    $clusterIds,
                    $outcomes,
                    true,
                );
            }

            $clusterIds[$desired->name] = $created->id;
            $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::CREATED);
            try {
                $this->databaseReadiness->wait($cloud, $created);
            } catch (CloudException $exception) {
                $next = $this->firstDatabaseCreateForCluster($blueprint, $desired->name);
                if ($next !== null) {
                    $outcomes[] = new ApplyResourceOutcome($next, ApplyOutcomeOperation::FAILED, $exception->getMessage());
                }
                return [
                    'state' => $state,
                    'cluster_ids' => $clusterIds,
                    'outcomes' => $outcomes,
                    'failure' => new ApplyResult(ApplyStatus::PARTIAL_FAILURE, ...$outcomes),
                ];
            }

            return ['state' => $state, 'cluster_ids' => $clusterIds, 'outcomes' => $outcomes, 'failure' => null];
        }

        [$clusterName, $databaseName] = explode('.', $action->address->name, 2);
        $clusterId = $clusterIds[$clusterName] ?? null;
        if ($clusterId === null) {
            return $this->databaseCreateFailure(
                $action,
                'Authoritative parent Database Cluster identity is unavailable; no create request was sent.',
                $state,
                $clusterIds,
                $outcomes,
            );
        }

        try {
            foreach ($cloud->databases($clusterId) as $remote) {
                if ($remote->name === $databaseName) {
                    return $this->databaseCreateFailure(
                        $action,
                        'A matching unmanaged logical Database appeared during locked revalidation; import is required.',
                        $state,
                        $clusterIds,
                        $outcomes,
                    );
                }
            }
            $created = $cloud->createDatabase($clusterId, new CreateDatabaseRequest($databaseName));
        } catch (CloudException $exception) {
            return $this->databaseCloudFailure($action, $exception, $state, $clusterIds, $outcomes);
        }

        if ($created->clusterId !== $clusterId || $created->name !== $databaseName) {
            return $this->databaseCreateFailure(
                $action,
                'Logical Database create returned an incompatible identity; the remote outcome requires inspection and explicit import.',
                $state,
                $clusterIds,
                $outcomes,
                true,
            );
        }

        try {
            $state = $this->checkpointDatabaseResource(
                $blueprint,
                $transaction,
                $state,
                new StateResource(
                    $action->address,
                    ResourceType::DATABASE,
                    $created->id,
                    new ResourceAddress(ResourceType::DATABASE_CLUSTER, $clusterName),
                ),
            );
        } catch (StateStorageException) {
            return $this->databaseCreateFailure(
                $action,
                'Remote logical Database was created but its local ownership checkpoint failed; inspect Cloud and use import before retrying.',
                $state,
                $clusterIds,
                $outcomes,
                true,
            );
        }

        $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::CREATED);
        return ['state' => $state, 'cluster_ids' => $clusterIds, 'outcomes' => $outcomes, 'failure' => null];
    }

    private function databaseCreateConfiguration(
        \LaravelCloudBlueprint\Blueprint\DatabaseClusterConfiguration $configuration,
    ): \LaravelCloudBlueprint\Cloud\DTO\DatabaseClusterCreateConfiguration {
        return match (true) {
            $configuration instanceof LaravelMySqlConfiguration => new CreateLaravelMySqlConfiguration(
                $configuration->size,
                $configuration->storage,
                $configuration->retentionDays,
                $configuration->usesScheduledSnapshots,
                $configuration->isPublic,
            ),
            $configuration instanceof NeonPostgresConfiguration => new CreateNeonPostgresConfiguration(
                $configuration->minimumComputeUnits,
                $configuration->maximumComputeUnits,
                $configuration->suspendSeconds,
                $configuration->retentionDays,
            ),
            default => throw new \LogicException('Unsupported Database Cluster create configuration.'),
        };
    }

    private function checkpointDatabaseResource(
        Blueprint $blueprint,
        StateTransaction $transaction,
        StateDocument $state,
        StateResource $resource,
    ): StateDocument {
        if ($state->organization === null) {
            $state = $state->withOrganization($blueprint->organization);
        }
        return $transaction->save($state->withResource($resource));
    }

    private function firstDatabaseCreateForCluster(Blueprint $blueprint, string $cluster): ?ResourceAddress
    {
        foreach ($blueprint->databaseClusters->get($cluster)->databases as $database) {
            return new ResourceAddress(ResourceType::DATABASE, $cluster . '.' . $database->name);
        }
        return null;
    }

    /**
     * @param array<string, string> $clusterIds
     * @param list<ApplyResourceOutcome> $outcomes
     * @return array{state: StateDocument, cluster_ids: array<string, string>, outcomes: list<ApplyResourceOutcome>, failure: ApplyResult}
     */
    private function databaseCloudFailure(
        PlanAction $action,
        CloudException $exception,
        StateDocument $state,
        array $clusterIds,
        array $outcomes,
    ): array {
        return $this->databaseCreateFailure(
            $action,
            $exception->getMessage(),
            $state,
            $clusterIds,
            $outcomes,
            $exception instanceof CloudTransportException || $exception instanceof CloudResponseException,
            $exception instanceof CloudValidationException ? $exception : null,
        );
    }

    /**
     * @param array<string, string> $clusterIds
     * @param list<ApplyResourceOutcome> $outcomes
     * @return array{state: StateDocument, cluster_ids: array<string, string>, outcomes: list<ApplyResourceOutcome>, failure: ApplyResult}
     */
    private function databaseCreateFailure(
        PlanAction $action,
        string $message,
        StateDocument $state,
        array $clusterIds,
        array $outcomes,
        bool $uncertain = false,
        ?CloudValidationException $validation = null,
    ): array {
        $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::FAILED, $message, $validation);
        $status = $this->confirmedMutationCount($outcomes) === 0 && !$uncertain
            ? ApplyStatus::FAILED
            : ApplyStatus::PARTIAL_FAILURE;
        return [
            'state' => $state,
            'cluster_ids' => $clusterIds,
            'outcomes' => $outcomes,
            'failure' => new ApplyResult($status, ...$outcomes),
        ];
    }

    private function assertNoCreateAndUpdateForSameEnvironment(ExecutionPlan $plan): void
    {
        /** @var array<string, PlanOperation> $operations */
        $operations = [];
        foreach ($plan as $action) {
            if ($action->resourceType !== ResourceType::ENVIRONMENT
                || ($action->operation !== PlanOperation::CREATE && $action->operation !== PlanOperation::UPDATE)) {
                continue;
            }
            $address = (string) $action->address;
            $previous = $operations[$address] ?? null;
            if ($previous !== null && $previous !== $action->operation) {
                throw new ApplyRefusedException(sprintf(
                    'Environment "%s" cannot be created and updated in the same plan. No resources were modified.',
                    $address,
                ));
            }
            $operations[$address] = $action->operation;
        }
    }

    /** @return list<PlanAction> */
    private function environmentCreateActions(ExecutionPlan $plan): array
    {
        return array_values(array_filter(
            iterator_to_array($plan, false),
            static fn (PlanAction $action): bool => $action->resourceType === ResourceType::ENVIRONMENT
                && $action->operation === PlanOperation::CREATE,
        ));
    }

    /** @param list<ApplyResourceOutcome> $outcomes */
    private function implicitEnvironmentFailure(
        array $outcomes,
        PlanAction $action,
        string $message,
        ?CloudValidationException $validation = null,
    ): ApplyResult {
        $outcomes[] = new ApplyResourceOutcome(
            $action->address,
            ApplyOutcomeOperation::FAILED,
            $message,
            $validation,
        );

        return new ApplyResult(ApplyStatus::PARTIAL_FAILURE, ...$outcomes);
    }

    private function verifyState(Blueprint $blueprint, ExecutionPlan $plan, StateDocument $state): void
    {
        if ($state->organization !== null && $state->organization !== $blueprint->organization) {
            throw new StateIdentityConflictException('Local state organization does not match the blueprint organization.');
        }

        foreach ($plan as $action) {
            if ($action->resourceType === ResourceType::VARIABLE || $this->isDatabaseResource($action->resourceType)) {
                continue;
            }
            $managed = $state->find($action->address);
            if ($managed === null) {
                if ($action->resourceType === ResourceType::ENVIRONMENT
                    && ($action->operation === PlanOperation::UPDATE || $action->operation === PlanOperation::DELETE)) {
                    throw new StateIdentityConflictException(sprintf(
                        'Local state ownership for "%s" no longer exists.',
                        (string) $action->address,
                    ));
                }
                continue;
            }

            if ($managed->type !== $action->resourceType) {
                throw new StateIdentityConflictException(sprintf(
                    'Local state resource type for "%s" is invalid.',
                    (string) $action->address,
                ));
            }
            if ($action->resourceType === ResourceType::APPLICATION && $managed->parent !== null) {
                throw new StateIdentityConflictException(sprintf(
                    'Local state parent for "%s" is invalid.',
                    (string) $action->address,
                ));
            }
            if ($action->resourceType === ResourceType::ENVIRONMENT) {
                if ($action->operation === PlanOperation::DELETE) {
                    if ($managed->parent === null
                        || $managed->parent->type !== ResourceType::APPLICATION
                        || $state->find($managed->parent) === null) {
                        throw new StateIdentityConflictException(sprintf(
                            'Local state parent for "%s" is invalid.',
                            (string) $action->address,
                        ));
                    }
                    if ($action->parent !== null && (string) $managed->parent !== (string) $action->parent) {
                        throw new StateIdentityConflictException(sprintf(
                            'Local state parent for "%s" is invalid.',
                            (string) $action->address,
                        ));
                    }
                    $approvedParent = $this->actionAt($plan, $managed->parent);
                    $managedParent = $state->get($managed->parent);
                    if ($approvedParent === null
                        || $approvedParent->remoteId === null
                        || $approvedParent->remoteId !== $managedParent->remoteId) {
                        throw new StateIdentityConflictException(sprintf(
                            'Parent Application identity for "%s" changed after approval.',
                            (string) $action->address,
                        ));
                    }
                } else {
                    $expectedParent = new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name);
                    if ($managed->parent === null
                        || (string) $managed->parent !== (string) $expectedParent) {
                        throw new StateIdentityConflictException(sprintf(
                            'Local state parent for "%s" is invalid.',
                            (string) $action->address,
                        ));
                    }
                }
            }
            foreach ($state->resources() as $resource) {
                if ((string) $resource->address !== (string) $managed->address
                    && $resource->remoteId === $managed->remoteId) {
                    throw new StateIdentityConflictException(sprintf(
                        'Local state remote identity for "%s" is owned by more than one address.',
                        (string) $action->address,
                    ));
                }
            }

            if ($action->remoteId === null || $managed->remoteId !== $action->remoteId) {
                throw new StateIdentityConflictException(sprintf(
                    'Local state identity for "%s" conflicts with remote planning truth.',
                    (string) $action->address,
                ));
            }
        }
    }

    /** @return array<string, list<VariableApplyAction>> */
    private function variableGroups(Blueprint $blueprint, ExecutionPlan $plan): array
    {
        /** @var array<string, PlanAction> $variableActions */
        $variableActions = [];
        foreach ($plan as $action) {
            if ($action->resourceType !== ResourceType::VARIABLE) {
                continue;
            }

            $address = (string) $action->address;
            if (array_key_exists($address, $variableActions)) {
                throw new ApplyRefusedException(sprintf(
                    'Variable plan address "%s" appears more than once. No resources were modified.',
                    $address,
                ));
            }
            $variableActions[$address] = $action;
        }

        $groups = [];
        foreach ($blueprint->environments as $environment) {
            foreach ($environment->variables as $variable) {
                $address = 'variable.' . $environment->name . '.' . $variable->name;
                $action = $variableActions[$address] ?? null;
                if ($action !== null) {
                    $groups[$environment->name][] = new VariableApplyAction($environment->name, $variable, $action);
                    unset($variableActions[$address]);
                }
            }
        }

        $unmatched = reset($variableActions);
        if ($unmatched instanceof PlanAction) {
            throw new ApplyRefusedException(sprintf(
                'Variable plan address "%s" does not exist in the blueprint. No resources were modified.',
                (string) $unmatched->address,
            ));
        }

        return $groups;
    }

    private function isDatabaseResource(ResourceType $type): bool
    {
        return $type === ResourceType::DATABASE_CLUSTER
            || $type === ResourceType::DATABASE
            || $type === ResourceType::DATABASE_ATTACHMENT;
    }

    /**
     * @param list<ApplyResourceOutcome> $outcomes
     * @param list<VariableApplyAction> $group
     */
    private function variableFailure(
        array $outcomes,
        array $group,
        string $message,
        bool $uncertain = false,
        ?CloudValidationException $validation = null,
    ): ApplyResult {
        foreach ($group as $item) {
            $mutation = $item->action->operation === PlanOperation::CREATE
                || $item->action->operation === PlanOperation::UPDATE;
            $outcomes[] = new ApplyResourceOutcome(
                $item->action->address,
                $mutation ? ApplyOutcomeOperation::FAILED : ApplyOutcomeOperation::UNCHANGED,
                $mutation ? $message : null,
                $mutation ? $validation : null,
            );
        }

        $status = $this->confirmedMutationCount($outcomes) === 0 && !$uncertain
            ? ApplyStatus::FAILED
            : ApplyStatus::PARTIAL_FAILURE;

        return new ApplyResult($status, ...$outcomes);
    }

    /** @param list<ApplyResourceOutcome> $outcomes */
    private function updateFailure(
        array $outcomes,
        PlanAction $action,
        CloudException $exception,
    ): ApplyResult {
        $outcomes[] = new ApplyResourceOutcome(
            $action->address,
            ApplyOutcomeOperation::FAILED,
            $exception->getMessage(),
            $exception instanceof CloudValidationException ? $exception : null,
        );
        $status = $this->confirmedMutationCount($outcomes) === 0
            && !$exception instanceof CloudTransportException
            ? ApplyStatus::FAILED
            : ApplyStatus::PARTIAL_FAILURE;

        return new ApplyResult($status, ...$outcomes);
    }

    /** @param list<ApplyResourceOutcome> $outcomes */
    private function confirmedMutationCount(array $outcomes): int
    {
        return count(array_filter(
            $outcomes,
            static fn (ApplyResourceOutcome $outcome): bool => $outcome->operation === ApplyOutcomeOperation::CREATED
                || $outcome->operation === ApplyOutcomeOperation::UPDATED
                || $outcome->operation === ApplyOutcomeOperation::DELETED,
        ));
    }

    /**
     * @param list<ApplyResourceOutcome> $outcomes
     * @return array{
     *   state: StateDocument,
     *   outcomes: list<ApplyResourceOutcome>,
     *   failure: ApplyResult|null
     * }
     */
    private function deleteEnvironmentResource(
        Blueprint $blueprint,
        PlanAction $action,
        LaravelCloudEnvironmentMutationClient $cloud,
        StateTransaction $transaction,
        StateDocument $state,
        array $outcomes,
    ): array {
        $managed = $state->find($action->address);
        if ($managed === null) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf('Local state ownership for "%s" no longer exists.', (string) $action->address),
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        if ($managed->type !== ResourceType::ENVIRONMENT) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf('Local state resource type for "%s" is invalid.', (string) $action->address),
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        if ($action->remoteId === null || $managed->remoteId !== $action->remoteId) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf('Local state remote identity for "%s" conflicts with approved plan.', (string) $action->address),
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        if ($managed->parent === null || $managed->parent->type !== ResourceType::APPLICATION) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf('Local state parent for "%s" is invalid.', (string) $action->address),
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        $parentManaged = $state->find($managed->parent);
        if ($parentManaged === null || $parentManaged->type !== ResourceType::APPLICATION) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf('Parent Application "%s" is not owned in state.', (string) $managed->parent),
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        if ($action->parent !== null && (string) $managed->parent !== (string) $action->parent) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf('Parent address for "%s" changed after approval.', (string) $action->address),
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        $blueprintParent = new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name);
        if ((string) $managed->parent !== (string) $blueprintParent) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                'Blueprint Application identity changed after approval.',
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        if ($blueprint->environments->has($action->address->name)) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf('Environment "%s" is declared in the blueprint and cannot be deleted.', $action->address->name),
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        if ($state->childrenOf($action->address) !== []) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf('State resource "%s" cannot be deleted while it has owned children.', (string) $action->address),
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        foreach ($state->resources() as $resource) {
            if ((string) $resource->address !== (string) $managed->address && $resource->remoteId === $managed->remoteId) {
                $outcomes[] = new ApplyResourceOutcome(
                    $action->address,
                    ApplyOutcomeOperation::FAILED,
                    sprintf('Remote identity for "%s" is owned by multiple addresses.', (string) $action->address),
                    destructiveOutcome: DestructiveOutcome::CONFLICT,
                    deleted: false,
                    confirmed: false,
                    stateCheckpointed: false,
                );
                return [
                    'state' => $state,
                    'outcomes' => $outcomes,
                    'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
                ];
            }
        }

        try {
            $remoteEnvironments = $cloud->environments($parentManaged->remoteId);
        } catch (CloudException $exception) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                'Locked Cloud rediscovery failed before mutation: ' . $exception->getMessage(),
                destructiveOutcome: DestructiveOutcome::UNCERTAIN,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        $remote = $this->findEnvironmentById($remoteEnvironments, $managed->remoteId);
        if ($remote === null) {
            try {
                $state = $transaction->save($state->withoutResource($action->address));
            } catch (StateStorageException $exception) {
                $outcomes[] = new ApplyResourceOutcome(
                    $action->address,
                    ApplyOutcomeOperation::FAILED,
                    'Environment was already absent remotely, but State checkpoint failed: ' . $exception->getMessage(),
                    destructiveOutcome: DestructiveOutcome::STATE_CHECKPOINT_FAILED,
                    deleted: false,
                    confirmed: true,
                    stateCheckpointed: false,
                );
                return [
                    'state' => $state,
                    'outcomes' => $outcomes,
                    'failure' => new ApplyResult(ApplyStatus::PARTIAL_FAILURE, ...$outcomes),
                ];
            }

            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::DELETED,
                'already absent; local State reconciled',
                destructiveOutcome: DestructiveOutcome::ALREADY_ABSENT,
                deleted: false,
                confirmed: true,
                stateCheckpointed: true,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => null,
            ];
        }

        if ($remote->applicationId !== $parentManaged->remoteId) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                'Environment parent Application did not match the locked State identity.',
                destructiveOutcome: DestructiveOutcome::CONFLICT,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        $dependencies = $remote->dependencies;
        $readiness = $dependencies->readiness();
        if ($readiness === EnvironmentDestructiveReadiness::BLOCKED) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                sprintf(
                    'Environment deletion refused: dependency discovery found: %s.',
                    implode(', ', array_map(
                        static fn ($cat): string => $cat->value,
                        $dependencies->blockingCategories(),
                    )),
                ),
                destructiveOutcome: DestructiveOutcome::REFUSED,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        if ($readiness === EnvironmentDestructiveReadiness::UNKNOWN) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                'Environment deletion refused: dependency discovery is incomplete or contains unknown relationships.',
                destructiveOutcome: DestructiveOutcome::REFUSED,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        $deleteException = null;
        try {
            $cloud->deleteEnvironment($remote->id);
        } catch (CloudException $exception) {
            $deleteException = $exception;
        }

        $verification = $this->deletionVerification->verifyAbsent($cloud, $parentManaged->remoteId, $remote->id);

        if ($verification === 'absent') {
            try {
                $state = $transaction->save($state->withoutResource($action->address));
            } catch (StateStorageException $exception) {
                $outcomes[] = new ApplyResourceOutcome(
                    $action->address,
                    ApplyOutcomeOperation::FAILED,
                    'Remote Environment was deleted but local State checkpoint failed: ' . $exception->getMessage(),
                    destructiveOutcome: DestructiveOutcome::STATE_CHECKPOINT_FAILED,
                    deleted: true,
                    confirmed: true,
                    stateCheckpointed: false,
                );
                return [
                    'state' => $state,
                    'outcomes' => $outcomes,
                    'failure' => new ApplyResult(ApplyStatus::PARTIAL_FAILURE, ...$outcomes),
                ];
            }

            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::DELETED,
                'deleted and confirmed',
                destructiveOutcome: DestructiveOutcome::DELETE_CONFIRMED,
                deleted: true,
                confirmed: true,
                stateCheckpointed: true,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => null,
            ];
        }

        if ($verification === 'present') {
            $message = $deleteException !== null
                ? 'Environment deletion failed: ' . $deleteException->getMessage()
                : 'Environment deletion was not confirmed: remote resource remains discoverable.';
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                $message,
                $deleteException instanceof CloudValidationException ? $deleteException : null,
                destructiveOutcome: DestructiveOutcome::UNCERTAIN,
                deleted: false,
                confirmed: false,
                stateCheckpointed: false,
            );
            return [
                'state' => $state,
                'outcomes' => $outcomes,
                'failure' => new ApplyResult(ApplyStatus::FAILED, ...$outcomes),
            ];
        }

        $outcomes[] = new ApplyResourceOutcome(
            $action->address,
            ApplyOutcomeOperation::FAILED,
            'Environment deletion outcome is uncertain: post-delete rediscovery failed.',
            destructiveOutcome: DestructiveOutcome::UNCERTAIN,
            deleted: null,
            confirmed: false,
            stateCheckpointed: false,
        );
        return [
            'state' => $state,
            'outcomes' => $outcomes,
            'failure' => new ApplyResult(ApplyStatus::PARTIAL_FAILURE, ...$outcomes),
        ];
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
}
