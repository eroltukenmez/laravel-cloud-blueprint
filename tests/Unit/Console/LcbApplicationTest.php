<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console;

use LaravelCloudBlueprint\Console\LcbApplication;
use PHPUnit\Framework\TestCase;

final class LcbApplicationTest extends TestCase
{
    public function testItCanBeInstantiatedWithTheExpectedNameAndVersion(): void
    {
        $application = new LcbApplication();

        self::assertSame('Laravel Cloud Blueprint', $application->getName());
        self::assertSame('0.1.0-alpha.4', $application->getVersion());
    }
}
