<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

enum DestructiveOutcome: string
{
    case DELETE_CONFIRMED = 'delete_confirmed';
    case ALREADY_ABSENT = 'already_absent';
    case REFUSED = 'refused';
    case CONFLICT = 'conflict';
    case UNCERTAIN = 'uncertain';
    case STATE_CHECKPOINT_FAILED = 'state_checkpoint_failed';
}
