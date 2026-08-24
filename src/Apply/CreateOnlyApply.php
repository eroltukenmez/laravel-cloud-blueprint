<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Apply\Exception\StateIdentityConflictException;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;

final readonly class CreateOnlyApply
{
    public function execute(
        Blueprint $blueprint,
        ExecutionPlan $plan,
        LaravelCloudClient $cloud,
        StateStore $states,
    ): ApplyResult {
        if ($plan->countByOperation(PlanOperation::UNSUPPORTED) > 0) {
            throw new ApplyRefusedException('The plan contains unsupported changes. No resources were modified.');
        }

        $transaction = $states->begin();

        try {
            $state = $transaction->load();
            $this->verifyState($blueprint, $plan, $state);
            $outcomes = [];
            $applicationId = null;

            foreach ($plan as $action) {
                if ($action->operation === PlanOperation::NO_CHANGE) {
                    $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::UNCHANGED);
                    if ($action->resourceType === ResourceType::APPLICATION) {
                        $applicationId = $action->remoteId;
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
                        $created = $cloud->createEnvironment(
                            $applicationId,
                            new CreateEnvironmentRequest($desired->name, $desired->branch),
                        );
                        $resource = new StateResource(
                            $action->address,
                            ResourceType::ENVIRONMENT,
                            $created->id,
                            new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name),
                        );
                    }
                } catch (CloudException $exception) {
                    $outcomes[] = new ApplyResourceOutcome($action->address, ApplyOutcomeOperation::FAILED, $exception->getMessage());
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
            }

            return new ApplyResult(ApplyStatus::SUCCESS, ...$outcomes);
        } finally {
            $transaction->release();
        }
    }

    private function verifyState(Blueprint $blueprint, ExecutionPlan $plan, StateDocument $state): void
    {
        if ($state->organization !== null && $state->organization !== $blueprint->organization) {
            throw new StateIdentityConflictException('Local state organization does not match the blueprint organization.');
        }

        foreach ($plan as $action) {
            $managed = $state->find($action->address);
            if ($managed === null) {
                continue;
            }

            if ($action->remoteId === null || $managed->remoteId !== $action->remoteId) {
                throw new StateIdentityConflictException(sprintf(
                    'Local state identity for "%s" conflicts with remote planning truth.',
                    (string) $action->address,
                ));
            }
        }
    }

    /** @param list<ApplyResourceOutcome> $outcomes */
    private function createdCount(array $outcomes): int
    {
        return count(array_filter(
            $outcomes,
            static fn (ApplyResourceOutcome $outcome): bool => $outcome->operation === ApplyOutcomeOperation::CREATED,
        ));
    }
}
