<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\JsonOutput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cloud:inspect', description: 'Discover Laravel Cloud resources using read-only requests.')]
final class CloudInspectCommand extends Command
{
    private readonly JsonOutput $json;

    public function __construct(
        private readonly CloudTokenProvider $tokens,
        private readonly LaravelCloudClientFactory $clients,
        ?JsonOutput $json = null,
    ) {
        $this->json = $json ?? new JsonOutput();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $token = $this->tokens->token();

        if ($token === null) {
            return $this->error($output, 'LCB_TOKEN is not set.', $json);
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
            return $this->error($output, $exception->getMessage(), $json);
        }

        if ($json) {
            return $this->json->write([
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
                ], $output)
                ? ExitCode::SUCCESS->value
                : ExitCode::GENERAL_ERROR->value;
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

    private function error(OutputInterface $output, string $message, bool $json): int
    {
        if ($json) {
            $this->json->write(['status' => 'error', 'message' => $message], $output);
        } else {
            $output->writeln(sprintf('<error>%s</error>', $message));
        }

        return ExitCode::GENERAL_ERROR->value;
    }
}
