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
use LaravelCloudBlueprint\Drift\CreateDriftReport;
use LaravelCloudBlueprint\Drift\DriftCheckEvaluator;
use LaravelCloudBlueprint\Drift\Rendering\DriftHumanRenderer;
use LaravelCloudBlueprint\Drift\Rendering\DriftJsonRenderer;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Exception\StateCorruptedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'drift',
    description: 'Report observed differences and ownership status without modifying Laravel Cloud or local State.',
)]
final class DriftCommand extends Command
{
    private const int JSON_CONTRACT_VERSION = 1;

    private readonly JsonOutput $json;

    public function __construct(
        private readonly FileReader $files,
        private readonly BlueprintLoader $blueprints,
        private readonly CloudTokenProvider $tokens,
        private readonly LaravelCloudClientFactory $clients,
        private readonly CreateDriftReport $reports,
        private readonly StateStore $states,
        private readonly DriftHumanRenderer $humanRenderer = new DriftHumanRenderer(),
        private readonly DriftJsonRenderer $jsonRenderer = new DriftJsonRenderer(),
        private readonly DriftCheckEvaluator $checkEvaluator = new DriftCheckEvaluator(),
        ?JsonOutput $json = null,
    ) {
        $this->json = $json ?? new JsonOutput();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Blueprint file path.', InitCommand::DEFAULT_FILE)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Fail when observations do not conform.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $check = $input->getOption('check') === true;
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
                        static fn ($error): array => [
                            'path' => $error->path,
                            'code' => $error->code->value,
                            'message' => $error->message,
                        ],
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
            $report = $this->reports->create(
                $loaded->blueprint(),
                $this->clients->create($token),
                $this->states->load(),
            );
        } catch (OrganizationMismatchException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::BLUEPRINT, 'blueprint_organization_mismatch');
        } catch (MissingEnvironmentValueException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::INPUT, 'environment_value_missing');
        } catch (CloudException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::CLOUD, 'cloud_read_failed');
        } catch (StateCorruptedException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_corrupted');
        } catch (StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_read_failed');
        }

        $checkResult = $check ? $this->checkEvaluator->evaluate($report) : null;

        if ($json) {
            $written = $this->json->write($this->jsonRenderer->render($report), $output, self::JSON_CONTRACT_VERSION);
            if (!$written) {
                return ExitCode::GENERAL_ERROR->value;
            }

            return $checkResult?->passed() === false
                ? ExitCode::CHECK_FAILED->value
                : ExitCode::SUCCESS->value;
        }

        $output->writeln($this->humanRenderer->render($report));

        if ($checkResult !== null) {
            if ($checkResult->passed()) {
                $output->writeln('Check passed.');
            } else {
                $failingCount = $checkResult->failingCount();
                $output->writeln(sprintf(
                    'Check failed: %d %s %s the policy.',
                    $failingCount,
                    $failingCount === 1 ? 'observation' : 'observations',
                    $failingCount === 1 ? 'violates' : 'violate',
                ));
            }
        }

        return $checkResult?->passed() === false
            ? ExitCode::CHECK_FAILED->value
            : ExitCode::SUCCESS->value;
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
}
