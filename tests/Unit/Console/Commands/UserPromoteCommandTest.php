<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Console\Commands;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Console\Commands\UserPromoteCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for {@see UserPromoteCommand}.
 *
 * The last-admin guard is proven at the repository boundary: demotion of the
 * sole admin is refused (no write), promotion never consults the admin count,
 * and demotion proceeds only when another admin exists.
 *
 * @package Phlix\Hub\Tests\Unit\Console\Commands
 */
final class UserPromoteCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserPromoteCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:promote'));
    }

    public function testPromoteSetsAdminTrueAndNeverCountsAdmins(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'alice',
            'is_admin' => 0,
        ]);
        $repository->expects(self::never())->method('countAdmins');
        $repository->expects(self::once())->method('setAdmin')->with('u1', true);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('promoted to admin', $tester->getDisplay());
    }

    public function testPromoteJsonEmitsExactEnvelope(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'alice',
            'is_admin' => 0,
        ]);
        $repository->method('setAdmin');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            '{"ok":true,"data":[{"id":"u1","username":"alice","is_admin":true}]}',
            trim($tester->getDisplay()),
        );
    }

    public function testDemoteProceedsWhenAnotherAdminExists(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'alice',
            'is_admin' => 1,
        ]);
        $repository->method('countAdmins')->willReturn(2);
        $repository->expects(self::once())->method('setAdmin')->with('u1', false);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--revoke' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('demoted from admin', $tester->getDisplay());
    }

    public function testDemoteLastAdminIsRefusedWithJsonErrorAndNoWrite(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'u1',
            'username' => 'alice',
            'is_admin' => 1,
        ]);
        $repository->method('countAdmins')->willReturn(1);
        $repository->expects(self::never())->method('setAdmin');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--revoke' => true, '--json' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            '{"ok":false,"error":"Cannot demote the last admin."}',
            trim($tester->getDisplay()),
        );
    }

    public function testUserNotFoundExitsOneBothModes(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(null);
        $repository->method('findByEmail')->willReturn(null);
        $repository->expects(self::never())->method('setAdmin');

        $plain = $this->tester($repository);
        self::assertSame(Command::FAILURE, $plain->execute(['user' => 'ghost']));
        self::assertStringContainsString('User not found: ghost', $plain->getDisplay());

        $json = $this->tester($repository);
        self::assertSame(Command::FAILURE, $json->execute(['user' => 'ghost', '--json' => true]));
        self::assertStringContainsString('"ok":false', trim($json->getDisplay()));
    }
}
