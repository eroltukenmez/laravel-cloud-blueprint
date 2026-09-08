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
    public function __construct(private readonly LocalFileStateStore $states, private readonly CloudTokenProvider $tokens, private readonly StateInspectionCloudReaderFactory $clients, private readonly FileReader $files, private readonly BlueprintLoader $blueprints, private readonly StateInspectionCoordinator $inspection = new StateInspectionCoordinator(), private readonly StateInspectionHumanRenderer $human = new StateInspectionHumanRenderer(), private readonly StateInspectionJsonRenderer $jsonRenderer = new StateInspectionJsonRenderer(), private readonly StateInspectionCheckEvaluator $checks = new StateInspectionCheckEvaluator(), private readonly JsonOutput $json = new JsonOutput()) { parent::__construct(); }
    protected function configure(): void { $this->addOption('cloud', null, InputOption::VALUE_NONE, 'Add read-only Cloud verification.')->addOption('file', null, InputOption::VALUE_REQUIRED, 'Blueprint file for recovery guidance.')->addOption('json', null, InputOption::VALUE_NONE, 'Output versioned JSON.')->addOption('check', null, InputOption::VALUE_NONE, 'Fail only the exit status when inspection is not healthy.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true; $cloudRequested = $input->getOption('cloud') === true; $check = $input->getOption('check') === true; $path = LocalFileStateStore::DEFAULT_PATH;
        try { $loaded = $this->states->loadWithMetadata(); } catch (StateCorruptedException|StateStorageException $e) { return $this->error($output, $e->getMessage(), ExitCode::GENERAL_ERROR, $json); }
        $cloud = null;
        if ($cloudRequested) { $token = $this->tokens->token(); if ($token === null) return $this->error($output, 'LCB_TOKEN is not set.', ExitCode::GENERAL_ERROR, $json); $cloud = $this->clients->create($token); }
        $blueprint = null;
        $file = $input->getOption('file'); if ($file !== null) { if (!is_string($file) || !$this->files->exists($file)) return $this->error($output, 'Blueprint file does not exist.', ExitCode::BLUEPRINT_ERROR, $json); try { $blueprint = $this->blueprints->load($this->files->read($file)); } catch (StructuredDataDecodingException) { return $this->error($output, 'Blueprint YAML could not be decoded.', ExitCode::BLUEPRINT_ERROR, $json); } catch (FileOperationException $e) { return $this->error($output, $e->getMessage(), ExitCode::GENERAL_ERROR, $json); } if (!$blueprint->isValid()) return $this->error($output, 'Blueprint validation failed.', ExitCode::BLUEPRINT_ERROR, $json); }
        $inspection = $this->inspection->inspect($loaded, $cloud, $blueprint, is_string($file) ? $file : null);
        $complete = !$cloudRequested || $inspection->cloudEvidence->complete;
        $result = $check ? $this->checks->evaluate($inspection->report, $cloudRequested, $complete) : null;
        if ($json) { if (!$this->json->write($this->jsonRenderer->render($path, $loaded, $inspection->report, $cloudRequested, $complete, $inspection->recovery), $output)) return ExitCode::GENERAL_ERROR->value; } else { $output->writeln($this->human->render($path, $loaded, $inspection->report, $cloudRequested, $complete, $inspection->recovery, $result)); }
        return $result?->passed() === false ? ExitCode::CHECK_FAILED->value : ExitCode::SUCCESS->value;
    }
    private function error(OutputInterface $out,string $message,ExitCode $code,bool $json): int { $json ? $this->json->write(['status'=>'error','message'=>$message],$out) : $out->writeln('<error>'.$message.'</error>'); return $code->value; }
}
