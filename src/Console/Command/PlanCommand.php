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
use LaravelCloudBlueprint\Console\JsonOutput;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanChange;
use LaravelCloudBlueprint\Planning\PlanOperation;
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
            return $this->error($output, 'The --file option must be a path.', ExitCode::GENERAL_ERROR, $json);
        }

        if (!$this->files->exists($path)) {
            return $this->error($output, sprintf('Blueprint file "%s" does not exist.', $path), ExitCode::GENERAL_ERROR, $json);
        }

        try {
            $loaded = $this->blueprints->load($this->files->read($path));
        } catch (StructuredDataDecodingException) {
            return $this->error($output, 'Blueprint YAML could not be decoded.', ExitCode::BLUEPRINT_ERROR, $json);
        } catch (FileOperationException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json);
        }

        if (!$loaded->isValid()) {
            if ($json) {
                $this->json->write([
                    'status' => 'validation_failed',
                    'errors' => array_map(
                        static fn ($error): array => ['path' => $error->path, 'code' => $error->code->value, 'message' => $error->message],
                        iterator_to_array($loaded->validation, false),
                    ),
                ], $output);
                return ExitCode::BLUEPRINT_ERROR->value;
            }
            foreach ($loaded->validation as $error) {
                $output->writeln(sprintf('[%s] %s: %s', $error->code->value, $error->path, $error->message));
            }
            $output->writeln(sprintf('Blueprint has %d validation error(s).', count($loaded->validation)));
            return ExitCode::BLUEPRINT_ERROR->value;
        }

        $token = $this->tokens->token();
        if ($token === null) {
            return $this->error($output, 'LCB_TOKEN is not set.', ExitCode::GENERAL_ERROR, $json);
        }

        try {
            $plan = $this->planner->create(
                $loaded->blueprint(),
                $this->clients->create($token),
                $this->states->load(),
            );
        } catch (OrganizationMismatchException|AmbiguousResourceMatchException|MissingEnvironmentValueException|CloudException|StateCorruptedException|StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json);
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
        $unchanged = $plan->countByOperation(PlanOperation::NO_CHANGE);
        $unsupported = $plan->countByOperation(PlanOperation::UNSUPPORTED);

        $hasUnmanagedMatches = false;
        foreach ($plan as $action) {
            if (str_contains($action->reason, 'unmanaged')) {
                $hasUnmanagedMatches = true;
                break;
            }
        }

        if ($create === 0 && $update === 0 && $unsupported === 0 && !$hasUnmanagedMatches) {
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
            $output->writeln('');
        }

        $output->writeln(sprintf(
            'Plan: %d to create, %d to update, %d unchanged, %d unsupported.',
            $create,
            $update,
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
                    'no_change' => $plan->countByOperation(PlanOperation::NO_CHANGE),
                    'unsupported' => $plan->countByOperation(PlanOperation::UNSUPPORTED),
                ],
                'actions' => array_map(
                    static fn (PlanAction $action): array => array_filter([
                        'resource' => (string) $action->address,
                        'type' => $action->resourceType->value,
                        'operation' => $action->operation->value,
                        'reason' => $action->reason,
                        'changes' => $action->changes === [] ? null : array_map(
                            static fn (PlanChange $change): array => [
                                'field' => $change->field,
                                'before' => $change->before,
                                'after' => $change->after,
                            ],
                            $action->changes,
                        ),
                    ], static fn (mixed $value): bool => $value !== null),
                    iterator_to_array($plan, false),
                ),
            ], $output)
            ? ExitCode::SUCCESS->value
            : ExitCode::GENERAL_ERROR->value;
    }

    private function error(OutputInterface $output, string $message, ExitCode $code, bool $json): int
    {
        if ($json) {
            $this->json->write(['status' => 'error', 'message' => $message], $output);
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
            PlanOperation::NO_CHANGE => '=',
            PlanOperation::UNSUPPORTED => '!',
        };
    }
}
