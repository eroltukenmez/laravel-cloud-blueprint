<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Apply\Exception\StateIdentityConflictException;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentVariableInput;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;

final readonly class CreateOnlyApply
{
    public function __construct(private VariableValueResolver $values)
    {
    }

    public function execute(
        Blueprint $blueprint,
        ExecutionPlan $plan,
        LaravelCloudClient $cloud,
        StateStore $states,
    ): ApplyResult {
        $this->assertSupported($plan);

        $variableGroups = $this->variableGroups($blueprint, $plan);

        $transaction = $states->begin();

        try {
            $state = $transaction->load();
            $this->verifyState($blueprint, $plan, $state);
            $outcomes = [];
            $applicationId = null;
            $environmentIds = [];
            /** @var array<string, CloudEnvironment> $implicitEnvironments */
            $implicitEnvironments = [];

            foreach ($plan as $action) {
                if ($action->resourceType === ResourceType::VARIABLE) {
                    continue;
                }

                if ($action->operation === PlanOperation::NO_CHANGE) {
                    $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::UNCHANGED);
                    if ($action->resourceType === ResourceType::APPLICATION) {
                        $applicationId = $action->remoteId;
                    } elseif ($action->remoteId !== null) {
                        $environmentIds[$action->address->name] = $action->remoteId;
                    }
                    continue;
                }

                if ($action->operation === PlanOperation::UPDATE) {
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
                    $status = $this->createdCount($outcomes) === 0 && !$exception instanceof CloudTransportException
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
            if ($action->resourceType === ResourceType::VARIABLE) {
                continue;
            }
            $managed = $state->find($action->address);
            if ($managed === null) {
                if ($action->resourceType === ResourceType::ENVIRONMENT
                    && $action->operation === PlanOperation::UPDATE) {
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
                $expectedParent = new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name);
                if ($managed->parent === null
                    || (string) $managed->parent !== (string) $expectedParent) {
                    throw new StateIdentityConflictException(sprintf(
                        'Local state parent for "%s" is invalid.',
                        (string) $action->address,
                    ));
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
    private function createdCount(array $outcomes): int
    {
        return count(array_filter(
            $outcomes,
            static fn (ApplyResourceOutcome $outcome): bool => $outcome->operation === ApplyOutcomeOperation::CREATED,
        ));
    }

    /** @param list<ApplyResourceOutcome> $outcomes */
    private function confirmedMutationCount(array $outcomes): int
    {
        return count(array_filter(
            $outcomes,
            static fn (ApplyResourceOutcome $outcome): bool => $outcome->operation === ApplyOutcomeOperation::CREATED
                || $outcome->operation === ApplyOutcomeOperation::UPDATED,
        ));
    }
}
