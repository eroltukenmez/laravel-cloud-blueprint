<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use JsonException;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Console\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cloud:inspect', description: 'Inspect Laravel Cloud resources without making changes.')]
final class CloudInspectCommand extends Command
{
    public function __construct(
        private readonly CloudTokenProvider $tokens,
        private readonly LaravelCloudClientFactory $clients,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $this->tokens->token();

        if ($token === null) {
            $output->writeln('<error>LCB_TOKEN is not set.</error>');
            return ExitCode::GENERAL_ERROR->value;
        }

        try {
            $client = $this->clients->create($token);
            $organization = $client->organization();
            $applications = [];

            foreach ($client->applications() as $application) {
                $environments = $client->environments($application->id);
                $applications[] = [$application, $environments];
            }
        } catch (CloudException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return ExitCode::GENERAL_ERROR->value;
        }

        if ($input->getOption('json') === true) {
            try {
                $output->writeln(json_encode([
                    'organization' => [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'slug' => $organization->slug,
                    ],
                    'applications' => array_map(
                        static fn (array $item): array => [
                            'id' => $item[0]->id,
                            'name' => $item[0]->name,
                            'slug' => $item[0]->slug,
                            'region' => $item[0]->region,
                            'repository' => $item[0]->repository,
                            'environments' => array_map(
                                static fn ($environment): array => [
                                    'id' => $environment->id,
                                    'name' => $environment->name,
                                    'branch' => $environment->branch,
                                ],
                                $item[1],
                            ),
                        ],
                        $applications,
                    ),
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } catch (JsonException) {
                $output->writeln('<error>Unable to encode Laravel Cloud inspection output.</error>');
                return ExitCode::GENERAL_ERROR->value;
            }

            return ExitCode::SUCCESS->value;
        }

        $output->writeln('Organization:');
        $output->writeln('  ' . $organization->name);
        $output->writeln('');
        $output->writeln('Applications:');

        foreach ($applications as [$application, $environments]) {
            $output->writeln('  ' . $application->name);
            foreach ($environments as $environment) {
                $output->writeln('    ' . $environment->name);
            }
        }

        return ExitCode::SUCCESS->value;
    }
}
