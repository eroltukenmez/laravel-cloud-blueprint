<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Console\Command\InitCommand;
use LaravelCloudBlueprint\Console\Command\CloudInspectCommand;
use LaravelCloudBlueprint\Console\Command\PlanCommand;
use LaravelCloudBlueprint\Console\Command\ApplyCommand;
use LaravelCloudBlueprint\Console\Command\ValidateCommand;
use LaravelCloudBlueprint\Console\Template\StarterBlueprintTemplate;
use LaravelCloudBlueprint\Infrastructure\File\NativeFileSystem;
use LaravelCloudBlueprint\Infrastructure\Environment\LcbTokenProvider;
use LaravelCloudBlueprint\Infrastructure\Environment\NativeEnvironmentValueProvider;
use LaravelCloudBlueprint\Infrastructure\Http\SymfonyLaravelCloudClientFactory;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Infrastructure\State\LocalFileStateStore;
use Symfony\Component\Console\Application;

final class LcbApplication extends Application
{
    public const string NAME = 'Laravel Cloud Blueprint';
    public const string VERSION = '0.1.0-alpha.1';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $files = new NativeFileSystem();
        $loader = new BlueprintLoader(
            new SymfonyYamlDecoder(),
            new BlueprintValidator(),
            new BlueprintNormalizer(),
        );
        $planner = new CreatePlan(new VariableValueResolver(new NativeEnvironmentValueProvider()));

        $this->add(new InitCommand($files, $files, new StarterBlueprintTemplate()));
        $this->add(new ValidateCommand($files, $loader));
        $this->add(new CloudInspectCommand(new LcbTokenProvider(), new SymfonyLaravelCloudClientFactory()));
        $this->add(new PlanCommand(
            $files,
            $loader,
            new LcbTokenProvider(),
            new SymfonyLaravelCloudClientFactory(),
            $planner,
        ));
        $this->add(new ApplyCommand(
            $files,
            $loader,
            new LcbTokenProvider(),
            new SymfonyLaravelCloudClientFactory(),
            $planner,
            new CreateOnlyApply(),
            new LocalFileStateStore(),
        ));
    }
}
