<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Shared\Auth\JwtClaims;
use Phlix\Shared\Events\Auth\UserCreated;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for {@see AuthManager}.
 *
 * @package Phlix\Hub\Tests\Unit\Auth
 * @since 0.2.0
 *
 * @covers \Phlix\Hub\Auth\AuthManager
 */
final class AuthManagerTest extends TestCase
{
    private const SECRET = 'an-extra-long-test-secret-with-32-bytes-min';

    /**
     * @return array{0: UserRepository&\PHPUnit\Framework\MockObject\MockObject, 1: JwtHandler, 2: AuditLogger&\PHPUnit\Framework\MockObject\MockObject, 3: StructuredLogger&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function deps(): array
    {
        $repo = $this->createMock(UserRepository::class);
        $jwt = new JwtHandler(self::SECRET);
        $audit = $this->createMock(AuditLogger::class);
        $logger = $this->createMock(StructuredLogger::class);
        return [$repo, $jwt, $audit, $logger];
    }

    public function testRegisterValidatesUsernameLength(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username must be 3-50 characters');
        $mgr->register('ab', 'a@example.com', 'longenough');
    }

    public function testRegisterValidatesEmail(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');
        $mgr->register('alice', 'not-an-email', 'longenough');
    }

    public function testRegisterValidatesPasswordLength(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 8 characters');
        $mgr->register('alice', 'a@example.com', 'short');
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('usernameExists')->willReturn(true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username already taken');
        $mgr->register('alice', 'a@example.com', 'longenough');
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email already registered');
        $mgr->register('alice', 'a@example.com', 'longenough');
    }

    public function testRegisterCreatesUserAndDispatchesUserCreatedEvent(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(2);
        $repo->method('create')->willReturn('u-new');
        $repo->method('findById')->willReturn(['id' => 'u-new', 'username' => 'alice', 'email' => 'a@example.com', 'password_hash' => 'secret']);

        $audit->expects(self::once())->method('logSignup')->with('u-new', 'alice', 'a@example.com');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(UserCreated::class))
            ->willReturnArgument(0);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $dispatcher);
        $result = $mgr->register('alice', 'a@example.com', 'longenough');

        self::assertArrayHasKey('access_token', $result);
        self::assertArrayHasKey('refresh_token', $result);
        self::assertArrayHasKey('claims', $result);
        self::assertSame('u-new', $result['user']['id'] ?? null);
        self::assertArrayNotHasKey('password_hash', $result['user']);
    }

    public function testRegisterAutoPromotesFirstUserToAdmin(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0);
        $repo->method('create')->willReturn('u-first');
        $repo->method('findById')->willReturn(['id' => 'u-first', 'username' => 'admin', 'password_hash' => 'h']);

        $repo->expects(self::once())
            ->method('setAdmin')
            ->with('u-first', true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        $mgr->register('admin', 'a@example.com', 'longenough');
    }

    public function testRegisterDoesNotPromoteSecondUser(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(1);
        $repo->method('create')->willReturn('u-second');
        $repo->method('findById')->willReturn(['id' => 'u-second', 'username' => 'bob']);

        $repo->expects(self::never())->method('setAdmin');

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        $mgr->register('bob', 'b@example.com', 'longenough');
    }

    public function testLoginValidatesPasswordAndReturnsTokens(): void
    {
        $hash = password_hash('correct-pw', PASSWORD_ARGON2ID);
        self::assertIsString($hash);

        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('findByUsername')->willReturn(['id' => 'u-1', 'username' => 'alice']);
        $repo->method('findById')->willReturn(['id' => 'u-1', 'username' => 'alice', 'password_hash' => $hash]);
        $repo->method('verifyPassword')->with('u-1', 'correct-pw')->willReturn(true);

        $audit->expects(self::once())->method('logLogin')->with('u-1', '1.2.3.4', true, null);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(UserLoggedIn::class))
            ->willReturnArgument(0);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $dispatcher);
        $result = $mgr->login('alice', 'correct-pw', '1.2.3.4');

        self::assertSame('Bearer', $result['token_type']);
        $claims = $jwt->validateAccessToken($result['access_token']);
        self::assertNotNull($claims);
        self::assertSame('u-1', $claims->sub);
    }

    public function testLoginFallsBackToEmailLookup(): void
    {
        $hash = password_hash('correct-pw', PASSWORD_ARGON2ID);
        self::assertIsString($hash);

        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(['id' => 'u-2', 'email' => 'a@example.com']);
        $repo->method('findById')->willReturn(['id' => 'u-2', 'email' => 'a@example.com']);
        $repo->method('verifyPassword')->willReturn(true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        $result = $mgr->login('a@example.com', 'correct-pw', 'device-1');
        self::assertArrayHasKey('access_token', $result);
    }

    public function testLoginWithBadPasswordReturnsNullAndAudits(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('findByUsername')->willReturn(['id' => 'u-1', 'username' => 'alice']);
        $repo->method('verifyPassword')->willReturn(false);

        $audit->expects(self::once())->method('logLogin')->with('u-1', 'device-1', false, 'bad_password');

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);

        $this->expectException(InvalidArgumentException::class);
        $mgr->login('alice', 'wrong-pw', 'device-1');
    }

    public function testLoginWithUnknownIdentifierAudits(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);

        $audit->expects(self::once())->method('logFailedAuth')->with('unknown_user', self::anything());

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);

        $this->expectException(InvalidArgumentException::class);
        $mgr->login('ghost', 'any-pw', 'device-1');
    }

    public function testRefreshIssuesNewAccessToken(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('findById')->willReturn(['id' => 'u-3', 'username' => 'carol']);

        $refresh = $jwt->createRefreshToken('u-3');
        $mgr = new AuthManager($repo, $jwt, $audit, $logger);

        $result = $mgr->refresh($refresh);
        $claims = $jwt->validateAccessToken($result['access_token']);
        self::assertNotNull($claims);
        self::assertSame('u-3', $claims->sub);
    }

    public function testRefreshRejectsInvalidToken(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger);

        $this->expectException(InvalidArgumentException::class);
        $mgr->refresh('not-a-jwt');
    }

    public function testLogoutDispatchesUserLoggedOutEvent(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $audit->expects(self::once())->method('logLogout')->with('u-1', 'session-9');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(UserLoggedOut::class))
            ->willReturnArgument(0);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $dispatcher);
        $mgr->logout('u-1', 'session-9');
    }

    public function testGetCurrentUserStripsPasswordHash(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('findById')->willReturn(['id' => 'u-1', 'username' => 'alice', 'password_hash' => 'secret']);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        $user = $mgr->getCurrentUser('u-1');

        self::assertNotNull($user);
        self::assertArrayNotHasKey('password_hash', $user);
        self::assertSame('alice', $user['username']);
    }

    public function testGetCurrentUserReturnsNullForUnknown(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('findById')->willReturn(null);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        self::assertNull($mgr->getCurrentUser('nobody'));
    }

    public function testJwtAccessorReturnsHandler(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        self::assertSame($jwt, $mgr->jwt());
    }

    public function testCreatedClaimsArePresentInResponse(): void
    {
        [$repo, $jwt, $audit, $logger] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(7);
        $repo->method('create')->willReturn('u-x');
        $repo->method('findById')->willReturn(['id' => 'u-x', 'username' => 'x']);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger);
        $result = $mgr->register('xanthe', 'x@example.com', 'longenough-pw');

        $claims = $result['claims'];
        self::assertSame(JwtClaims::ISS_PHLIX_HUB, $claims['iss']);
        self::assertSame(JwtClaims::AUD_HUB, $claims['aud']);
        self::assertSame('u-x', $claims['sub']);
        self::assertSame(JwtClaims::TYPE_ACCESS, $claims['type']);
    }
}
