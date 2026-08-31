<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Command;

use InvalidArgumentException;
use LaravelCloudBlueprint\Application\State\ReleaseStateOwnership;
use LaravelCloudBlueprint\Application\State\StateOwnershipReleaseProposal;
use LaravelCloudBlueprint\Application\State\StateOwnershipReleaseRefusedException;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\JsonOutput;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Exception\StateCorruptedException;
use LaravelCloudBlueprint\State\Exception\StateLockedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(
    name: 'state:unmanage',
    description: 'Release local LCB ownership of a managed resource without modifying Laravel Cloud.',
)]
final class StateUnmanageCommand extends Command
{
    private readonly JsonOutput $jsonOutput;

    public function __construct(
        private readonly ReleaseStateOwnership $releases,
        private readonly StateStore $states,
        ?JsonOutput $jsonOutput = null,
    ) {
        $this->jsonOutput = $jsonOutput ?? new JsonOutput();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('address', InputArgument::REQUIRED, 'Exact State resource address to unmanage.')
            ->addOption('auto-approve', null, InputOption::VALUE_NONE, 'Release ownership without confirmation.')
            ->addOption('non-interactive', null, InputOption::VALUE_NONE, 'Disable prompting; release still requires --auto-approve.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $value = $input->getArgument('address');
        if (!is_string($value)) {
            return $this->error($output, 'State resource address must be a string.', $json);
        }

        try {
            $address = ResourceAddress::fromString($value);
        } catch (InvalidArgumentException) {
            return $this->error($output, sprintf('Invalid State resource address "%s".', $value), $json);
        }
        if (!$this->isStateResourceType($address->type)) {
            return $this->error($output, sprintf(
                'Resource type "%s" is not State-owned and cannot be unmanaged.',
                $address->type->value,
            ), $json, $address);
        }

        try {
            $proposal = $this->releases->preview($address, $this->states);
        } catch (StateCorruptedException|StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), $json, $address);
        }

        if (!$proposal->isManaged()) {
            return $this->noChanges($proposal, $output, $json);
        }
        if (!$proposal->canRelease()) {
            return $this->refused(
                $proposal,
                'Cannot release ownership while managed children remain.',
                $output,
                $json,
            );
        }

        $autoApprove = $input->getOption('auto-approve') === true;
        $nonInteractive = $input->getOption('non-interactive') === true || !$input->isInteractive();
        if (!$json) {
            $this->renderPreview($proposal, $output);
        }
        if (!$autoApprove && ($nonInteractive || $json)) {
            return $this->refused(
                $proposal,
                'Ownership release requires --auto-approve when running non-interactively.',
                $output,
                $json,
                renderHumanProposal: false,
            );
        }

        if (!$autoApprove) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "Release local LCB ownership?\nThe Laravel Cloud resource will not be modified. [y/N] ",
                false,
            );
            if (!$helper instanceof QuestionHelper || !$helper->ask($input, $output, $question)) {
                $output->writeln('Ownership release cancelled. Local State and Laravel Cloud were not modified.');
                return ExitCode::SUCCESS->value;
            }
        }

        try {
            $result = $this->releases->execute($proposal, $this->states);
        } catch (StateOwnershipReleaseRefusedException $exception) {
            return $this->refused($exception->proposal, $exception->getMessage(), $output, $json);
        } catch (StateCorruptedException|StateLockedException|StateStorageException $exception) {
            return $this->error($output, $exception->getMessage(), $json, $address);
        }

        if ($json) {
            return $this->writeJson([
                'status' => 'success',
                'message' => 'Local LCB ownership was released. The Laravel Cloud resource was not modified or deleted.',
                'resource' => $this->resource($result->proposal),
                'released' => true,
            ], $output);
        }

        $output->writeln(sprintf('Released local LCB ownership of %s.', (string) $address));
        $output->writeln('The Laravel Cloud resource was not modified or deleted.');
        return ExitCode::SUCCESS->value;
    }

    private function renderPreview(StateOwnershipReleaseProposal $proposal, OutputInterface $output): void
    {
        $resource = $proposal->resource;
        if ($resource === null) {
            return;
        }

        $output->writeln('State ownership release');
        $output->writeln('');
        $output->writeln('Resource: ' . (string) $resource->address);
        $output->writeln('Type: ' . $resource->type->value);
        if ($resource->parent !== null) {
            $output->writeln('Parent: ' . (string) $resource->parent);
        }
        $output->writeln('');
        $output->writeln('This removes only the local LCB State ownership.');
        $output->writeln('The Laravel Cloud resource will not be modified or deleted.');
        $output->writeln('If the resource remains in the Blueprint, the next plan will evaluate it as unmanaged.');
        $output->writeln('');
    }

    private function noChanges(
        StateOwnershipReleaseProposal $proposal,
        OutputInterface $output,
        bool $json,
    ): int {
        $message = 'Resource is not currently managed. Local State and Laravel Cloud are unchanged.';
        if ($json) {
            return $this->writeJson([
                'status' => 'no_changes',
                'message' => $message,
                'resource' => ['address' => (string) $proposal->address],
                'released' => false,
            ], $output);
        }

        $output->writeln($message);
        return ExitCode::SUCCESS->value;
    }

    private function refused(
        StateOwnershipReleaseProposal $proposal,
        string $message,
        OutputInterface $output,
        bool $json,
        bool $renderHumanProposal = true,
    ): int {
        if ($json) {
            return $this->writeJson([
                'status' => 'refused',
                'message' => $message . ' No Laravel Cloud resource was modified.',
                'resource' => $this->resource($proposal),
                'children' => array_map(
                    static fn ($child): string => (string) $child->address,
                    $proposal->children(),
                ),
                'released' => false,
            ], $output, ExitCode::GENERAL_ERROR);
        }

        if ($renderHumanProposal && $proposal->isManaged()) {
            $this->renderPreview($proposal, $output);
        }
        $output->writeln('<error>' . $message . '</error>');
        foreach ($proposal->children() as $child) {
            $output->writeln('- ' . (string) $child->address);
        }
        $output->writeln('No Laravel Cloud resource was modified.');
        return ExitCode::GENERAL_ERROR->value;
    }

    private function error(
        OutputInterface $output,
        string $message,
        bool $json,
        ?ResourceAddress $address = null,
    ): int {
        if ($json) {
            return $this->writeJson([
                'status' => 'error',
                'message' => $message,
                ...($address === null ? [] : ['resource' => ['address' => (string) $address]]),
                'released' => false,
            ], $output, ExitCode::GENERAL_ERROR);
        }

        $output->writeln('<error>' . $message . '</error>');
        return ExitCode::GENERAL_ERROR->value;
    }

    /** @return array{address: string, type: string, parent: string|null} */
    private function resource(StateOwnershipReleaseProposal $proposal): array
    {
        $resource = $proposal->resource;

        return [
            'address' => (string) $proposal->address,
            'type' => $resource?->type->value ?? $proposal->address->type->value,
            'parent' => $resource?->parent === null ? null : (string) $resource->parent,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(
        array $payload,
        OutputInterface $output,
        ExitCode $code = ExitCode::SUCCESS,
    ): int {
        return $this->jsonOutput->write($payload, $output) ? $code->value : ExitCode::GENERAL_ERROR->value;
    }

    private function isStateResourceType(ResourceType $type): bool
    {
        return in_array($type, [
            ResourceType::APPLICATION,
            ResourceType::ENVIRONMENT,
            ResourceType::DATABASE_CLUSTER,
            ResourceType::DATABASE,
        ], true);
    }
}
