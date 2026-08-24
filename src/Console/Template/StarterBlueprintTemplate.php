<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console\Template;

final readonly class StarterBlueprintTemplate
{
    public function contents(): string
    {
        return <<<'YAML'
version: 1

organization: my-organization

application:
  name: my-api
  source:
    provider: github
    repository: acme/my-api

environments:
  production:
    branch: main

    variables:
      APP_ENV:
        value: production

      APP_DEBUG:
        value: "false"

      APP_KEY:
        from_env: APP_KEY
        sensitive: true
YAML . "\n";
    }
}
