<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Application\Import\ImportCandidate;
use LaravelCloudBlueprint\Application\Import\ImportProposal;
use LaravelCloudBlueprint\Application\Import\ImportRefusedException;
use LaravelCloudBlueprint\Application\Import\ImportResources;
use LaravelCloudBlueprint\Application\Import\ImportResult;
use LaravelCloudBlueprint\Application\Import\ImportStatus;
use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecodingException;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\JsonError;
use LaravelCloudBlueprint\Console\JsonErrorCategory;
use LaravelCloudBlueprint\Console\JsonOutput;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
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

#[AsCommand(name: 'import', description: 'Adopt supported existing resource identities into local state without modifying Laravel Cloud.')]
final class ImportCommand extends Command
{
    private const int JSON_CONTRACT_VERSION = 1;

    private readonly JsonOutput $jsonOutput;

    public function __construct(
        private readonly FileReader $files,
        private readonly BlueprintLoader $blueprints,
        private readonly CloudTokenProvider $tokens,
        private readonly LaravelCloudClientFactory $clients,
        private readonly ImportResources $imports,
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
            ->addOption('auto-approve', null, InputOption::VALUE_NONE, 'Import without confirmation.')
            ->addOption('non-interactive', null, InputOption::VALUE_NONE, 'Disable prompting; state changes still require --auto-approve.')
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
            return $this->error($output, 'LCB_TOKEN is not set.', ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::AUTHENTICATION, 'authentication_token_missing');
        }

        try {
            $cloud = $this->clients->create($token);
            $proposal = $this->imports->preview($loaded->blueprint(), $cloud, $this->states);
        } catch (OrganizationMismatchException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::BLUEPRINT, 'blueprint_organization_mismatch');
        } catch (ImportRefusedException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::MUTATION, 'state_mutation_refused');
        } catch (CloudException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::CLOUD, 'cloud_read_failed');
        } catch (StateCorruptedException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_corrupted');
        } catch (StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_read_failed');
        }

        if ($this->isRefused($proposal)) {
            if ($json) {
                return $this->renderJson('refused', $proposal, [], $output, 'Import contains conflicted or unsupported resources.');
            }
            $this->renderProposal($proposal, $output);
            return $this->error(
                $output,
                'Import refused. No state resources were changed.',
                ExitCode::GENERAL_ERROR,
                false,
                JsonErrorCategory::MUTATION,
                'state_mutation_refused',
            );
        }

        if ($proposal->countByStatus(ImportStatus::IMPORTABLE) === 0) {
            if ($json) {
                return $this->renderJson('no_changes', $proposal, [], $output);
            }
            $this->renderProposal($proposal, $output);
            $output->writeln('Nothing to import. Local state is unchanged.');
            return ExitCode::SUCCESS->value;
        }

        $autoApprove = $input->getOption('auto-approve') === true;
        $nonInteractive = $input->getOption('non-interactive') === true || !$input->isInteractive();
        if (!$autoApprove && ($nonInteractive || $json)) {
            return $this->error(
                $output,
                'Import requires --auto-approve when running non-interactively.',
                ExitCode::GENERAL_ERROR,
                $json,
                JsonErrorCategory::MUTATION,
                'state_mutation_refused',
            );
        }

        if (!$json) {
            $this->renderProposal($proposal, $output);
        }
        if (!$autoApprove) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'Import these existing resources into local LCB state? Cloud resources will not be modified. [y/N] ',
                false,
            );
            if (!$helper instanceof QuestionHelper || !$helper->ask($input, $output, $question)) {
                $output->writeln('Import cancelled. Local state and Cloud resources were not modified.');
                return ExitCode::SUCCESS->value;
            }
        }

        try {
            $result = $this->imports->execute($loaded->blueprint(), $cloud, $this->states);
        } catch (ImportRefusedException $exception) {
            if ($json && $exception->proposal !== null) {
                return $this->renderJson('refused', $exception->proposal, [], $output, $exception->getMessage());
            }
            if (!$json && $exception->proposal !== null) {
                $output->writeln('Import proposal changed during revalidation:');
                $this->renderProposal($exception->proposal, $output);
            }
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::MUTATION, 'state_mutation_refused');
        } catch (OrganizationMismatchException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::BLUEPRINT, 'blueprint_organization_mismatch');
        } catch (CloudException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::CLOUD, 'cloud_read_failed');
        } catch (StateCorruptedException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_corrupted');
        } catch (StateLockedException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_lock_failed');
        } catch (StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_write_failed');
        }

        if ($json) {
            return $this->renderJson('success', $result->proposal, $result->adopted, $output);
        }
        $this->renderResult($result, $output);
        return ExitCode::SUCCESS->value;
    }

    private function renderProposal(ImportProposal $proposal, OutputInterface $output): void
    {
        $output->writeln('Laravel Cloud Blueprint Import');
        $output->writeln('');
        foreach ($proposal as $candidate) {
            $output->writeln(sprintf('%s %s', $this->symbol($candidate->status), (string) $candidate->address));
            if ($candidate->status === ImportStatus::IMPORTABLE) {
                $output->writeln(sprintf('  Existing remote %s: %s', $candidate->type->value, $candidate->remoteName));
                if ($candidate->parent !== null) {
                    $output->writeln('  Parent: ' . (string) $candidate->parent);
                }
            } else {
                $output->writeln('  ' . $candidate->reason);
            }
            $output->writeln('');
        }
        $output->writeln(sprintf(
            'Import: %d to import, %d already managed, %d conflicts, %d unsupported.',
            $proposal->countByStatus(ImportStatus::IMPORTABLE),
            $proposal->countByStatus(ImportStatus::ALREADY_MANAGED),
            $proposal->countByStatus(ImportStatus::CONFLICT),
            $proposal->countByStatus(ImportStatus::UNSUPPORTED),
        ));
        $output->writeln('');
    }

    private function renderResult(ImportResult $result, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            'Import success: %d adopted, %d already managed. Cloud resources were not modified.',
            $result->adoptedCount(),
            $result->alreadyManagedCount(),
        ));
    }

    /** @param list<ImportCandidate> $adopted */
    private function renderJson(
        string $status,
        ImportProposal $proposal,
        array $adopted,
        OutputInterface $output,
        ?string $message = null,
    ): int {
        $adoptedAddresses = array_map(static fn (ImportCandidate $candidate): string => (string) $candidate->address, $adopted);
        $result = $this->json([
            'status' => $status,
            ...($message === null ? [] : ['message' => $message]),
            'summary' => [
                'importable' => $proposal->countByStatus(ImportStatus::IMPORTABLE),
                'already_managed' => $proposal->countByStatus(ImportStatus::ALREADY_MANAGED),
                'conflict' => $proposal->countByStatus(ImportStatus::CONFLICT),
                'unsupported' => $proposal->countByStatus(ImportStatus::UNSUPPORTED),
                'adopted' => count($adopted),
            ],
            'resources' => array_map(
                static fn (ImportCandidate $candidate): array => [
                    'status' => $candidate->status->value,
                    'address' => (string) $candidate->address,
                    'type' => $candidate->type->value,
                    'remote_id' => $candidate->remoteId,
                    'parent' => $candidate->parent === null ? null : (string) $candidate->parent,
                    'reason' => $candidate->status === ImportStatus::IMPORTABLE ? null : $candidate->reason,
                    'adopted' => in_array((string) $candidate->address, $adoptedAddresses, true),
                ],
                iterator_to_array($proposal, false),
            ),
        ], $output);

        return $status === 'refused' ? ExitCode::GENERAL_ERROR->value : $result;
    }

    private function isRefused(ImportProposal $proposal): bool
    {
        return $proposal->countByStatus(ImportStatus::CONFLICT) > 0
            || $proposal->countByStatus(ImportStatus::UNSUPPORTED) > 0;
    }

    private function symbol(ImportStatus $status): string
    {
        return match ($status) {
            ImportStatus::IMPORTABLE => '+',
            ImportStatus::ALREADY_MANAGED => '=',
            ImportStatus::CONFLICT, ImportStatus::UNSUPPORTED => '!',
        };
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
            return $this->jsonOutput->writeError(new JsonError($category, $errorCode, $message, $details), $output, self::JSON_CONTRACT_VERSION)
                ? $code->value
                : ExitCode::GENERAL_ERROR->value;
        } else {
            $output->writeln(sprintf('<error>%s</error>', $message));
        }
        return $code->value;
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, OutputInterface $output): int
    {
        return $this->jsonOutput->write($data, $output, self::JSON_CONTRACT_VERSION)
            ? ExitCode::SUCCESS->value
            : ExitCode::GENERAL_ERROR->value;
    }

    private function validationJson(\LaravelCloudBlueprint\Blueprint\Validation\ValidationResult $validation, OutputInterface $output): int
    {
        return $this->error(
            $output,
            'Blueprint validation failed.',
            ExitCode::BLUEPRINT_ERROR,
            true,
            JsonErrorCategory::BLUEPRINT,
            'blueprint_invalid',
            ['validation_errors' => array_map(
                static fn ($error): array => ['path' => $error->path, 'code' => $error->code->value, 'message' => $error->message],
                iterator_to_array($validation, false),
            )],
        );
    }
}
