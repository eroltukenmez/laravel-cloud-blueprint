<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Apply\ApplyResourceOutcome;
use LaravelCloudBlueprint\Apply\ApplyResult;
use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Apply\Exception\StateIdentityConflictException;
use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecodingException;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\JsonOutput;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Exception\StateCorruptedException;
use LaravelCloudBlueprint\State\Exception\StateLockedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'apply', description: 'Apply supported changes; may modify Laravel Cloud resources.')]
final class ApplyCommand extends Command
{
    private readonly JsonOutput $jsonOutput;

    public function __construct(
        private readonly FileReader $files,
        private readonly BlueprintLoader $blueprints,
        private readonly CloudTokenProvider $tokens,
        private readonly LaravelCloudClientFactory $clients,
        private readonly CreatePlan $planner,
        private readonly CreateOnlyApply $apply,
        private readonly StateStore $states,
        ?JsonOutput $jsonOutput = null,
    ) {
        $this->jsonOutput = $jsonOutput ?? new JsonOutput();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Blueprint file path.', InitCommand::DEFAULT_FILE)
            ->addOption('auto-approve', null, InputOption::VALUE_NONE, 'Apply without confirmation.')
            ->addOption('non-interactive', null, InputOption::VALUE_NONE, 'Disable prompting; changes still require --auto-approve.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonOutput = $input->getOption('json') === true;
        $path = $input->getOption('file');
        if (!is_string($path)) {
            return $this->error($output, 'The --file option must be a path.', ExitCode::GENERAL_ERROR, $jsonOutput);
        }
        if (!$this->files->exists($path)) {
            return $this->error($output, sprintf('Blueprint file "%s" does not exist.', $path), ExitCode::GENERAL_ERROR, $jsonOutput);
        }

        try {
            $loaded = $this->blueprints->load($this->files->read($path));
        } catch (StructuredDataDecodingException) {
            return $this->error($output, 'Blueprint YAML could not be decoded.', ExitCode::BLUEPRINT_ERROR, $jsonOutput);
        } catch (FileOperationException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $jsonOutput);
        }

        if (!$loaded->isValid()) {
            if ($jsonOutput) {
                return $this->validationJson($loaded->validation, $output);
            }
            foreach ($loaded->validation as $error) {
                $output->writeln(sprintf('[%s] %s: %s', $error->code->value, $error->path, $error->message));
            }
            $output->writeln(sprintf('Blueprint has %d validation error(s).', count($loaded->validation)));
            return ExitCode::BLUEPRINT_ERROR->value;
        }

        $token = $this->tokens->token();
        if ($token === null) {
            return $this->error($output, 'LCB_TOKEN is not set.', ExitCode::GENERAL_ERROR, $jsonOutput);
        }

        try {
            $cloud = $this->clients->create($token);
            $plan = $this->planner->create($loaded->blueprint(), $cloud, $this->states->load());
        } catch (CloudValidationException $exception) {
            return $this->renderCloudValidationFailure($exception, $output, $jsonOutput);
        } catch (OrganizationMismatchException|AmbiguousResourceMatchException|MissingEnvironmentValueException|CloudException|StateCorruptedException|StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $jsonOutput);
        }

        try {
            $this->apply->assertSupported($plan);
        } catch (ApplyRefusedException $exception) {
            return $this->error($output, 'Apply refused. ' . $exception->getMessage(), ExitCode::GENERAL_ERROR, $jsonOutput);
        }

        if (!$plan->hasActionableChanges()) {
            if ($jsonOutput) {
                return $this->json(['status' => 'success', 'summary' => ['created' => 0, 'updated' => 0, 'unchanged' => count($plan), 'deleted' => 0, 'failed' => 0], 'resources' => []], $output);
            }
            $output->writeln('No changes.');
            $output->writeln('');
            $output->writeln('Laravel Cloud infrastructure matches the blueprint.');
            return ExitCode::SUCCESS->value;
        }

        $autoApprove = $input->getOption('auto-approve') === true;
        $nonInteractive = $input->getOption('non-interactive') === true || !$input->isInteractive();
        $hasDestructive = $plan->countByOperation(PlanOperation::DELETE) > 0;

        if (!$autoApprove && ($nonInteractive || $jsonOutput)) {
            return $this->error($output, 'Apply requires --auto-approve when running non-interactively.', ExitCode::GENERAL_ERROR, $jsonOutput);
        }

        if (!$jsonOutput) {
            $this->renderPlan($plan, $output);
        }

        if (!$autoApprove) {
            $helper = $this->getHelper('question');
            if (!$helper instanceof QuestionHelper) {
                $output->writeln('Apply cancelled. No resources were modified.');
                return ExitCode::SUCCESS->value;
            }

            if ($hasDestructive) {
                $output->writeln('');
                $output->writeln($this->destructiveWarning($plan));
                $output->writeln('This is Cloud deletion, not the local-only state:unmanage operation.');
                $output->writeln('');
                if (!$helper->ask($input, $output, new ConfirmationQuestion('Confirm destructive apply? [y/N] ', false))) {
                    $output->writeln('Apply cancelled. No resources were modified.');
                    return ExitCode::SUCCESS->value;
                }
            } elseif (!$helper->ask($input, $output, new ConfirmationQuestion('Apply these changes? [y/N] ', false))) {
                $output->writeln('Apply cancelled. No resources were modified.');
                return ExitCode::SUCCESS->value;
            }
        }

        try {
            $result = $this->apply->execute(
                $loaded->blueprint(),
                $plan,
                $cloud,
                $this->states,
                function () use ($path): \LaravelCloudBlueprint\Blueprint\Blueprint {
                    try {
                        $locked = $this->blueprints->load($this->files->read($path));
                    } catch (StructuredDataDecodingException|FileOperationException $exception) {
                        throw new ApplyRefusedException('Blueprint changed or became unreadable after approval: ' . $exception->getMessage());
                    }
                    if (!$locked->isValid()) {
                        throw new ApplyRefusedException('Blueprint changed and is invalid after approval. No resources were modified.');
                    }

                    return $locked->blueprint();
                },
            );
        } catch (ApplyRefusedException|StateIdentityConflictException|StateCorruptedException|StateLockedException|StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $jsonOutput);
        }

        if ($jsonOutput) {
            $exit = $this->renderResultJson($result, $output);
        } else {
            $this->renderResultText($result, $output);
            $exit = ExitCode::SUCCESS->value;
        }

        return $result->status === ApplyStatus::SUCCESS ? $exit : ExitCode::GENERAL_ERROR->value;
    }

    private function renderPlan(\LaravelCloudBlueprint\Planning\ExecutionPlan $plan, OutputInterface $output): void
    {
        $output->writeln('Laravel Cloud Blueprint Apply');
        $output->writeln('');
        foreach ($plan as $action) {
            $symbol = match ($action->operation) {
                PlanOperation::CREATE => '+',
                PlanOperation::UPDATE => '~',
                PlanOperation::DELETE => '-',
                PlanOperation::NO_CHANGE => '=',
                PlanOperation::UNSUPPORTED => '!',
            };
            $output->writeln(sprintf('%s %s', $symbol, (string) $action->address));
            if ($action->changes === []) {
                $output->writeln('  ' . $action->reason);
            } else {
                foreach ($action->changes as $change) {
                    $output->writeln(sprintf('  %s: %s → %s', $change->field, $change->before, $change->after));
                }
            }
        }
        $output->writeln('');
    }

    private function destructiveWarning(\LaravelCloudBlueprint\Planning\ExecutionPlan $plan): string
    {
        $types = [];
        foreach ($plan as $action) {
            if ($action->operation === PlanOperation::DELETE) {
                $types[$action->resourceType->value] = true;
            }
        }
        if (array_keys($types) === [ResourceType::ENVIRONMENT->value]) {
            return 'WARNING: This permanently deletes a Laravel Cloud Environment and cannot be undone.';
        }
        if (array_keys($types) === [ResourceType::DATABASE->value]) {
            return 'WARNING: This permanently deletes a Laravel Cloud logical Database and cannot be undone.';
        }
        return 'WARNING: This permanently deletes Laravel Cloud resources and cannot be undone.';
    }

    private function renderResultText(ApplyResult $result, OutputInterface $output): void
    {
        foreach ($result as $outcome) {
            $output->writeln(sprintf('%s: %s', (string) $outcome->address, $outcome->operation->value));
            if ($outcome->deleted !== null
                || $outcome->confirmed !== null
                || $outcome->stateCheckpointed !== null) {
                $output->writeln('  outcome: ' . $outcome->outcome->value);
            }
            if ($outcome->message !== null) {
                $output->writeln('  ' . $outcome->message);
            }
            if ($outcome->validation !== null) {
                $this->renderValidationDetails($outcome->validation, $output, '  ');
            }
        }
        $output->writeln($result->hasDestructiveOutcomes()
            ? sprintf(
                'Apply %s: %d created, %d updated, %d deleted, %d unchanged.',
                str_replace('_', ' ', $result->status->value),
                $result->createdCount(),
                $result->updatedCount(),
                $result->deletedCount(),
                $result->unchangedCount(),
            )
            : sprintf(
                'Apply %s: %d created, %d updated, %d unchanged.',
                str_replace('_', ' ', $result->status->value),
                $result->createdCount(),
                $result->updatedCount(),
                $result->unchangedCount(),
            ));
    }

    private function renderResultJson(ApplyResult $result, OutputInterface $output): int
    {
        return $this->json([
            'status' => $result->status->value,
            'summary' => [
                'created' => $result->createdCount(),
                'updated' => $result->updatedCount(),
                'unchanged' => $result->unchangedCount(),
                'deleted' => $result->deletedCount(),
                'failed' => $result->failedCount(),
            ],
            'resources' => array_map($this->outcomeJson(...), iterator_to_array($result, false)),
        ], $output);
    }

    /** @return array<string, mixed> */
    private function outcomeJson(ApplyResourceOutcome $outcome): array
    {
        $base = [
            'resource' => (string) $outcome->address,
            'operation' => $outcome->operation->value,
            'outcome' => $outcome->outcome->value,
            ...($outcome->message === null ? [] : ['message' => $outcome->message]),
            ...($outcome->validation === null ? [] : [
                'validation' => [
                    'message' => $outcome->validation->apiMessage,
                    'errors' => $outcome->validation->fieldErrors,
                ],
            ]),
        ];

        if ($outcome->deleted !== null
            || $outcome->confirmed !== null
            || $outcome->stateCheckpointed !== null) {
            $base['deleted'] = $outcome->deleted;
            $base['confirmed'] = $outcome->confirmed;
            $base['state_checkpointed'] = $outcome->stateCheckpointed;
        }

        return $base;
    }

    private function renderCloudValidationFailure(
        CloudValidationException $exception,
        OutputInterface $output,
        bool $jsonOutput,
    ): int {
        if ($jsonOutput) {
            $this->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
                'validation' => [
                    'message' => $exception->apiMessage,
                    'errors' => $exception->fieldErrors,
                ],
            ], $output);
        } else {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            $this->renderValidationDetails($exception, $output);
        }

        return ExitCode::GENERAL_ERROR->value;
    }

    private function renderValidationDetails(
        CloudValidationException $exception,
        OutputInterface $output,
        string $indent = '',
    ): void {
        if ($exception->apiMessage !== null && $exception->apiMessage !== $exception->getMessage()) {
            $output->writeln($indent . $exception->apiMessage);
        }
        foreach ($exception->fieldErrors as $field => $messages) {
            $output->writeln($indent . $field . ':');
            foreach ($messages as $message) {
                $output->writeln($indent . '  ' . $message);
            }
        }
    }

    private function error(OutputInterface $output, string $message, ExitCode $code, bool $json = false): int
    {
        if ($json) {
            $this->json(['status' => 'error', 'message' => $message], $output);
            return $code->value;
        }

        $output->writeln(sprintf('<error>%s</error>', $message));
        return $code->value;
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, OutputInterface $output): int
    {
        return $this->jsonOutput->write($data, $output)
            ? ExitCode::SUCCESS->value
            : ExitCode::GENERAL_ERROR->value;
    }

    private function validationJson(\LaravelCloudBlueprint\Blueprint\Validation\ValidationResult $validation, OutputInterface $output): int
    {
        $this->json([
            'status' => 'validation_failed',
            'errors' => array_map(
                static fn ($error): array => ['path' => $error->path, 'code' => $error->code->value, 'message' => $error->message],
                iterator_to_array($validation, false),
            ),
        ], $output);
        return ExitCode::BLUEPRINT_ERROR->value;
    }
}
