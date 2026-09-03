<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use Closure;
use InvalidArgumentException;
use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Apply\Exception\StateIdentityConflictException;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\NeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseMutationClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClusterDeletionClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudEnvironmentMutationClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudLogicalDatabaseDeletionClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencies;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencyType;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseClusterLifecycleReadiness;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDestructiveReadiness;
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
use LaravelCloudBlueprint\Cloud\Exception\CloudAuthenticationException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\DatabaseDestructiveRole;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\Resource\DerivedResource;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;

final readonly class CreateOnlyApply
{
    public function __construct(
        private VariableValueResolver $values,
        private DatabaseClusterReadiness $databaseReadiness = new DatabaseClusterReadiness(),
        private EnvironmentDeletionVerification $deletionVerification = new EnvironmentDeletionVerification(),
        private DatabaseDeletionVerification $databaseDeletionVerification = new DatabaseDeletionVerification(),
        private DatabaseClusterDeletionVerification $databaseClusterDeletionVerification = new DatabaseClusterDeletionVerification(),
        private DatabaseClusterDeletionReadiness $databaseClusterDeletionReadiness = new DatabaseClusterDeletionReadiness(),
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
        if ($this->hasDelete($plan, ResourceType::ENVIRONMENT)
            && !$cloud instanceof LaravelCloudEnvironmentMutationClient) {
            throw new ApplyRefusedException(
                'The configured Cloud client cannot delete Environment resources. No resources were modified.',
            );
        }
        if ($this->hasDelete($plan, ResourceType::DATABASE)
            && !$cloud instanceof LaravelCloudLogicalDatabaseDeletionClient) {
            throw new ApplyRefusedException(
                'The configured Cloud client cannot delete logical Database resources. No resources were modified.',
            );
        }
        if ($this->hasDelete($plan, ResourceType::DATABASE_CLUSTER)
            && !$cloud instanceof LaravelCloudDatabaseClusterDeletionClient) {
            throw new ApplyRefusedException('The configured Cloud client cannot delete Database Cluster resources. No resources were modified.');
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
            if ($this->hasDelete($plan, ResourceType::DATABASE_CLUSTER)) {
                try {
                    $lockedPlan = (new CreatePlan($this->values))->create($blueprint, $cloud, $state);
                } catch (CloudException $exception) {
                    throw new ApplyRefusedException('Locked Database Cluster approval-graph revalidation failed; no mutation was sent.');
                }
                $this->assertClusterApprovalGraphUnchanged($plan, $lockedPlan);
            }
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
                    if ($action->resourceType === ResourceType::DATABASE_CLUSTER) {
                        if (!$cloud instanceof LaravelCloudDatabaseClusterDeletionClient) {
                            throw new ApplyRefusedException('Database Cluster DELETE capability changed during apply.');
                        }
                        $deleteResult = $this->deleteDatabaseClusterResource($blueprint, $action, $cloud, $transaction, $state, $outcomes);
                        $state = $deleteResult['state'];
                        $outcomes = $deleteResult['outcomes'];
                        if ($deleteResult['failure'] !== null) {
                            return $deleteResult['failure'];
                        }
                        continue;
                    }
                    if ($action->resourceType === ResourceType::DATABASE) {
                        if (!$cloud instanceof LaravelCloudLogicalDatabaseDeletionClient) {
                            throw new ApplyRefusedException('Logical Database DELETE capability changed during apply.');
                        }
                        $deleteResult = $this->deleteDatabaseResource(
                            $blueprint,
                            $plan,
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
                    if ($action->destructiveRole === DatabaseDestructiveRole::PARENT_LIFECYCLE_DEPENDENCY
                        && $state->find($action->address) === null) {
                        continue;
                    }
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
                if ($action->resourceType !== ResourceType::ENVIRONMENT
                    && $action->resourceType !== ResourceType::DATABASE
                    && $action->resourceType !== ResourceType::DATABASE_CLUSTER) {
                    throw new ApplyRefusedException(sprintf(
                        '%s DELETE apply is not supported because destructive execution is not enabled for this resource type. No resources were modified.',
                        ucfirst($action->resourceType->value),
                    ));
                }

                if ($action->resourceType === ResourceType::DATABASE) {
                    if ($action->databaseDependencies === null) {
                        throw new ApplyRefusedException(sprintf(
                            'Logical Database deletion "%s" is refused because dependency discovery is unavailable. No resources were modified.',
                            (string) $action->address,
                        ));
                    }
                    $readiness = $action->databaseDependencies->readiness();
                    if ($readiness === DatabaseDestructiveReadiness::BLOCKED) {
                        throw new ApplyRefusedException(sprintf(
                            'Logical Database deletion "%s" is blocked by existing dependencies (%s). No resources were modified.',
                            (string) $action->address,
                            implode(', ', array_map(
                                static fn ($cat): string => $cat->value,
                                $action->databaseDependencies->blockingCategories(),
                            )),
                        ));
                    }
                    if ($readiness === DatabaseDestructiveReadiness::UNKNOWN) {
                        throw new ApplyRefusedException(sprintf(
                            'Logical Database deletion "%s" is refused because dependency discovery is incomplete. No resources were modified.',
                            (string) $action->address,
                        ));
                    }
                    continue;
                }

                if ($action->resourceType === ResourceType::DATABASE_CLUSTER) {
                    $dependencies = $action->databaseDependencies;
                    if ($dependencies === null) {
                        throw new ApplyRefusedException(sprintf(
                            'Database Cluster deletion "%s" is refused because destructive discovery is incomplete: dependency evidence unavailable. No resources were modified.',
                            (string) $action->address,
                        ));
                    }
                    if ($dependencies->ownershipConflict) {
                        throw new ApplyRefusedException(sprintf(
                            'Database Cluster deletion "%s" has a destructive ownership conflict: ownership_conflict. No resources were modified.',
                            (string) $action->address,
                        ));
                    }
                    if (!$dependencies->complete
                        || !$dependencies->snapshotDiscoveryComplete || !$dependencies->recoveryEvidenceComplete
                        || $dependencies->unknownRelationships !== [] || $dependencies->missingRelationships !== []) {
                        $detail = match (true) {
                            $dependencies->missingRelationships !== [] => 'missing relationship evidence: ' . implode(', ', $dependencies->missingRelationships),
                            $dependencies->unknownRelationships !== [] => 'unknown relationship evidence: ' . implode(', ', $dependencies->unknownRelationships),
                            !$dependencies->snapshotDiscoveryComplete => 'missing relationship evidence: snapshots',
                            !$dependencies->recoveryEvidenceComplete => 'unknown relationship evidence: retained_recovery',
                            default => 'incomplete structural evidence',
                        };
                        throw new ApplyRefusedException(sprintf(
                            'Database Cluster deletion "%s" is refused because destructive discovery is incomplete: %s. No resources were modified.',
                            (string) $action->address,
                            $detail,
                        ));
                    }
                    if ($dependencies->unmanagedChildCount > 0
                        || $dependencies->snapshotCount > 0 || $dependencies->retainedRecovery
                        || $dependencies->lifecycleReadiness !== DatabaseClusterLifecycleReadiness::ELIGIBLE) {
                        throw new ApplyRefusedException(sprintf('Database Cluster deletion "%s" is blocked by destructive dependencies or lifecycle state. No resources were modified.', (string) $action->address));
                    }
                    $approvedChildren = 0;
                    foreach ($plan as $candidate) {
                        if ($candidate->resourceType === ResourceType::DATABASE
                            && $candidate->operation === PlanOperation::DELETE
                            && $candidate->parent !== null
                            && (string) $candidate->parent === (string) $action->address) {
                            ++$approvedChildren;
                        }
                    }
                    if ($approvedChildren !== $dependencies->ownedChildCount
                        || ($dependencies->derivedParentDependencyCount === 1) !== ($action->parentLifecycleDependency !== null)
                        || $dependencies->derivedParentDependencyCount > 1) {
                        throw new ApplyRefusedException(sprintf('Database Cluster deletion "%s" approval graph is incomplete. No resources were modified.', (string) $action->address));
                    }
                    continue;
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
            if ($action->resourceType === ResourceType::DATABASE_CLUSTER
                && $action->operation !== PlanOperation::NO_CHANGE
                && $action->operation !== PlanOperation::CREATE
                && $action->operation !== PlanOperation::DELETE) {
                throw new ApplyRefusedException('Database Cluster DELETE apply is not supported. No resources were modified.');
            }
            if ($action->resourceType === ResourceType::DATABASE
                && !in_array($action->operation, [PlanOperation::NO_CHANGE, PlanOperation::CREATE, PlanOperation::DELETE], true)) {
                throw new ApplyRefusedException('Logical Database operation is not supported. No resources were modified.');
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

            $createdCluster = $created->cluster;
            if ($createdCluster->name !== $desired->name
                || $createdCluster->type !== $desired->type->value
                || $createdCluster->region !== $desired->region) {
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
                $state = $this->checkpointDatabaseResources(
                    $blueprint,
                    $transaction,
                    $state,
                    new StateResource($action->address, ResourceType::DATABASE_CLUSTER, $createdCluster->id),
                    new StateResource(
                        DerivedResource::defaultDatabaseAddress($action->address),
                        ResourceType::DATABASE,
                        $created->defaultDatabaseId,
                        $action->address,
                        StateOwnershipClassification::DERIVED,
                        StateProvenance::CLUSTER_CREATE_RESPONSE,
                    ),
                );
            } catch (InvalidArgumentException|StateStorageException $exception) {
                return $this->databaseCreateFailure(
                    $action,
                    'Remote Database Cluster and its default Database were created but their combined local ownership checkpoint failed; inspect Cloud and use import before retrying.',
                    $state,
                    $clusterIds,
                    $outcomes,
                    true,
                );
            }

            $clusterIds[$desired->name] = $createdCluster->id;
            $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::CREATED);
            try {
                $this->databaseReadiness->wait($cloud, $createdCluster);
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
        return $this->checkpointDatabaseResources($blueprint, $transaction, $state, $resource);
    }

    private function checkpointDatabaseResources(
        Blueprint $blueprint,
        StateTransaction $transaction,
        StateDocument $state,
        StateResource ...$resources,
    ): StateDocument {
        if ($state->organization === null) {
            $state = $state->withOrganization($blueprint->organization);
        }
        foreach ($resources as $resource) {
            $state = $state->withResource($resource);
        }
        return $transaction->save($state);
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

    private function hasDelete(ExecutionPlan $plan, ResourceType $type): bool
    {
        foreach ($plan as $action) {
            if ($action->resourceType === $type && $action->operation === PlanOperation::DELETE) {
                return true;
            }
        }
        return false;
    }

    private function assertClusterApprovalGraphUnchanged(ExecutionPlan $approved, ExecutionPlan $fresh): void
    {
        foreach ($fresh as $candidate) {
            if ($candidate->operation !== PlanOperation::DELETE) {
                continue;
            }
            $original = $this->actionAt($approved, $candidate->address);
            if ($original === null || $original->operation !== PlanOperation::DELETE
                || $original->remoteId !== $candidate->remoteId
                || ($original->parent === null ? null : (string) $original->parent)
                    !== ($candidate->parent === null ? null : (string) $candidate->parent)) {
                throw new ApplyRefusedException('The locked destructive graph differs from the approved plan; no mutation was sent.');
            }
            if ($candidate->resourceType === ResourceType::DATABASE_CLUSTER) {
                if (($original->parentLifecycleDependency === null ? null : (string) $original->parentLifecycleDependency->address)
                    !== ($candidate->parentLifecycleDependency === null ? null : (string) $candidate->parentLifecycleDependency->address)
                    || $original->databaseDependencies?->categories() !== $candidate->databaseDependencies?->categories()
                    || $original->databaseDependencies?->missingRelationships !== $candidate->databaseDependencies?->missingRelationships
                    || $original->databaseDependencies?->unknownRelationships !== $candidate->databaseDependencies?->unknownRelationships) {
                    throw new ApplyRefusedException('The locked Database Cluster dependencies differ from the approved graph; no mutation was sent.');
                }
            }
        }
    }

    /**
     * @param list<ApplyResourceOutcome> $outcomes
     * @return array{state: StateDocument, outcomes: list<ApplyResourceOutcome>, failure: ApplyResult|null}
     */
    private function deleteDatabaseResource(
        Blueprint $blueprint,
        ExecutionPlan $approvedPlan,
        PlanAction $action,
        LaravelCloudLogicalDatabaseDeletionClient $cloud,
        StateTransaction $transaction,
        StateDocument $state,
        array $outcomes,
    ): array {
        $failure = function (
            DestructiveOutcome $destructiveOutcome,
            string $message,
            bool|null $deleted = false,
            bool $confirmed = false,
            ?CloudValidationException $validation = null,
            bool $uncertain = false,
        ) use ($action, $state, $outcomes): array {
            $failedOutcomes = [...$outcomes, new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                $message,
                $validation,
                $destructiveOutcome,
                $deleted,
                $confirmed,
                false,
            )];
            $status = $this->confirmedMutationCount($failedOutcomes) > 0 || $uncertain
                ? ApplyStatus::PARTIAL_FAILURE
                : ApplyStatus::FAILED;
            return [
                'state' => $state,
                'outcomes' => $failedOutcomes,
                'failure' => new ApplyResult($status, ...$failedOutcomes),
            ];
        };

        $managed = $state->find($action->address);
        if ($managed === null || $managed->type !== ResourceType::DATABASE) {
            return $failure(DestructiveOutcome::CONFLICT,
                sprintf('Local State ownership for "%s" is missing or invalid.', (string) $action->address));
        }
        if ($action->remoteId === null || $managed->remoteId !== $action->remoteId) {
            return $failure(DestructiveOutcome::CONFLICT,
                sprintf('Local State remote identity for "%s" conflicts with the approved plan.', (string) $action->address));
        }
        if ($managed->parent === null || $managed->parent->type !== ResourceType::DATABASE_CLUSTER) {
            return $failure(DestructiveOutcome::CONFLICT,
                sprintf('Local State parent for "%s" is invalid.', (string) $action->address));
        }
        $parent = $state->find($managed->parent);
        if ($parent === null || $parent->type !== ResourceType::DATABASE_CLUSTER) {
            return $failure(DestructiveOutcome::CONFLICT,
                sprintf('Parent Database Cluster "%s" is not owned in State.', (string) $managed->parent));
        }
        $approvedParent = $this->actionAt($approvedPlan, $managed->parent);
        if ($approvedParent === null
            || $approvedParent->remoteId === null
            || $approvedParent->remoteId !== $parent->remoteId) {
            return $failure(DestructiveOutcome::CONFLICT,
                sprintf('Parent Database Cluster identity for "%s" changed after approval.', (string) $action->address));
        }
        if ($action->parent === null || (string) $action->parent !== (string) $managed->parent) {
            return $failure(DestructiveOutcome::CONFLICT,
                sprintf('Parent address for "%s" changed after approval.', (string) $action->address));
        }
        foreach ($state->resources() as $resource) {
            if ((string) $resource->address !== (string) $managed->address
                && $resource->remoteId === $managed->remoteId) {
                return $failure(DestructiveOutcome::CONFLICT,
                    sprintf('Remote identity for "%s" is owned by multiple addresses.', (string) $action->address));
            }
        }
        if ($this->blueprintHasDatabase($blueprint, $action->address)) {
            return $failure(DestructiveOutcome::CONFLICT,
                sprintf('Logical Database "%s" is declared in the locked Blueprint and cannot be deleted.', $action->address->name));
        }

        try {
            $freshPlan = (new CreatePlan($this->values))->create($blueprint, $cloud, $state);
        } catch (CloudException $exception) {
            return $failure(
                DestructiveOutcome::UNCERTAIN,
                'Locked logical Database replanning failed before mutation: ' . $exception->getMessage(),
            );
        }
        $freshAction = $this->actionAt($freshPlan, $action->address);
        if ($freshAction === null
            || $freshAction->operation !== PlanOperation::DELETE
            || $freshAction->remoteId !== $managed->remoteId
            || $freshAction->parent === null
            || (string) $freshAction->parent !== (string) $managed->parent) {
            return $failure(DestructiveOutcome::CONFLICT,
                'Logical Database deletion assumptions changed during locked replanning; no DELETE was sent.');
        }
        $freshDependencies = $freshAction->databaseDependencies;
        if ($freshDependencies === null || $freshDependencies->readiness() === DatabaseDestructiveReadiness::UNKNOWN) {
            return $failure(DestructiveOutcome::REFUSED,
                'Logical Database deletion refused: locked dependency discovery is incomplete or unknown.');
        }
        if ($freshDependencies->readiness() === DatabaseDestructiveReadiness::BLOCKED) {
            if (in_array(DatabaseDependencyType::OWNERSHIP_CONFLICT, $freshDependencies->blockingCategories(), true)) {
                return $failure(DestructiveOutcome::CONFLICT,
                    'Logical Database parent or ownership identity conflicted during locked replanning.');
            }
            return $failure(DestructiveOutcome::REFUSED, sprintf(
                'Logical Database deletion refused: locked dependency discovery found: %s.',
                implode(', ', array_map(
                    static fn ($category): string => $category->value,
                    $freshDependencies->blockingCategories(),
                )),
            ));
        }
        $freshParent = $this->actionAt($freshPlan, $managed->parent);
        if ($freshParent === null || $freshParent->remoteId !== $parent->remoteId) {
            return $failure(DestructiveOutcome::CONFLICT,
                'Parent Database Cluster identity changed during locked replanning; no DELETE was sent.');
        }

        try {
            $remote = $cloud->databaseWithDestructiveRelationships($parent->remoteId, $managed->remoteId);
        } catch (CloudResourceNotFoundException) {
            return $this->checkpointAbsentDatabase(
                $action,
                $transaction,
                $state,
                $outcomes,
                false,
                DestructiveOutcome::ALREADY_ABSENT,
                'already absent; local State reconciled',
            );
        } catch (CloudException $exception) {
            return $failure(
                DestructiveOutcome::UNCERTAIN,
                'Locked exact logical Database rediscovery failed before mutation: ' . $exception->getMessage(),
            );
        }

        if ($remote->relationshipClusterId === null) {
            return $failure(DestructiveOutcome::REFUSED,
                'Logical Database deletion refused: parent Cluster discovery is incomplete.');
        }
        if ($remote->relationshipClusterId !== $parent->remoteId) {
            return $failure(DestructiveOutcome::CONFLICT,
                'Logical Database parent Cluster did not match the locked State identity.');
        }
        $dependencies = $this->databaseDependencies($remote, $parent->remoteId);
        if ($dependencies->readiness() === DatabaseDestructiveReadiness::UNKNOWN) {
            return $failure(DestructiveOutcome::REFUSED,
                'Logical Database deletion refused: attachment discovery is incomplete or contains unknown relationships.');
        }
        if ($dependencies->readiness() === DatabaseDestructiveReadiness::BLOCKED) {
            return $failure(DestructiveOutcome::REFUSED,
                'Logical Database deletion refused: one or more Environment attachments exist.');
        }

        $deleteException = null;
        try {
            $cloud->deleteDatabase($parent->remoteId, $managed->remoteId);
        } catch (CloudException $exception) {
            $deleteException = $exception;
        }

        $verification = $this->databaseDeletionVerification->verifyAbsent(
            $cloud,
            $parent->remoteId,
            $managed->remoteId,
        );
        if ($verification === 'absent') {
            return $this->checkpointAbsentDatabase(
                $action,
                $transaction,
                $state,
                $outcomes,
                true,
                DestructiveOutcome::DELETE_CONFIRMED,
                'deleted and confirmed',
            );
        }
        if ($verification === 'present') {
            $message = $deleteException === null
                ? 'Logical Database deletion was not confirmed: exact remote resource remains discoverable.'
                : 'Logical Database deletion failed: ' . $deleteException->getMessage();
            return $failure(
                DestructiveOutcome::UNCERTAIN,
                $message,
                false,
                false,
                $deleteException instanceof CloudValidationException ? $deleteException : null,
            );
        }

        return $failure(
            DestructiveOutcome::UNCERTAIN,
            'Logical Database deletion outcome is uncertain: exact post-delete rediscovery failed.',
            null,
            false,
            null,
            true,
        );
    }

    private function databaseDependencies(CloudDatabase $database, string $parentRemoteId): DatabaseDependencies
    {
        $parentConflict = $database->relationshipClusterId !== null
            && $database->relationshipClusterId !== $parentRemoteId;
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

    private function blueprintHasDatabase(Blueprint $blueprint, ResourceAddress $address): bool
    {
        foreach ($blueprint->databaseClusters as $cluster) {
            foreach ($cluster->databases as $database) {
                if ($cluster->name . '.' . $database->name === $address->name) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param list<ApplyResourceOutcome> $outcomes
     * @return array{state: StateDocument, outcomes: list<ApplyResourceOutcome>, failure: ApplyResult|null}
     */
    private function checkpointAbsentDatabase(
        PlanAction $action,
        StateTransaction $transaction,
        StateDocument $state,
        array $outcomes,
        bool $deleteSent,
        DestructiveOutcome $destructiveOutcome,
        string $message,
    ): array {
        try {
            $state = $transaction->save($state->withoutResource($action->address));
        } catch (StateStorageException $exception) {
            $outcomes[] = new ApplyResourceOutcome(
                $action->address,
                ApplyOutcomeOperation::FAILED,
                'Logical Database is absent remotely, but State checkpoint failed: ' . $exception->getMessage(),
                destructiveOutcome: DestructiveOutcome::STATE_CHECKPOINT_FAILED,
                deleted: $deleteSent,
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
            $message,
            destructiveOutcome: $destructiveOutcome,
            deleted: $deleteSent,
            confirmed: true,
            stateCheckpointed: true,
        );
        return ['state' => $state, 'outcomes' => $outcomes, 'failure' => null];
    }

    /**
     * @param list<ApplyResourceOutcome> $outcomes
     * @return array{state: StateDocument, outcomes: list<ApplyResourceOutcome>, failure: ApplyResult|null}
     */
    private function deleteDatabaseClusterResource(
        Blueprint $blueprint,
        PlanAction $action,
        LaravelCloudDatabaseClusterDeletionClient $cloud,
        StateTransaction $transaction,
        StateDocument $state,
        array $outcomes,
    ): array {
        $failure = function (DestructiveOutcome $kind, string $message, bool|null $deleted = false, bool $confirmed = false, ?CloudValidationException $validation = null, bool $uncertain = false) use ($action, $state, $outcomes): array {
            $failed = [...$outcomes, new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::FAILED, $message, $validation, $kind, $deleted, $confirmed, false)];
            return ['state' => $state, 'outcomes' => $failed, 'failure' => new ApplyResult(
                $this->confirmedMutationCount($failed) > 0 || $uncertain ? ApplyStatus::PARTIAL_FAILURE : ApplyStatus::FAILED,
                ...$failed,
            )];
        };

        $managed = $state->find($action->address);
        if ($managed === null || $managed->type !== ResourceType::DATABASE_CLUSTER
            || $action->remoteId === null || $action->remoteId !== $managed->remoteId) {
            return $failure(DestructiveOutcome::CONFLICT, 'Database Cluster State identity conflicts with the approved deletion.');
        }
        foreach ($state->resources() as $candidate) {
            if ((string) $candidate->address !== (string) $managed->address && $candidate->remoteId === $managed->remoteId) {
                return $failure(DestructiveOutcome::CONFLICT, 'Database Cluster remote identity is owned by multiple State addresses.');
            }
        }
        foreach ($blueprint->databaseClusters as $desired) {
            if ($desired->name === $action->address->name) {
                return $failure(DestructiveOutcome::CONFLICT, 'Database Cluster is still declared in the locked Blueprint; no DELETE was sent.');
            }
        }

        try {
            $cluster = $cloud->databaseCluster($managed->remoteId);
        } catch (CloudResourceNotFoundException) {
            if ($state->childrenOf($managed->address) !== []) {
                return $failure(DestructiveOutcome::CONFLICT, 'Database Cluster is absent remotely but owned child State remains; parent State was retained.');
            }
            return $this->checkpointAbsentCluster($action, $transaction, $state, $outcomes, false, DestructiveOutcome::ALREADY_ABSENT, 'already absent; local State reconciled');
        } catch (CloudException $exception) {
            return $failure(DestructiveOutcome::UNCERTAIN, 'Locked exact Database Cluster rediscovery failed before mutation: ' . $exception->getMessage());
        }
        if ($cluster->id !== $managed->remoteId || !$cluster->childDiscoveryComplete) {
            return $failure(DestructiveOutcome::REFUSED, 'Database Cluster deletion refused: exact child relationship discovery is incomplete or conflicted.');
        }

        $children = $state->childrenOf($managed->address);
        $derived = array_values(array_filter($children, static fn (StateResource $child): bool => $child->type === ResourceType::DATABASE && $child->isDerived()));
        $ordinary = array_values(array_filter($children, static fn (StateResource $child): bool => !$child->isDerived()));
        if ($ordinary !== [] || count($derived) > 1) {
            return $failure(DestructiveOutcome::CONFLICT, 'Database Cluster still has owned child State entries after ordinary child execution.');
        }
        try {
            $listed = $cloud->databases($managed->remoteId);
        } catch (CloudException $exception) {
            return $failure(DestructiveOutcome::REFUSED, 'Database Cluster deletion refused: complete logical Database listing failed.');
        }
        $listedIds = array_map(static fn (CloudDatabase $database): string => $database->id, $listed);
        if (count($listedIds) !== count(array_unique($listedIds))
            || count($cluster->databaseIds) !== count(array_unique($cluster->databaseIds))
            || array_values(array_diff($listedIds, $cluster->databaseIds)) !== []
            || array_values(array_diff($cluster->databaseIds, $listedIds)) !== []) {
            return $failure(DestructiveOutcome::CONFLICT, 'Database Cluster child relationship and complete listing disagree.');
        }

        if ($derived !== []) {
            $child = $derived[0];
            $approved = $action->parentLifecycleDependency;
            if ($child->classification !== StateOwnershipClassification::DERIVED
                || $child->provenance !== StateProvenance::CLUSTER_CREATE_RESPONSE
                || $child->parent === null || (string) $child->parent !== (string) $managed->address
                || $approved === null || (string) $approved->address !== (string) $child->address) {
                return $failure(DestructiveOutcome::CONFLICT, 'Derived Database authorization no longer matches the approved parent lifecycle dependency.');
            }
            $matches = count(array_filter($listedIds, static fn (string $id): bool => $id === $child->remoteId));
            if ($matches === 0) {
                if ($listed !== []) {
                    return $failure(DestructiveOutcome::CONFLICT, 'Derived Database is absent but a replacement or unmanaged Cluster child exists; no mutation was sent.');
                }
                try {
                    $cloud->databaseWithDestructiveRelationships($managed->remoteId, $child->remoteId);
                    return $failure(DestructiveOutcome::CONFLICT, 'Derived Database exact discovery conflicts with complete Cluster topology.');
                } catch (CloudResourceNotFoundException) {
                    $derivedAction = new PlanAction($child->address, ResourceType::DATABASE, PlanOperation::NO_CHANGE, '', $child->remoteId, $managed->address, $child->classification, $child->provenance, DatabaseDestructiveRole::PARENT_LIFECYCLE_DEPENDENCY);
                    $checkpoint = $this->checkpointAbsentDatabase($derivedAction, $transaction, $state, $outcomes, false, DestructiveOutcome::ALREADY_ABSENT, 'derived parent dependency already absent; local State reconciled');
                    if ($checkpoint['failure'] !== null) {
                        return $checkpoint;
                    }
                    $state = $checkpoint['state'];
                    $outcomes = $checkpoint['outcomes'];
                } catch (CloudException $exception) {
                    return $failure(DestructiveOutcome::UNCERTAIN, 'Derived Database exact absence could not be proven.');
                }
            } elseif ($matches === 1 && count($listed) === 1) {
                try {
                    $remote = $cloud->databaseWithDestructiveRelationships($managed->remoteId, $child->remoteId);
                } catch (CloudException $exception) {
                    return $failure(DestructiveOutcome::UNCERTAIN, 'Derived Database exact destructive rediscovery failed before mutation.');
                }
                if ($remote->id !== $child->remoteId || $remote->relationshipClusterId !== $managed->remoteId
                    || !$remote->destructiveRelationshipsComplete || $remote->unknownRelationships !== []
                    || $remote->missingRelationships !== [] || $remote->environmentIds !== []) {
                    return $failure(DestructiveOutcome::REFUSED, 'Derived Database deletion refused: exact parent or Environment attachment evidence is unsafe.');
                }
                $deleteException = null;
                try {
                    $cloud->deleteDatabase($managed->remoteId, $child->remoteId);
                } catch (CloudException $exception) {
                    $deleteException = $exception;
                }
                $verified = $this->databaseDeletionVerification->verifyAbsent($cloud, $managed->remoteId, $child->remoteId);
                if ($verified !== 'absent') {
                    $refused = $verified === 'present'
                        && ($deleteException instanceof CloudAuthenticationException || $deleteException instanceof CloudValidationException);
                    return $failure(
                        $refused ? DestructiveOutcome::REFUSED : DestructiveOutcome::UNCERTAIN,
                        $refused
                            ? 'Derived Database deletion was refused and the exact child remains present.'
                            : 'Derived Database deletion outcome is uncertain; exact absence was not proven.',
                        $verified === 'failed' ? null : false,
                        false,
                        $deleteException instanceof CloudValidationException ? $deleteException : null,
                        $verified === 'failed',
                    );
                }
                $derivedAction = new PlanAction($child->address, ResourceType::DATABASE, PlanOperation::NO_CHANGE, '', $child->remoteId, $managed->address, $child->classification, $child->provenance, DatabaseDestructiveRole::PARENT_LIFECYCLE_DEPENDENCY);
                $checkpoint = $this->checkpointAbsentDatabase($derivedAction, $transaction, $state, $outcomes, true, DestructiveOutcome::DELETE_CONFIRMED, 'derived parent dependency deleted and confirmed');
                if ($checkpoint['failure'] !== null) {
                    return $checkpoint;
                }
                $state = $checkpoint['state'];
                $outcomes = $checkpoint['outcomes'];
            } else {
                return $failure(DestructiveOutcome::CONFLICT, 'Derived Database identity is not the sole exact Cluster child; no DELETE was sent.');
            }
        } elseif ($listed !== []) {
            return $failure(DestructiveOutcome::REFUSED, 'Database Cluster deletion blocked by an unmanaged logical Database child.');
        }

        $postChildFailure = function (DestructiveOutcome $kind, string $message, bool|null $deleted = false, bool $confirmed = false, ?CloudValidationException $validation = null, bool $uncertain = false) use ($action, $state, $outcomes): array {
            $failed = [...$outcomes, new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::FAILED, $message, $validation, $kind, $deleted, $confirmed, false)];
            return ['state' => $state, 'outcomes' => $failed, 'failure' => new ApplyResult(
                $this->confirmedMutationCount($failed) > 0 || $uncertain ? ApplyStatus::PARTIAL_FAILURE : ApplyStatus::FAILED,
                ...$failed,
            )];
        };

        try {
            $cluster = $this->databaseClusterDeletionReadiness->wait($cloud, $cloud->databaseCluster($managed->remoteId));
            $freshPlan = (new CreatePlan($this->values))->create($blueprint, $cloud, $state);
        } catch (CloudException $exception) {
            return $postChildFailure(DestructiveOutcome::REFUSED, 'Post-child Database Cluster readiness could not be proven: ' . $exception->getMessage());
        }
        $fresh = $this->actionAt($freshPlan, $action->address);
        $dependencies = $fresh?->databaseDependencies;
        if ($cluster->id !== $managed->remoteId || $fresh === null || $fresh->operation !== PlanOperation::DELETE
            || $fresh->remoteId !== $managed->remoteId || $dependencies === null
            || $dependencies->readiness() !== DatabaseDestructiveReadiness::SAFE
            || $dependencies->ownedChildCount !== 0 || $dependencies->derivedParentDependencyCount !== 0
            || $dependencies->unmanagedChildCount !== 0 || $dependencies->ownershipConflict
            || $dependencies->snapshotCount !== 0 || $dependencies->retainedRecovery
            || !$dependencies->snapshotDiscoveryComplete || !$dependencies->recoveryEvidenceComplete
            || $dependencies->lifecycleReadiness !== DatabaseClusterLifecycleReadiness::ELIGIBLE) {
            return $postChildFailure(DestructiveOutcome::REFUSED, 'Post-child Database Cluster destructive rediscovery is not completely safe; no parent DELETE was sent.');
        }

        $deleteException = null;
        try {
            $cloud->deleteDatabaseCluster($managed->remoteId);
        } catch (CloudException $exception) {
            $deleteException = $exception;
        }
        $verified = $this->databaseClusterDeletionVerification->verifyAbsent($cloud, $managed->remoteId);
        if ($verified === 'absent') {
            return $this->checkpointAbsentCluster($action, $transaction, $state, $outcomes, $deleteException === null, $deleteException instanceof CloudResourceNotFoundException ? DestructiveOutcome::ALREADY_ABSENT : DestructiveOutcome::DELETE_CONFIRMED, $deleteException instanceof CloudResourceNotFoundException ? 'already absent and confirmed' : 'deleted and confirmed');
        }
        if ($verified === 'present'
            && ($deleteException instanceof CloudAuthenticationException || $deleteException instanceof CloudValidationException)) {
            return $postChildFailure(
                DestructiveOutcome::REFUSED,
                'Database Cluster deletion was refused and the exact Cluster remains present.',
                false,
                false,
                $deleteException instanceof CloudValidationException ? $deleteException : null,
            );
        }
        return $postChildFailure(DestructiveOutcome::UNCERTAIN, 'Database Cluster deletion outcome is uncertain; exact absence was not proven.', $verified === 'unknown' ? null : false, false, $deleteException instanceof CloudValidationException ? $deleteException : null, true);
    }

    /**
     * @param list<ApplyResourceOutcome> $outcomes
     * @return array{state: StateDocument, outcomes: list<ApplyResourceOutcome>, failure: ApplyResult|null}
     */
    private function checkpointAbsentCluster(PlanAction $action, StateTransaction $transaction, StateDocument $state, array $outcomes, bool $deleteSent, DestructiveOutcome $kind, string $message): array
    {
        if ($state->childrenOf($action->address) !== []) {
            throw new ApplyRefusedException('Database Cluster State cannot be removed while owned child State remains.');
        }
        try {
            $state = $transaction->save($state->withoutResource($action->address));
        } catch (StateStorageException $exception) {
            $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::FAILED, 'Database Cluster is absent remotely, but State checkpoint failed: ' . $exception->getMessage(), destructiveOutcome: DestructiveOutcome::STATE_CHECKPOINT_FAILED, deleted: $deleteSent, confirmed: true, stateCheckpointed: false);
            return ['state' => $state, 'outcomes' => $outcomes, 'failure' => new ApplyResult(ApplyStatus::PARTIAL_FAILURE, ...$outcomes)];
        }
        $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::DELETED, $message, destructiveOutcome: $kind, deleted: $deleteSent, confirmed: true, stateCheckpointed: true);
        return ['state' => $state, 'outcomes' => $outcomes, 'failure' => null];
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
