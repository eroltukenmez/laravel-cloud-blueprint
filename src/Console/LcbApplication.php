<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Console\Command\InitCommand;
use LaravelCloudBlueprint\Console\Command\ValidateCommand;
use LaravelCloudBlueprint\Console\Template\StarterBlueprintTemplate;
use LaravelCloudBlueprint\Infrastructure\File\NativeFileSystem;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
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

        $this->add(new InitCommand($files, $files, new StarterBlueprintTemplate()));
        $this->add(new ValidateCommand($files, $loader));
    }
}
