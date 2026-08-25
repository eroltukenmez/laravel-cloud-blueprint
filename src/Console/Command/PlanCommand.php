<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use JsonException;
use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecodingException;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanChange;
use LaravelCloudBlueprint\Planning\PlanOperation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'plan', description: 'Create a read-only Laravel Cloud execution plan.')]
final class PlanCommand extends Command
{
    public function __construct(
        private readonly FileReader $files,
        private readonly BlueprintLoader $blueprints,
        private readonly CloudTokenProvider $tokens,
        private readonly LaravelCloudClientFactory $clients,
        private readonly CreatePlan $planner,
    ) {
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
        $path = $input->getOption('file');
        if (!is_string($path)) {
            $output->writeln('<error>The --file option must be a path.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }

        if (!$this->files->exists($path)) {
            $output->writeln(sprintf('<error>Blueprint file "%s" does not exist.</error>', $path));
            return ExitCode::GENERAL_ERROR->value;
        }

        try {
            $loaded = $this->blueprints->load($this->files->read($path));
        } catch (StructuredDataDecodingException) {
            $output->writeln('<error>Blueprint YAML could not be decoded.</error>');
            return ExitCode::BLUEPRINT_ERROR->value;
        } catch (FileOperationException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return ExitCode::GENERAL_ERROR->value;
        }

        if (!$loaded->isValid()) {
            foreach ($loaded->validation as $error) {
                $output->writeln(sprintf('[%s] %s: %s', $error->code->value, $error->path, $error->message));
            }
            $output->writeln(sprintf('Blueprint has %d validation error(s).', count($loaded->validation)));
            return ExitCode::BLUEPRINT_ERROR->value;
        }

        $token = $this->tokens->token();
        if ($token === null) {
            $output->writeln('<error>LCB_TOKEN is not set.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }

        try {
            $plan = $this->planner->create($loaded->blueprint(), $this->clients->create($token));
        } catch (OrganizationMismatchException|AmbiguousResourceMatchException|MissingEnvironmentValueException|CloudException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return ExitCode::GENERAL_ERROR->value;
        }

        if ($input->getOption('json') === true) {
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

        if ($create === 0 && $update === 0 && $unsupported === 0) {
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
        try {
            $output->writeln(json_encode([
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
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            $output->writeln('<error>Unable to encode the execution plan.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }

        return ExitCode::SUCCESS->value;
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
