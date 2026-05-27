<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Console\Commands;

use Phlix\Hub\Console\Commands\SmokeJwtCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for {@see SmokeJwtCommand}.
 *
 * The command performs a real, deterministic JWT mint → validate round-trip
 * with a throwaway test secret, so no mocking is required.
 *
 * @package Phlix\Hub\Tests\Unit\Console\Commands
 *
 * @covers \Phlix\Hub\Console\Commands\SmokeJwtCommand
 */
final class SmokeJwtCommandTest extends TestCase
{
    public function testRoundTripSucceedsAndExitsZero(): void
    {
        $application = new Application();
        $application->add(new SmokeJwtCommand());
        $tester = new CommandTester($application->find('smoke:jwt'));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('OK: JWT round-trip succeeded', $output);
        self::assertStringContainsString('iss=phlix-hub', $output);
        self::assertStringContainsString('aud=hub', $output);
    }
}
