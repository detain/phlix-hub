<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Console\Commands;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Console\Commands\UserCreateCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for {@see UserCreateCommand}.
 *
 * Covers field validation (INVALID), collision refusal (INVALID), repository
 * failure (FAILURE) and the success path — each in both human and `--json`
 * modes, asserting the exact envelope key order.
 *
 * @package Phlix\Hub\Tests\Unit\Console\Commands
 */
final class UserCreateCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserCreateCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:create'));
    }

    public function testCreatesAccountAndPrintsHumanConfirmation(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->willReturn(false);
        $repository->expects(self::once())->method('create')->willReturn('new-id');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'bob',
            '--email' => 'bob@example.com',
            '--password' => 'secret123',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Created user "bob" (new-id).', $tester->getDisplay());
    }

    public function testJsonSuccessEmitsExactEnvelope(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->willReturn(false);
        $repository->method('create')->willReturn('new-id');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'bob',
            '--email' => 'bob@example.com',
            '--password' => 'secret123',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            '{"ok":true,"data":[{"id":"new-id","username":"bob",'
            . '"email":"bob@example.com","display_name":"bob"}]}',
            trim($tester->getDisplay()),
        );
    }

    public function testDisplayNameOptionFlowsIntoEnvelope(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->willReturn(false);
        $repository->method('create')->willReturn('new-id');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'bob',
            '--email' => 'bob@example.com',
            '--password' => 'secret123',
            '--display-name' => 'Bobby',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"display_name":"Bobby"', trim($tester->getDisplay()));
    }

    public function testShortUsernameExitsInvalidBothModes(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::never())->method('create');

        $plain = $this->tester($repository);
        self::assertSame(
            Command::INVALID,
            $plain->execute(['username' => 'ab', '--email' => 'a@b.com', '--password' => 'x']),
        );
        self::assertStringContainsString('Username must be 3-50 characters', $plain->getDisplay());

        $json = $this->tester($repository);
        self::assertSame(
            Command::INVALID,
            $json->execute(['username' => 'ab', '--email' => 'a@b.com', '--password' => 'x', '--json' => true]),
        );
        self::assertStringContainsString('Username must be 3-50 characters', trim($json->getDisplay()));
    }

    public function testInvalidEmailExitsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'bob',
            '--email' => 'not-an-email',
            '--password' => 'secret123',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid email format', $tester->getDisplay());
    }

    public function testEmptyPasswordExitsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'bob',
            '--email' => 'bob@example.com',
            '--password' => '',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('A --password is required', $tester->getDisplay());
    }

    public function testUsernameCollisionExitsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(true);
        $repository->expects(self::never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'bob',
            '--email' => 'bob@example.com',
            '--password' => 'secret123',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Username already exists: bob', $tester->getDisplay());
    }

    public function testRepositoryFailureExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->willReturn(false);
        $repository->method('create')->willThrowException(new RuntimeException('db down'));

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'bob',
            '--email' => 'bob@example.com',
            '--password' => 'secret123',
            '--json' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('User creation failed: db down', trim($tester->getDisplay()));
    }
}
