<?php
declare(strict_types=1);
namespace LaravelCloudBlueprint\Console\Command;
use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecodingException;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReaderFactory;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\JsonError;
use LaravelCloudBlueprint\Console\JsonErrorCategory;
use LaravelCloudBlueprint\Console\JsonOutput;
use LaravelCloudBlueprint\Infrastructure\State\LocalFileStateStore;
use LaravelCloudBlueprint\State\Exception\StateCorruptedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\Inspection\StateInspectionCheckEvaluator;
use LaravelCloudBlueprint\State\Inspection\StateInspectionCoordinator;
use LaravelCloudBlueprint\State\Inspection\StateInspectionHumanRenderer;
use LaravelCloudBlueprint\State\Inspection\StateInspectionJsonRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'state:inspect', description: 'Read-only local State inspection; --cloud adds read-only verification and --file enables recovery guidance.')]
final class StateInspectCommand extends Command
{
    private const int JSON_CONTRACT_VERSION = 1;

    public function __construct(
        private readonly LocalFileStateStore $states,
        private readonly CloudTokenProvider $tokens,
        private readonly StateInspectionCloudReaderFactory $clients,
        private readonly FileReader $files,
        private readonly BlueprintLoader $blueprints,
        private readonly StateInspectionCoordinator $inspection = new StateInspectionCoordinator(),
        private readonly StateInspectionHumanRenderer $human = new StateInspectionHumanRenderer(),
        private readonly StateInspectionJsonRenderer $jsonRenderer = new StateInspectionJsonRenderer(),
        private readonly StateInspectionCheckEvaluator $checks = new StateInspectionCheckEvaluator(),
        private readonly JsonOutput $json = new JsonOutput(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cloud', null, InputOption::VALUE_NONE, 'Add read-only Cloud verification.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Blueprint file for recovery guidance.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output versioned JSON.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Fail only the exit status when inspection is not healthy.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $cloudRequested = $input->getOption('cloud') === true;
        $check = $input->getOption('check') === true;
        $path = LocalFileStateStore::DEFAULT_PATH;

        try {
            $loaded = $this->states->loadWithMetadata();
        } catch (StateCorruptedException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_corrupted');
        } catch (StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::STATE, 'state_read_failed');
        }

        $cloud = null;
        if ($cloudRequested) {
            $token = $this->tokens->token();
            if ($token === null) {
                return $this->error($output, 'LCB_TOKEN is not set.', ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::AUTHENTICATION, 'authentication_token_missing');
            }
            $cloud = $this->clients->create($token);
        }

        $blueprint = null;
        $file = $input->getOption('file');
        if ($file !== null) {
            if (!is_string($file)) {
                return $this->error($output, 'The --file option must be a path.', ExitCode::BLUEPRINT_ERROR, $json, JsonErrorCategory::INPUT, 'invalid_file_option');
            }
            if (!$this->files->exists($file)) {
                return $this->error($output, 'Blueprint file does not exist.', ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::FILESYSTEM, 'file_not_found');
            }

            try {
                $blueprint = $this->blueprints->load($this->files->read($file));
            } catch (StructuredDataDecodingException) {
                return $this->error($output, 'Blueprint YAML could not be decoded.', ExitCode::BLUEPRINT_ERROR, $json, JsonErrorCategory::BLUEPRINT, 'blueprint_decode_failed');
            } catch (FileOperationException $exception) {
                return $this->error($output, $exception->getMessage(), ExitCode::GENERAL_ERROR, $json, JsonErrorCategory::FILESYSTEM, 'file_read_failed');
            }

            if (!$blueprint->isValid()) {
                return $this->error(
                    $output,
                    'Blueprint validation failed.',
                    ExitCode::BLUEPRINT_ERROR,
                    $json,
                    JsonErrorCategory::BLUEPRINT,
                    'blueprint_invalid',
                    ['validation_errors' => array_map(
                        static fn ($error): array => [
                            'path' => $error->path,
                            'code' => $error->code->value,
                            'message' => $error->message,
                        ],
                        iterator_to_array($blueprint->validation, false),
                    )],
                );
            }
        }

        $inspection = $this->inspection->inspect($loaded, $cloud, $blueprint, is_string($file) ? $file : null);
        $complete = !$cloudRequested || $inspection->cloudEvidence->complete;
        $result = $check ? $this->checks->evaluate($inspection->report, $cloudRequested, $complete) : null;

        if ($json) {
            if (!$this->json->write(
                $this->jsonRenderer->render($path, $loaded, $inspection->report, $cloudRequested, $complete, $inspection->recovery),
                $output,
                self::JSON_CONTRACT_VERSION,
            )) {
                return ExitCode::GENERAL_ERROR->value;
            }
        } else {
            $output->writeln($this->human->render($path, $loaded, $inspection->report, $cloudRequested, $complete, $inspection->recovery, $result));
        }

        return $result?->passed() === false ? ExitCode::CHECK_FAILED->value : ExitCode::SUCCESS->value;
    }

    /** @param array<string, mixed> $details */
    private function error(
        OutputInterface $out,
        string $message,
        ExitCode $code,
        bool $json,
        JsonErrorCategory $category,
        string $errorCode,
        array $details = [],
    ): int {
        if ($json) {
            return $this->json->writeError(new JsonError($category, $errorCode, $message, $details), $out, self::JSON_CONTRACT_VERSION)
                ? $code->value
                : ExitCode::GENERAL_ERROR->value;
        }

        $out->writeln('<error>' . $message . '</error>');
        return $code->value;
    }
}
