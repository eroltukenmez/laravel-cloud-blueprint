<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Contract;

use LaravelCloudBlueprint\Apply\ApplyOutcome;
use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Console\Command\ApplyCommand;
use LaravelCloudBlueprint\Console\Command\CloudInspectCommand;
use LaravelCloudBlueprint\Console\Command\DriftCommand;
use LaravelCloudBlueprint\Console\Command\ImportCommand;
use LaravelCloudBlueprint\Console\Command\PlanCommand;
use LaravelCloudBlueprint\Console\Command\StateInspectCommand;
use LaravelCloudBlueprint\Console\Command\StateUnmanageCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\LcbApplication;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\PlanReconciliationStatus;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use LaravelCloudBlueprint\Infrastructure\State\LocalFileStateStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublicContractTest extends TestCase
{
    public function testFirstPartyCommandNamesArgumentsOptionsAndDefaultsAreTheFreezeCandidate(): void
    {
        $application = new LcbApplication();
        $expected = [
            'init' => [[], ['file', 'force', 'from-cloud', 'application', 'provider', 'non-interactive']],
            'validate' => [[], ['file']],
            'cloud:inspect' => [[], ['json']],
            'plan' => [[], ['file', 'json']],
            'drift' => [[], ['file', 'json', 'check']],
            'apply' => [[], ['file', 'auto-approve', 'non-interactive', 'json']],
            'import' => [[], ['file', 'auto-approve', 'non-interactive', 'json']],
            'state:inspect' => [[], ['cloud', 'file', 'json', 'check']],
            'state:unmanage' => [['address'], ['auto-approve', 'non-interactive', 'json']],
        ];

        foreach ($expected as $name => [$arguments, $options]) {
            $definition = $application->find($name)->getDefinition();
            self::assertSame($arguments, array_keys($definition->getArguments()), $name);
            self::assertSame($options, array_keys($definition->getOptions()), $name);
        }

        self::assertSame('cloud.blueprint.yaml', $application->find('init')->getDefinition()->getOption('file')->getDefault());
        self::assertSame('cloud.blueprint.yaml', $application->find('validate')->getDefinition()->getOption('file')->getDefault());
        self::assertSame('cloud.blueprint.yaml', $application->find('plan')->getDefinition()->getOption('file')->getDefault());
        self::assertSame('cloud.blueprint.yaml', $application->find('drift')->getDefinition()->getOption('file')->getDefault());
        self::assertSame('cloud.blueprint.yaml', $application->find('apply')->getDefinition()->getOption('file')->getDefault());
        self::assertSame('cloud.blueprint.yaml', $application->find('import')->getDefinition()->getOption('file')->getDefault());
        self::assertNull($application->find('state:inspect')->getDefinition()->getOption('file')->getDefault());
    }

    public function testJsonCommandsOwnContractVersionOne(): void
    {
        foreach ([
            CloudInspectCommand::class,
            PlanCommand::class,
            DriftCommand::class,
            ApplyCommand::class,
            ImportCommand::class,
            StateInspectCommand::class,
            StateUnmanageCommand::class,
        ] as $command) {
            $constant = (new ReflectionClass($command))->getReflectionConstant('JSON_CONTRACT_VERSION');
            self::assertNotFalse($constant, $command);
            self::assertSame(1, $constant->getValue(), $command);
        }
    }

    public function testExitCodesAndPlanTaxonomyAreFrozenCandidates(): void
    {
        self::assertSame([0, 1, 2, 3], array_map(static fn (ExitCode $code): int => $code->value, ExitCode::cases()));
        self::assertSame(
            ['create', 'update', 'delete', 'no_change', 'unsupported'],
            array_map(static fn (PlanOperation $value): string => $value->value, PlanOperation::cases()),
        );
        self::assertSame(
            ['supported', 'blocked', 'unsupported', 'not_applicable'],
            array_map(static fn (PlanReconciliationStatus $value): string => $value->value, PlanReconciliationStatus::cases()),
        );
    }

    public function testApplyAndDriftTaxonomiesAreFrozenCandidates(): void
    {
        self::assertSame(
            ['success', 'partial_failure', 'failed'],
            array_map(static fn (ApplyStatus $value): string => $value->value, ApplyStatus::cases()),
        );
        self::assertSame(
            ['created', 'updated', 'unchanged', 'delete_confirmed', 'already_absent', 'refused', 'conflict', 'uncertain', 'postcondition_failed', 'state_checkpoint_failed', 'failed'],
            array_map(static fn (ApplyOutcome $value): string => $value->value, ApplyOutcome::cases()),
        );
        self::assertSame(
            ['in_sync', 'desired_resource_missing', 'desired_resource_absent', 'configuration_difference', 'identity_missing', 'identity_replacement', 'identity_conflict', 'lifecycle_condition', 'unknown'],
            array_map(static fn (ObservationKind $value): string => $value->value, ObservationKind::cases()),
        );
        self::assertSame(
            ['managed', 'derived', 'unmanaged', 'conflict', 'none', 'unknown'],
            array_map(static fn (OwnershipStatus $value): string => $value->value, OwnershipStatus::cases()),
        );
        self::assertSame(
            ['supported', 'unsupported', 'blocked', 'not_applicable'],
            array_map(static fn (ReconciliationStatus $value): string => $value->value, ReconciliationStatus::cases()),
        );
        self::assertSame(
            ['complete', 'incomplete'],
            array_map(static fn (EvidenceStatus $value): string => $value->value, EvidenceStatus::cases()),
        );
    }

    public function testBlueprintOneAndStateV1ToV2CompatibilityRemainIntact(): void
    {
        $validator = new BlueprintValidator();
        $blueprint = [
            'version' => 1,
            'organization' => 'acme',
            'application' => [
                'name' => 'api',
                'region' => 'eu-central-1',
                'source' => ['provider' => 'github', 'repository' => 'acme/api'],
            ],
            'environments' => [],
        ];
        self::assertTrue($validator->validate($blueprint)->isValid());
        self::assertFalse($validator->validate([...$blueprint, 'version' => 2])->isValid());
        self::assertSame(StateVersion::V2, StateVersion::CURRENT);
        self::assertSame(['managed', 'derived'], array_map(
            static fn (StateOwnershipClassification $value): string => $value->value,
            StateOwnershipClassification::cases(),
        ));
        self::assertSame(['cluster_create_response'], array_map(
            static fn (StateProvenance $value): string => $value->value,
            StateProvenance::cases(),
        ));

        $directory = sys_get_temp_dir() . '/lcb-public-contract-' . bin2hex(random_bytes(8));
        $path = $directory . '/state.json';
        self::assertTrue(mkdir($directory));
        self::assertNotFalse(file_put_contents($path, '{"version":1,"serial":4,"organization":"acme","resources":{}}'));

        try {
            $store = new LocalFileStateStore($path);
            $loaded = $store->loadWithMetadata();
            self::assertSame(StateVersion::V1, $loaded->sourceVersion);
            self::assertSame(StateVersion::V2, $loaded->document->version);
            self::assertSame(4, $loaded->document->serial);

            $saved = $store->save($loaded->document->withResource(new StateResource(
                new ResourceAddress(ResourceType::APPLICATION, 'api'),
                ResourceType::APPLICATION,
                'app-id',
            )));
            self::assertSame(StateVersion::V2, $saved->version);
            self::assertSame(5, $saved->serial);
            $persisted = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($persisted);
            self::assertSame(2, $persisted['version']);
        } finally {
            foreach ([$path, $path . '.lock'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
}
