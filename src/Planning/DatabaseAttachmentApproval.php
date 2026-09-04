<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Blueprint\DatabaseAttachmentIntent;

final readonly class DatabaseAttachmentApproval
{
    public function __construct(
        public string $intent,
        public ?string $reference,
        public string $environmentIdentity,
        public string $applicationIdentity,
        public ?string $databaseIdentity,
        public ?string $clusterIdentity,
    ) {
    }

    public static function fromIdentities(
        DatabaseAttachmentIntent $intent,
        string $environmentId,
        string $applicationId,
        ?string $databaseId,
        ?string $clusterId,
    ): self {
        return new self(
            $intent->type->value,
            $intent->isAttached() ? (string) $intent->reference() : null,
            self::fingerprint($environmentId),
            self::fingerprint($applicationId),
            $databaseId === null ? null : self::fingerprint($databaseId),
            $clusterId === null ? null : self::fingerprint($clusterId),
        );
    }

    public function equals(self $other): bool
    {
        return $this->intent === $other->intent
            && $this->reference === $other->reference
            && hash_equals($this->environmentIdentity, $other->environmentIdentity)
            && hash_equals($this->applicationIdentity, $other->applicationIdentity)
            && $this->nullableIdentityEquals($this->databaseIdentity, $other->databaseIdentity)
            && $this->nullableIdentityEquals($this->clusterIdentity, $other->clusterIdentity);
    }

    private static function fingerprint(string $identity): string
    {
        return hash('sha256', "laravel-cloud-blueprint:database-attachment:\0" . $identity);
    }

    private function nullableIdentityEquals(?string $left, ?string $right): bool
    {
        return $left === null || $right === null ? $left === $right : hash_equals($left, $right);
    }
}
