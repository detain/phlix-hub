<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Console\Commands;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Console\Commands\UserListCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for {@see UserListCommand}.
 *
 * The {@see UserRepository} is fully mocked, so no database is touched. Each
 * test wires a stubbed repository through the command's lazy factory and asserts
 * the rendered table, the exact `--json` envelope (key order included), and the
 * exit code.
 *
 * @package Phlix\Hub\Tests\Unit\Console\Commands
 */
final class UserListCommandTest extends TestCase
{
    /**
     * Build a CommandTester around a UserListCommand backed by the repository.
     */
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserListCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:list'));
    }

    public function testListsAccountsAsHumanTable(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())
            ->method('findAll')
            ->willReturn([[
                'id' => 'user-1',
                'username' => 'alice',
                'email' => 'alice@example.com',
                'is_admin' => 1,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-02 00:00:00',
            ]]);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('alice', $display);
        self::assertStringContainsString('alice@example.com', $display);
        self::assertStringContainsString('ADMIN', $display);
    }

    public function testJsonSuccessEmitsExactEnvelopeWithKeyOrder(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willReturn([[
            'id' => '11111111-1111-1111-1111-111111111111',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'is_admin' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]]);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $expected = '{"ok":true,"data":[{"id":"11111111-1111-1111-1111-111111111111",'
            . '"username":"alice","email":"alice@example.com","is_admin":1,'
            . '"created_at":"2026-01-01 00:00:00","updated_at":"2026-01-02 00:00:00"}]}';
        self::assertSame($expected, trim($tester->getDisplay()));
    }

    public function testEmptyResultWithoutJsonPrintsNotice(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willReturn([]);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No users found.', $tester->getDisplay());
    }

    public function testEmptyResultWithJsonEmitsEmptyDataArray(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willReturn([]);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('{"ok":true,"data":[]}', trim($tester->getDisplay()));
    }

    public function testRepositoryFailureExitsOneWithJsonError(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willThrowException(new RuntimeException('db down'));

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            '{"ok":false,"error":"Failed to list users: db down"}',
            trim($tester->getDisplay()),
        );
    }

    public function testRepositoryFailureExitsOneWithHumanError(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willThrowException(new RuntimeException('db down'));

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Failed to list users: db down', $tester->getDisplay());
    }
}
