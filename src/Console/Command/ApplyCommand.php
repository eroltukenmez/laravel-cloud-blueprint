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
                return $this->json(['status' => 'success', 'summary' => ['created' => 0, 'updated' => 0, 'unchanged' => count($plan)], 'resources' => []], $output);
            }
            $output->writeln('No changes.');
            $output->writeln('');
            $output->writeln('Laravel Cloud infrastructure matches the blueprint.');
            return ExitCode::SUCCESS->value;
        }

        $autoApprove = $input->getOption('auto-approve') === true;
        $nonInteractive = $input->getOption('non-interactive') === true || !$input->isInteractive();

        if (!$autoApprove && ($nonInteractive || $jsonOutput)) {
            return $this->error($output, 'Apply requires --auto-approve when running non-interactively.', ExitCode::GENERAL_ERROR, $jsonOutput);
        }

        if (!$jsonOutput) {
            $this->renderPlan($plan, $output);
        }

        if (!$autoApprove) {
            $helper = $this->getHelper('question');
            if (!$helper instanceof QuestionHelper
                || !$helper->ask($input, $output, new ConfirmationQuestion('Apply these changes? [y/N] ', false))) {
                $output->writeln('Apply cancelled. No resources were modified.');
                return ExitCode::SUCCESS->value;
            }
        }

        try {
            $result = $this->apply->execute($loaded->blueprint(), $plan, $cloud, $this->states);
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

    private function renderResultText(ApplyResult $result, OutputInterface $output): void
    {
        foreach ($result as $outcome) {
            $output->writeln(sprintf('%s: %s', (string) $outcome->address, $outcome->operation->value));
            if ($outcome->message !== null) {
                $output->writeln('  ' . $outcome->message);
            }
            if ($outcome->validation !== null) {
                $this->renderValidationDetails($outcome->validation, $output, '  ');
            }
        }
        $output->writeln(sprintf(
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
            ],
            'resources' => array_map($this->outcomeJson(...), iterator_to_array($result, false)),
        ], $output);
    }

    /** @return array<string, mixed> */
    private function outcomeJson(ApplyResourceOutcome $outcome): array
    {
        return [
            'resource' => (string) $outcome->address,
            'operation' => $outcome->operation->value,
            ...($outcome->message === null ? [] : ['message' => $outcome->message]),
            ...($outcome->validation === null ? [] : [
                'validation' => [
                    'message' => $outcome->validation->apiMessage,
                    'errors' => $outcome->validation->fieldErrors,
                ],
            ]),
        ];
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
