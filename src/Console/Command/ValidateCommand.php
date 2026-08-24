<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecodingException;
use LaravelCloudBlueprint\Console\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'validate', description: 'Validate a Laravel Cloud blueprint.')]
final class ValidateCommand extends Command
{
    public function __construct(
        private readonly FileReader $reader,
        private readonly BlueprintLoader $loader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'file',
            null,
            InputOption::VALUE_REQUIRED,
            'Blueprint file path.',
            InitCommand::DEFAULT_FILE,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('file');

        if (!is_string($path)) {
            $output->writeln('<error>The --file option must be a path.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }

        if (!$this->reader->exists($path)) {
            $output->writeln(sprintf('<error>Blueprint file "%s" does not exist.</error>', $path));
            return ExitCode::GENERAL_ERROR->value;
        }

        try {
            $result = $this->loader->load($this->reader->read($path));
        } catch (StructuredDataDecodingException) {
            $output->writeln('<error>Blueprint YAML could not be decoded.</error>');
            return ExitCode::BLUEPRINT_ERROR->value;
        } catch (FileOperationException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return ExitCode::GENERAL_ERROR->value;
        }

        if (!$result->isValid()) {
            foreach ($result->validation as $error) {
                $output->writeln(sprintf(
                    '<error>[%s] %s: %s</error>',
                    $error->code->value,
                    $error->path,
                    $error->message,
                ));
            }

            $output->writeln(sprintf('<error>Blueprint has %d validation error(s).</error>', count($result->validation)));

            return ExitCode::BLUEPRINT_ERROR->value;
        }

        $output->writeln('<info>Blueprint is valid.</info>');

        return ExitCode::SUCCESS->value;
    }
}
