<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

enum DatabaseAttachmentMutationOutcome
{
    case UPDATED_CONFIRMED;
    case ALREADY_RECONCILED;
    case REFUSED;
    case CONFLICT;
    case UNCERTAIN;
}
