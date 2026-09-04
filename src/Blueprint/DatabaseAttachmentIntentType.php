<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

enum DatabaseAttachmentIntentType: string
{
    case UNMANAGED = 'unmanaged';
    case ATTACHED = 'attached';
    case DETACHED = 'detached';
}
