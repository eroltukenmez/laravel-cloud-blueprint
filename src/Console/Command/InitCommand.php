<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use LaravelCloudBlueprint\Application\CloudBlueprintExporter;
use LaravelCloudBlueprint\Application\CloudBlueprintExportException;
use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Application\File\FileWriter;
use LaravelCloudBlueprint\Blueprint\Encoder\BlueprintEncoder;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\Template\StarterBlueprintTemplate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'init', description: 'Create or generate a Laravel Cloud blueprint.')]
final class InitCommand extends Command
{
    public const string DEFAULT_FILE = 'cloud.blueprint.yaml';

    public function __construct(
        private readonly FileReader $reader,
        private readonly FileWriter $writer,
        private readonly StarterBlueprintTemplate $template,
        private readonly CloudTokenProvider $tokens,
        private readonly LaravelCloudClientFactory $clients,
        private readonly CloudBlueprintExporter $exporter,
        private readonly BlueprintEncoder $encoder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Blueprint file path.', self::DEFAULT_FILE)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing blueprint file.')
            ->addOption('from-cloud', null, InputOption::VALUE_NONE, 'Generate from an existing Laravel Cloud application.')
            ->addOption('application', null, InputOption::VALUE_REQUIRED, 'Exact application name or slug.')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Source provider when unavailable from Laravel Cloud.')
            ->addOption('non-interactive', null, InputOption::VALUE_NONE, 'Disable interactive application selection.');
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

        if ($input->getOption('from-cloud') !== true) {
            return $this->writeStarter($path, $output);
        }

        $token = $this->tokens->token();
        if ($token === null) {
            $output->writeln('<error>LCB_TOKEN is not set.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }

        $providerOption = $input->getOption('provider');
        if ($providerOption !== null && !is_string($providerOption)) {
            $output->writeln('<error>The --provider option must be a source provider.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }
        $provider = is_string($providerOption) ? SourceProvider::tryFrom($providerOption) : null;
        if (is_string($providerOption) && $provider === null) {
            $output->writeln('<error>The --provider option must be github, gitlab, or bitbucket.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }

        try {
            $client = $this->clients->create($token);
            $organization = $client->organization();
            $applications = $client->applications();
            $application = $this->selectApplication($applications, $input, $output);
            $blueprint = $this->exporter->export(
                $organization,
                $application,
                $client->environments($application->id),
                $provider,
            );
            $this->writer->write($path, $this->encoder->encode($blueprint));
        } catch (CloudException|CloudBlueprintExportException|FileOperationException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return ExitCode::GENERAL_ERROR->value;
        }

        $output->writeln('<info>Generated blueprint from Laravel Cloud.</info>');
        $output->writeln('');
        $output->writeln('Application:');
        $output->writeln('  ' . $application->name);
        $output->writeln('');
        $output->writeln('Included: Application, Region, Repository, Environments, Branches');
        $output->writeln('Not exported: Environment variables, Secrets, Databases, Caches, Other unsupported resources');
        $output->writeln('No resources were imported into LCB state.');
        $output->writeln(sprintf('<info>Created blueprint file "%s".</info>', $path));
        $output->writeln('Review the generated blueprint before applying.');

        return ExitCode::SUCCESS->value;
    }

    private function writeStarter(string $path, OutputInterface $output): int
    {
        try {
            $this->writer->write($path, $this->template->contents());
        } catch (FileOperationException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return ExitCode::GENERAL_ERROR->value;
        }

        $output->writeln(sprintf('<info>Created blueprint file "%s".</info>', $path));

        return ExitCode::SUCCESS->value;
    }

    /**
     * @param list<CloudApplication> $applications
     * @throws CloudBlueprintExportException
     */
    private function selectApplication(
        array $applications,
        InputInterface $input,
        OutputInterface $output,
    ): CloudApplication {
        if ($applications === []) {
            throw new CloudBlueprintExportException('No Laravel Cloud applications were found.');
        }

        $selection = $input->getOption('application');
        if ($selection !== null && !is_string($selection)) {
            throw new CloudBlueprintExportException('The --application option must be an application name or slug.');
        }
        if (is_string($selection)) {
            $matches = array_values(array_filter(
                $applications,
                static fn (CloudApplication $application): bool => $application->name === $selection
                    || $application->slug === $selection,
            ));
            if (count($matches) !== 1) {
                throw new CloudBlueprintExportException(sprintf(
                    'Application selection "%s" did not match exactly one application.',
                    $selection,
                ));
            }

            return $matches[0];
        }

        if (count($applications) === 1) {
            return $applications[0];
        }

        if (!$input->isInteractive() || $input->getOption('non-interactive') === true) {
            throw new CloudBlueprintExportException(
                'Multiple Laravel Cloud applications were found. Supply --application.',
            );
        }

        $output->writeln('Applications:');
        foreach ($applications as $index => $application) {
            $output->writeln(sprintf('  %d. %s', $index + 1, $application->name));
        }

        $question = new Question('Select application number: ');
        $question->setValidator(static function (mixed $answer) use ($applications): int {
            if (!is_string($answer) || !ctype_digit($answer)) {
                throw new CloudBlueprintExportException('Select an application using its number.');
            }
            $index = (int) $answer - 1;
            if (!isset($applications[$index])) {
                throw new CloudBlueprintExportException('The selected application number does not exist.');
            }

            return $index;
        });

        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new CloudBlueprintExportException('Interactive application selection is unavailable.');
        }
        $index = $helper->ask($input, $output, $question);
        if (!is_int($index)) {
            throw new CloudBlueprintExportException('Application selection failed.');
        }

        return $applications[$index];
    }
}
