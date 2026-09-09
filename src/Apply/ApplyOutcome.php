<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

enum ApplyOutcome: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case UNCHANGED = 'unchanged';
    case DELETE_CONFIRMED = 'delete_confirmed';
    case ALREADY_ABSENT = 'already_absent';
    case REFUSED = 'refused';
    case CONFLICT = 'conflict';
    case UNCERTAIN = 'uncertain';
    case POSTCONDITION_FAILED = 'postcondition_failed';
    case STATE_CHECKPOINT_FAILED = 'state_checkpoint_failed';
    case FAILED = 'failed';

    public function isFailure(): bool
    {
        return match ($this) {
            self::CREATED,
            self::UPDATED,
            self::UNCHANGED,
            self::DELETE_CONFIRMED,
            self::ALREADY_ABSENT => false,
            self::REFUSED,
            self::CONFLICT,
            self::UNCERTAIN,
            self::POSTCONDITION_FAILED,
            self::STATE_CHECKPOINT_FAILED,
            self::FAILED => true,
        };
    }
}
