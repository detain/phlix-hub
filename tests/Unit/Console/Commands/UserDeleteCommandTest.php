<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Console\Commands;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Console\Commands\UserDeleteCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for {@see UserDeleteCommand}.
 *
 * The last-admin gate is proven absolute: it fires BEFORE the confirmation gate
 * and survives `--force`, so no combination can delete the sole administrator.
 * Deleting a non-admin never consults the admin count.
 *
 * @package Phlix\Hub\Tests\Unit\Console\Commands
 */
final class UserDeleteCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserDeleteCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:delete'));
    }

    public function testDeleteWithoutForceIsRefusedForConfirmation(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'alice',
            'is_admin' => 0,
        ]);
        // A non-admin target short-circuits the guard before any admin count.
        $repository->expects(self::never())->method('countAdmins');
        $repository->expects(self::never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('re-run with --force to confirm', $tester->getDisplay());
    }

    public function testForceDeletesNonAdminAndEmitsJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'alice',
            'is_admin' => 0,
        ]);
        $repository->expects(self::never())->method('countAdmins');
        $repository->expects(self::once())->method('delete')->with('u1');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--force' => true, '--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            '{"ok":true,"data":[{"id":"u1","username":"alice","deleted":true}]}',
            trim($tester->getDisplay()),
        );
    }

    public function testLastAdminRefusedEvenWithForceAndJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'root',
            'is_admin' => 1,
        ]);
        $repository->method('countAdmins')->willReturn(1);
        $repository->expects(self::never())->method('delete');

        $tester = $this->tester($repository);
        // --force is present yet MUST NOT bypass the guard.
        $exitCode = $tester->execute(['user' => 'root', '--force' => true, '--json' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            '{"ok":false,"error":"Cannot delete the last admin."}',
            trim($tester->getDisplay()),
        );
    }

    public function testLastAdminGuardPrecedesConfirmation(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'root',
            'is_admin' => 1,
        ]);
        $repository->method('countAdmins')->willReturn(1);
        $repository->expects(self::never())->method('delete');

        $tester = $this->tester($repository);
        // No --force: the guard must still be the reported reason, not confirmation.
        $exitCode = $tester->execute(['user' => 'root']);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Cannot delete the last admin', $display);
        self::assertStringNotContainsString('re-run with --force', $display);
    }

    public function testDeleteNonLastAdminWithForceProceeds(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'root',
            'is_admin' => 1,
        ]);
        $repository->method('countAdmins')->willReturn(2);
        $repository->expects(self::once())->method('delete')->with('u1');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'root', '--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Deleted user "root" (u1).', $tester->getDisplay());
    }

    public function testUserNotFoundExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(null);
        $repository->method('findByEmail')->willReturn(null);
        $repository->expects(self::never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'ghost', '--force' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('User not found: ghost', $tester->getDisplay());
    }
}
