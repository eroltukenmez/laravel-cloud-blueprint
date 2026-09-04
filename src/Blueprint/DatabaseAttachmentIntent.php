<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

use LogicException;

final readonly class DatabaseAttachmentIntent
{
    public function __construct(
        public DatabaseAttachmentIntentType $type = DatabaseAttachmentIntentType::UNMANAGED,
        private ?DatabaseReference $databaseReference = null,
    ) {
        if ($type === DatabaseAttachmentIntentType::ATTACHED && $databaseReference === null) {
            throw new LogicException('An attached Database intent requires a reference.');
        }

        if ($type !== DatabaseAttachmentIntentType::ATTACHED && $databaseReference !== null) {
            throw new LogicException('Only an attached Database intent may contain a reference.');
        }
    }

    public static function unmanaged(): self
    {
        return new self(DatabaseAttachmentIntentType::UNMANAGED, null);
    }

    public static function attached(DatabaseReference $reference): self
    {
        return new self(DatabaseAttachmentIntentType::ATTACHED, $reference);
    }

    public static function detached(): self
    {
        return new self(DatabaseAttachmentIntentType::DETACHED, null);
    }

    public function reference(): DatabaseReference
    {
        if ($this->databaseReference === null) {
            throw new LogicException('Only an attached Database intent has a reference.');
        }

        return $this->databaseReference;
    }

    public function isUnmanaged(): bool { return $this->type === DatabaseAttachmentIntentType::UNMANAGED; }
    public function isAttached(): bool { return $this->type === DatabaseAttachmentIntentType::ATTACHED; }
    public function isDetached(): bool { return $this->type === DatabaseAttachmentIntentType::DETACHED; }
}
