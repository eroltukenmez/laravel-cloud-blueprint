<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecodingException;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\JsonError;
use LaravelCloudBlueprint\Console\JsonErrorCategory;
use LaravelCloudBlueprint\Console\JsonOutput;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanChange;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\PlanReconciliationStatus;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Exception\StateCorruptedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'plan', description: 'Compare a blueprint with Laravel Cloud without making changes.')]
final class PlanCommand extends Command
{
    private const int JSON_CONTRACT_VERSION = 1;

    private readonly JsonOutput $json;

    public function __construct(
        private readonly FileReader $files,
        private readonly BlueprintLoader $blueprints,
        private readonly CloudTokenProvider $tokens,
        private readonly LaravelCloudClientFactory $clients,
        private readonly CreatePlan $planner,
        private readonly StateStore $states,
        ?JsonOutput $json = null,
    ) {
        $this->json = $json ?? new JsonOutput();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Blueprint file path.', InitCommand::DEFAULT_FILE)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $path = $input->getOption('file');
        if (!is_string($path)) {
            return $this->error($output, 'The --file option must be a path.', ExitCode::BLUEPRINT_ERROR, $json, JsonErrorCategory::INPUT, 'invalid_file_option');
        }

        if (!$this->files->exists($path)) {
            return $this->error($output, sprintf('Blueprint file "%s" does not exist.', $path), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::FILESYSTEM, 'file_not_found');
        }

        try {
            $loaded = $this->blueprints->load($this->files->read($path));
        } catch (StructuredDataDecodingException) {
            return $this->error($output, 'Blueprint YAML could not be decoded.', ExitCode::BLUEPRINT_ERROR, $json, JsonErrorCategory::BLUEPRINT, 'blueprint_decode_failed');
        } catch (FileOperationException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::FILESYSTEM, 'file_read_failed');
        }

        if (!$loaded->isValid()) {
            if ($json) {
                return $this->error(
                    $output,
                    'Blueprint validation failed.',
                    ExitCode::BLUEPRINT_ERROR,
                    true,
                    JsonErrorCategory::BLUEPRINT,
                    'blueprint_invalid',
                    ['validation_errors' => array_map(
                        static fn ($error): array => ['path' => $error->path, 'code' => $error->code->value, 'message' => $error->message],
                        iterator_to_array($loaded->validation, false),
                    )],
                );
            }
            foreach ($loaded->validation as $error) {
                $output->writeln(sprintf('[%s] %s: %s', $error->code->value, $error->path, $error->message));
            }
            $output->writeln(sprintf('Blueprint has %d validation error(s).', count($loaded->validation)));
            return ExitCode::BLUEPRINT_ERROR->value;
        }

        $token = $this->tokens->token();
        if ($token === null) {
            return $this->error($output, 'LCB_TOKEN is not set.', ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::AUTHENTICATION, 'authentication_token_missing');
        }

        try {
            $plan = $this->planner->create(
                $loaded->blueprint(),
                $this->clients->create($token),
                $this->states->load(),
            );
        } catch (OrganizationMismatchException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::BLUEPRINT, 'blueprint_organization_mismatch');
        } catch (AmbiguousResourceMatchException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::CLOUD, 'cloud_resource_ambiguous');
        } catch (MissingEnvironmentValueException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::INPUT, 'environment_value_missing');
        } catch (CloudException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::CLOUD, 'cloud_read_failed');
        } catch (StateCorruptedException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_corrupted');
        } catch (StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_read_failed');
        }

        if ($json) {
            return $this->renderJson($plan, $output);
        }

        $this->renderText($plan, $output);
        return ExitCode::SUCCESS->value;
    }

    private function renderText(ExecutionPlan $plan, OutputInterface $output): void
    {
        $output->writeln('Laravel Cloud Blueprint Plan');
        $output->writeln('');

        $create = $plan->countByOperation(PlanOperation::CREATE);
        $update = $plan->countByOperation(PlanOperation::UPDATE);
        $delete = $plan->countByOperation(PlanOperation::DELETE);
        $unchanged = $plan->countByOperation(PlanOperation::NO_CHANGE);
        $unsupported = $plan->countByOperation(PlanOperation::UNSUPPORTED);

        if (!$plan->hasReportableActions()) {
            $output->writeln('No changes.');
            $output->writeln('');
            $output->writeln('Laravel Cloud infrastructure matches the blueprint.');
            return;
        }

        foreach ($plan as $action) {
            $output->writeln(sprintf('%s %s', $this->symbol($action->operation), (string) $action->address));
            if ($action->changes === []) {
                $output->writeln('  ' . $action->reason);
            } else {
                foreach ($action->changes as $change) {
                    $output->writeln(sprintf('  %s: %s → %s', $change->field, $change->before, $change->after));
                }
            }
            if ($action->reconciliation !== PlanReconciliationStatus::NOT_APPLICABLE) {
                $output->writeln(sprintf('  Reconciliation: %s.', $action->reconciliation->value));
            }
            if ($action->databaseDependencies !== null) {
                $output->writeln(sprintf(
                    '  Destructive readiness: %s%s.',
                    $action->databaseDependencies->readiness()->value,
                    $action->resourceType === ResourceType::DATABASE_CLUSTER
                        ? match ($action->databaseDependencies->readiness()->value) {
                            'safe' => ' (eligible for guarded child-first deletion)',
                            'blocked' => ' (guarded deletion is blocked)',
                            default => ' (guarded deletion is not eligible)',
                        }
                        : match ($action->databaseDependencies->readiness()->value) {
                            'safe' => ' (eligible for guarded deletion)',
                            'blocked' => ' (guarded deletion is blocked)',
                            default => ' (guarded deletion is not eligible)',
                        },
                ));
                $blocking = $action->databaseDependencies->blockingCategories();
                if ($blocking !== []) {
                    $output->writeln(sprintf('  Blocking dependencies: %s.', implode(', ', array_map(
                        static fn ($dependency): string => $dependency->value,
                        $blocking,
                    ))));
                }
                if ($action->databaseDependencies->missingRelationships !== []) {
                    $output->writeln(sprintf(
                        '  Missing dependency relationships: %s.',
                        implode(', ', $action->databaseDependencies->missingRelationships),
                    ));
                }
                if ($action->databaseDependencies->unknownRelationships !== []) {
                    $output->writeln(sprintf(
                        '  Unknown dependency relationships: %s.',
                        implode(', ', $action->databaseDependencies->unknownRelationships),
                    ));
                }
                if ($action->resourceType === ResourceType::DATABASE_CLUSTER) {
                    $output->writeln(sprintf(
                        '  Structural readiness: %s.',
                        $action->databaseDependencies->structuralReadiness()->value,
                    ));
                    $output->writeln(sprintf(
                        '  Derived parent dependencies: %d.',
                        $action->databaseDependencies->derivedParentDependencyCount,
                    ));
                    if ($action->parentLifecycleDependency !== null) {
                        $output->writeln('  Derived parent dependency:');
                        $output->writeln('    ' . (string) $action->parentLifecycleDependency->address);
                        $output->writeln('    Will be deleted only during this approved guarded parent lifecycle.');
                    }
                    $output->writeln($action->databaseDependencies->snapshotDiscoveryComplete
                        ? sprintf(
                            '  Discovered snapshots: %d (%d manual, %d scheduled).',
                            $action->databaseDependencies->snapshotCount,
                            $action->databaseDependencies->manualSnapshotCount,
                            $action->databaseDependencies->scheduledSnapshotCount,
                        )
                        : '  Snapshot discovery: incomplete.');
                }
            }
            $output->writeln('');
        }

        $output->writeln($delete === 0
            ? sprintf(
                'Plan: %d to create, %d to update, %d unchanged, %d unsupported.',
                $create,
                $update,
                $unchanged,
                $unsupported,
            )
            : sprintf(
                'Plan: %d to create, %d to update, %d to delete, %d unchanged, %d unsupported.',
                $create,
                $update,
                $delete,
                $unchanged,
                $unsupported,
            ));
    }

    private function renderJson(ExecutionPlan $plan, OutputInterface $output): int
    {
        return $this->json->write([
                'status' => 'success',
                'summary' => [
                    'create' => $plan->countByOperation(PlanOperation::CREATE),
                    'update' => $plan->countByOperation(PlanOperation::UPDATE),
                    'delete' => $plan->countByOperation(PlanOperation::DELETE),
                    'no_change' => $plan->countByOperation(PlanOperation::NO_CHANGE),
                    'unsupported' => $plan->countByOperation(PlanOperation::UNSUPPORTED),
                ],
                'actions' => array_map(
                    static function (PlanAction $action): array {
                        $dependencies = $action->destructiveDependencies();
                        $clusterDependencies = $action->resourceType === ResourceType::DATABASE_CLUSTER
                            ? $action->databaseDependencies
                            : null;
                        return array_filter([
                        'resource' => (string) $action->address,
                        'type' => $action->resourceType->value,
                        'operation' => $action->operation->value,
                        'reconciliation' => $action->reconciliation->value,
                        'ownership' => $action->ownership->value,
                        'reason' => $action->reason,
                        'parent' => $action->parent === null ? null : (string) $action->parent,
                        'classification' => $action->ownershipClassification?->value,
                        'provenance' => $action->provenance?->value,
                        'destructive_role' => $action->destructiveRole?->value,
                        'destructive_readiness' => $dependencies?->readiness()->value,
                        'dependencies' => $dependencies === null ? null : array_map(
                            static fn ($dependency): string => $dependency->value,
                            $dependencies->categories(),
                        ),
                        'blocking_dependencies' => $dependencies === null ? null : array_map(
                            static fn ($dependency): string => $dependency->value,
                            $dependencies->blockingCategories(),
                        ),
                        'informational_dependencies' => $dependencies === null ? null : array_map(
                            static fn ($dependency): string => $dependency->value,
                            $dependencies->informationalCategories(),
                        ),
                        'missing_dependency_relationships' => $dependencies?->missingRelationships,
                        'unknown_dependency_relationships' => $dependencies?->unknownRelationships,
                        'snapshot_discovery_complete' => $clusterDependencies?->snapshotDiscoveryComplete,
                        'snapshot_count' => $clusterDependencies?->snapshotDiscoveryComplete === true
                            ? $clusterDependencies->snapshotCount
                            : null,
                        'manual_snapshot_count' => $clusterDependencies?->snapshotDiscoveryComplete === true
                            ? $clusterDependencies->manualSnapshotCount
                            : null,
                        'scheduled_snapshot_count' => $clusterDependencies?->snapshotDiscoveryComplete === true
                            ? $clusterDependencies->scheduledSnapshotCount
                            : null,
                        'recovery_evidence_complete' => $clusterDependencies?->recoveryEvidenceComplete,
                        'cluster_lifecycle_readiness' => $clusterDependencies?->lifecycleReadiness->value,
                        'structural_readiness' => $clusterDependencies?->structuralReadiness()->value,
                        'derived_parent_dependency_count' => $clusterDependencies?->derivedParentDependencyCount,
                        'parent_lifecycle_dependency' => $action->parentLifecycleDependency === null ? null : [
                            'address' => (string) $action->parentLifecycleDependency->address,
                            'classification' => $action->parentLifecycleDependency->classification->value,
                            'provenance' => $action->parentLifecycleDependency->provenance->value,
                            'destructive_role' => $action->parentLifecycleDependency->destructiveRole->value,
                            'planned_lifecycle_effect' => $action->parentLifecycleDependency->plannedLifecycleEffect,
                        ],
                        'changes' => $action->changes === [] ? null : array_map(
                            static fn (PlanChange $change): array => [
                                'field' => $change->field,
                                'before' => $change->before,
                                'after' => $change->after,
                            ],
                            $action->changes,
                        ),
                        ], static fn (mixed $value): bool => $value !== null);
                    },
                    iterator_to_array($plan, false),
                ),
            ], $output, self::JSON_CONTRACT_VERSION)
            ? ExitCode::SUCCESS->value
            : ExitCode::GENERAL_ERROR->value;
    }

    /** @param array<string, mixed> $details */
    private function error(
        OutputInterface $output,
        string $message,
        ExitCode $code,
        bool $json,
        JsonErrorCategory $category,
        string $errorCode,
        array $details = [],
    ): int {
        if ($json) {
            return $this->json->writeError(new JsonError($category, $errorCode, $message, $details), $output, self::JSON_CONTRACT_VERSION)
                ? $code->value
                : ExitCode::GENERAL_ERROR->value;
        } else {
            $output->writeln(sprintf('<error>%s</error>', $message));
        }

        return $code->value;
    }

    private function symbol(PlanOperation $operation): string
    {
        return match ($operation) {
            PlanOperation::CREATE => '+',
            PlanOperation::UPDATE => '~',
            PlanOperation::DELETE => '-',
            PlanOperation::NO_CHANGE => '=',
            PlanOperation::UNSUPPORTED => '!',
        };
    }
}
