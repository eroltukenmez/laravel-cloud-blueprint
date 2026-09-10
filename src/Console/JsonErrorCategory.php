<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console;

enum JsonErrorCategory: string
{
    case INPUT = 'input';
    case BLUEPRINT = 'blueprint';
    case STATE = 'state';
    case AUTHENTICATION = 'authentication';
    case CLOUD = 'cloud';
    case MUTATION = 'mutation';
    case FILESYSTEM = 'filesystem';
    case OUTPUT = 'output';
}
