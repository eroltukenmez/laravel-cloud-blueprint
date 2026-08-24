<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Yaml;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\Encoder\BlueprintEncoder;
use Symfony\Component\Yaml\Yaml;

final readonly class SymfonyBlueprintYamlEncoder implements BlueprintEncoder
{
    public function encode(Blueprint $blueprint): string
    {
        $environments = [];
        foreach ($blueprint->environments as $environment) {
            $environments[$environment->name] = ['branch' => $environment->branch];
        }

        $sections = [
            ['version' => $blueprint->schemaVersion->value],
            ['organization' => $blueprint->organization],
            ['application' => [
                'name' => $blueprint->application->name,
                'region' => $blueprint->application->region,
                'source' => [
                    'provider' => $blueprint->application->source->provider->value,
                    'repository' => $blueprint->application->source->repository,
                ],
            ]],
            ['environments' => $environments],
        ];

        return implode("\n\n", array_map(
            static fn (array $section): string => rtrim(Yaml::dump($section, 4, 2), "\n"),
            $sections,
        )) . "\n";
    }
}
