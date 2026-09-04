<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class EnvironmentDefinition
{
    public DatabaseAttachmentIntent $database;

    public function __construct(
        public string $name,
        public string $branch,
        public VariableDefinitionCollection $variables,
        DatabaseAttachmentIntent|DatabaseReference|null $database = null,
    ) {
        $this->database = match (true) {
            $database instanceof DatabaseAttachmentIntent => $database,
            $database instanceof DatabaseReference => DatabaseAttachmentIntent::attached($database),
            default => DatabaseAttachmentIntent::unmanaged(),
        };
    }
}
