<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\JsonError;
use LaravelCloudBlueprint\Console\JsonErrorCategory;
use LaravelCloudBlueprint\Console\JsonOutput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cloud:inspect', description: 'Discover Laravel Cloud resources using read-only requests.')]
final class CloudInspectCommand extends Command
{
    private const int JSON_CONTRACT_VERSION = 1;

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
            return $this->error($output, 'LCB_TOKEN is not set.', $json, JsonErrorCategory::AUTHENTICATION, 'authentication_token_missing');
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
            return $this->error($output, $exception->getMessage(), $json, JsonErrorCategory::CLOUD, 'cloud_read_failed');
        }

        if ($json) {
            $machineApplications = $applications;
            foreach ($machineApplications as &$item) {
                usort($item[1], static fn ($left, $right): int => [$left->name, $left->id] <=> [$right->name, $right->id]);
            }
            unset($item);
            usort($machineApplications, static fn (array $left, array $right): int => [$left[0]->name, $left[0]->id] <=> [$right[0]->name, $right[0]->id]);

            return $this->json->write([
                    'status' => 'success',
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
                        $machineApplications,
                    ),
                ], $output, self::JSON_CONTRACT_VERSION)
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

    private function error(
        OutputInterface $output,
        string $message,
        bool $json,
        JsonErrorCategory $category,
        string $errorCode,
    ): int {
        if ($json) {
            $this->json->writeError(new JsonError($category, $errorCode, $message), $output, self::JSON_CONTRACT_VERSION);
        } else {
            $output->writeln(sprintf('<error>%s</error>', $message));
        }

        return ExitCode::GENERAL_ERROR->value;
    }
}
