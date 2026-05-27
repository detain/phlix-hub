<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Console\Commands;

use Phlix\Hub\Common\Database\MigrationRunner;
use Phlix\Hub\Console\Commands\MigrateCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for {@see MigrateCommand}.
 *
 * The {@see MigrationRunner} is fully mocked, so no real database is
 * touched; each test wires a stubbed runner through the command's lazy
 * factory and asserts the rendered summary and exit code.
 *
 * @package Phlix\Hub\Tests\Unit\Console\Commands
 *
 * @covers \Phlix\Hub\Console\Commands\MigrateCommand
 */
final class MigrateCommandTest extends TestCase
{
    /**
     * Build a CommandTester around a MigrateCommand backed by the given runner.
     */
    private function tester(MigrationRunner $runner): CommandTester
    {
        $application = new Application();
        $application->add(new MigrateCommand(static fn(): MigrationRunner => $runner));

        return new CommandTester($application->find('migrate'));
    }

    public function testNoMigrationFilesExitsZeroWithNotice(): void
    {
        $runner = $this->createMock(MigrationRunner::class);
        $runner->method('discoverFiles')->willReturn([]);
        $runner->expects(self::never())->method('run');

        $tester = $this->tester($runner);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No SQL migrations found', $tester->getDisplay());
    }

    public function testAppliedMigrationsAreListedAndExitZero(): void
    {
        $runner = $this->createMock(MigrationRunner::class);
        $runner->method('discoverFiles')->willReturn([
            '/tmp/migrations/001.sql',
            '/tmp/migrations/002.sql',
        ]);
        $runner->method('run')->willReturn(['001.sql', '002.sql']);

        $tester = $this->tester($runner);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('Applied: 001.sql', $output);
        self::assertStringContainsString('Applied: 002.sql', $output);
        self::assertStringContainsString('Migrations complete (2 applied).', $output);
    }

    public function testAllAppliedExitsZeroWithNothingToDo(): void
    {
        $runner = $this->createMock(MigrationRunner::class);
        $runner->method('discoverFiles')->willReturn(['/tmp/migrations/001.sql']);
        $runner->method('run')->willReturn([]);

        $tester = $this->tester($runner);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('All migrations already applied', $tester->getDisplay());
    }

    public function testRunnerFailureExitsOneWithError(): void
    {
        $runner = $this->createMock(MigrationRunner::class);
        $runner->method('discoverFiles')->willReturn(['/tmp/migrations/001.sql']);
        $runner->method('run')->willThrowException(new RuntimeException('boom'));

        $tester = $this->tester($runner);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Migration failed: boom', $tester->getDisplay());
    }
}
