<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Application\File\FileWriter;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\Template\StarterBlueprintTemplate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'init', description: 'Create a starter Laravel Cloud blueprint.')]
final class InitCommand extends Command
{
    public const string DEFAULT_FILE = 'cloud.blueprint.yaml';

    public function __construct(
        private readonly FileReader $reader,
        private readonly FileWriter $writer,
        private readonly StarterBlueprintTemplate $template,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Blueprint file path.', self::DEFAULT_FILE)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing blueprint file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('file');

        if (!is_string($path)) {
            $output->writeln('<error>The --file option must be a path.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }

        if ($this->reader->exists($path) && $input->getOption('force') !== true) {
            $output->writeln(sprintf('<error>Blueprint file "%s" already exists. Use --force to overwrite it.</error>', $path));
            return ExitCode::GENERAL_ERROR->value;
        }

        try {
            $this->writer->write($path, $this->template->contents());
        } catch (FileOperationException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return ExitCode::GENERAL_ERROR->value;
        }

        $output->writeln(sprintf('<info>Created blueprint file "%s".</info>', $path));

        return ExitCode::SUCCESS->value;
    }
}
