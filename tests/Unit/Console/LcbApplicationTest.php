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
        self::assertSame('0.1.0-alpha.13', $application->getVersion());
    }

    public function testCommandDescriptionsClarifyReadOnlyLocalStateAndCloudMutationBoundaries(): void
    {
        $application = new LcbApplication();

        self::assertStringContainsString('without making changes', $application->find('plan')->getDescription());
        self::assertStringContainsString('without modifying Laravel Cloud or local State', $application->find('drift')->getDescription());
        self::assertStringContainsString('read-only', $application->find('cloud:inspect')->getDescription());
        self::assertStringContainsString('local state', $application->find('import')->getDescription());
        self::assertStringContainsString('without modifying Laravel Cloud', $application->find('import')->getDescription());
        self::assertStringContainsString('Release local LCB ownership', $application->find('state:unmanage')->getDescription());
        self::assertStringContainsString('without modifying Laravel Cloud', $application->find('state:unmanage')->getDescription());
        self::assertStringContainsString('may modify Laravel Cloud', $application->find('apply')->getDescription());
        self::assertStringContainsString('Read-only', $application->find('state:inspect')->getDescription());
    }

    public function testHelpClarifiesNonInteractiveApprovalAndReadOnlyCloudInit(): void
    {
        $application = new LcbApplication();

        self::assertStringContainsString(
            'without writing local state',
            $application->find('init')->getDefinition()->getOption('from-cloud')->getDescription(),
        );
        self::assertStringContainsString(
            'require --auto-approve',
            $application->find('apply')->getDefinition()->getOption('non-interactive')->getDescription(),
        );
        self::assertStringContainsString(
            'require --auto-approve',
            $application->find('import')->getDefinition()->getOption('non-interactive')->getDescription(),
        );
        self::assertStringContainsString(
            'requires --auto-approve',
            $application->find('state:unmanage')->getDefinition()->getOption('non-interactive')->getDescription(),
        );
    }
}
