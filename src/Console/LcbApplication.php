<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console;

use Symfony\Component\Console\Application;

final class LcbApplication extends Application
{
    public const string NAME = 'Laravel Cloud Blueprint';
    public const string VERSION = '0.1.0-alpha.1';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
    }
}
