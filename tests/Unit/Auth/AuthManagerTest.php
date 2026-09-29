<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Auth\RefreshTokenRevocationService;
use Phlix\Hub\Auth\SignupsDisabledException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Shared\Auth\JwtClaims;
use Phlix\Shared\Events\Auth\UserCreated;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

/**
 * Unit tests for {@see AuthManager}.
 *
 * @package Phlix\Hub\Tests\Unit\Auth
 */
final class AuthManagerTest extends TestCase
{
    private const SECRET = 'an-extra-long-test-secret-with-32-bytes-min';

    /**
     * @return array{
     *     0: UserRepository&\PHPUnit\Framework\MockObject\MockObject,
     *     1: JwtHandler,
     *     2: AuditLogger&\PHPUnit\Framework\MockObject\MockObject,
     *     3: StructuredLogger&\PHPUnit\Framework\MockObject\MockObject,
     *     4: RateLimiter
     * }
     */
    private function deps(): array
    {
        $repo = $this->createMock(UserRepository::class);
        $jwt = new JwtHandler(self::SECRET);
        $audit = $this->createMock(AuditLogger::class);
        $logger = $this->createMock(StructuredLogger::class);
        $rateLimiter = new RateLimiter(windowSeconds: 900, maxAttempts: 5, cap: 1000);
        return [$repo, $jwt, $audit, $logger, $rateLimiter];
    }

    public function testRegisterValidatesUsernameLength(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username must be 3-50 characters');
        $mgr->register('ab', 'a@example.com', 'longenough');
    }

    public function testRegisterValidatesEmail(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');
        $mgr->register('alice', 'not-an-email', 'longenough');
    }

    public function testRegisterValidatesPasswordLength(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 8 characters');
        $mgr->register('alice', 'a@example.com', 'short');
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('usernameExists')->willReturn(true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username already taken');
        $mgr->register('alice', 'a@example.com', 'longenough');
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email already registered');
        $mgr->register('alice', 'a@example.com', 'longenough');
    }

    public function testRegisterCreatesUserAndDispatchesUserCreatedEvent(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(2);
        $repo->method('create')->willReturn('u-new');
        $repo->method('findById')->willReturn([
            'id' => 'u-new',
            'username' => 'alice',
            'email' => 'a@example.com',
            'password_hash' => 'secret'
        ]);

        $audit->expects(self::once())->method('logSignup')->with('u-new', 'alice', 'a@example.com');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(UserCreated::class))
            ->willReturnArgument(0);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl, $dispatcher);
        $result = $mgr->register('alice', 'a@example.com', 'longenough');

        self::assertArrayHasKey('access_token', $result);
        self::assertArrayHasKey('refresh_token', $result);
        self::assertArrayHasKey('claims', $result);
        self::assertSame('u-new', $result['user']['id'] ?? null);
        self::assertArrayNotHasKey('password_hash', $result['user']);
    }

    public function testRegisterAutoPromotesFirstUserToAdmin(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(0);
        $repo->method('create')->willReturn('u-first');
        $repo->method('findById')->willReturn(['id' => 'u-first', 'username' => 'admin', 'password_hash' => 'h']);

        $repo->expects(self::once())
            ->method('setAdmin')
            ->with('u-first', true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        $mgr->register('admin', 'a@example.com', 'longenough');
    }

    public function testRegisterDoesNotPromoteSecondUser(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(1);
        $repo->method('create')->willReturn('u-second');
        $repo->method('findById')->willReturn(['id' => 'u-second', 'username' => 'bob']);

        $repo->expects(self::never())->method('setAdmin');

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        $mgr->register('bob', 'b@example.com', 'longenough');
    }

    public function testLoginValidatesPasswordAndReturnsTokens(): void
    {
        $hash = password_hash('correct-pw', PASSWORD_ARGON2ID);
        self::addToAssertionCount(1);

        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findByUsername')->willReturn(['id' => 'u-1', 'username' => 'alice']);
        $repo->method('findById')->willReturn(['id' => 'u-1', 'username' => 'alice', 'password_hash' => $hash]);
        $repo->method('verifyPassword')->with('u-1', 'correct-pw')->willReturn(true);

        $audit->expects(self::once())->method('logLogin')->with('u-1', 'device-7', true, null);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(UserLoggedIn::class))
            ->willReturnArgument(0);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl, $dispatcher);
        $result = $mgr->login('alice', 'correct-pw', '1.2.3.4', 'device-7');

        self::assertSame('Bearer', $result['token_type']);
        $claims = $jwt->validateAccessToken($result['access_token']);
        self::assertNotNull($claims);
        self::assertSame('u-1', $claims->sub);
    }

    public function testLoginFallsBackToEmailLookup(): void
    {
        $hash = password_hash('correct-pw', PASSWORD_ARGON2ID);
        self::addToAssertionCount(1);

        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(['id' => 'u-2', 'email' => 'a@example.com']);
        $repo->method('findById')->willReturn(['id' => 'u-2', 'email' => 'a@example.com']);
        $repo->method('verifyPassword')->willReturn(true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        $result = $mgr->login('a@example.com', 'correct-pw', '10.0.0.5', 'device-1');
        self::assertArrayHasKey('access_token', $result);
    }

    public function testLoginWithBadPasswordReturnsNullAndAudits(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findByUsername')->willReturn(['id' => 'u-1', 'username' => 'alice']);
        $repo->method('verifyPassword')->willReturn(false);

        $audit->expects(self::once())->method('logLogin')->with('u-1', 'device-1', false, 'bad_password');

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $this->expectException(InvalidArgumentException::class);
        $mgr->login('alice', 'wrong-pw', '10.0.0.6', 'device-1');
    }

    public function testLoginWithUnknownIdentifierAudits(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);

        $audit->expects(self::once())->method('logFailedAuth')->with('unknown_user', self::anything());

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $this->expectException(InvalidArgumentException::class);
        $mgr->login('ghost', 'any-pw', '10.0.0.7', 'device-1');
    }

    public function testRefreshIssuesNewAccessToken(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findById')->willReturn(['id' => 'u-3', 'username' => 'carol']);

        $refresh = $jwt->createRefreshToken('u-3');
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $result = $mgr->refresh($refresh);
        $claims = $jwt->validateAccessToken($result['access_token']);
        self::assertNotNull($claims);
        self::assertSame('u-3', $claims->sub);
    }

    public function testRefreshRejectsInvalidToken(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $this->expectException(InvalidArgumentException::class);
        $mgr->refresh('not-a-jwt');
    }

    public function testLogoutDispatchesUserLoggedOutEvent(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $audit->expects(self::once())->method('logLogout')->with('u-1', 'session-9');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(UserLoggedOut::class))
            ->willReturnArgument(0);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl, $dispatcher);
        $mgr->logout('u-1', 'session-9');
    }

    public function testGetCurrentUserStripsPasswordHash(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findById')->willReturn(['id' => 'u-1', 'username' => 'alice', 'password_hash' => 'secret']);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        $user = $mgr->getCurrentUser('u-1');

        self::assertNotNull($user);
        self::assertArrayNotHasKey('password_hash', $user);
        self::assertSame('alice', $user['username']);
    }

    public function testGetCurrentUserReturnsNullForUnknown(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findById')->willReturn(null);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        self::assertNull($mgr->getCurrentUser('nobody'));
    }

    public function testCreatedClaimsArePresentInResponse(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(7);
        $repo->method('create')->willReturn('u-x');
        $repo->method('findById')->willReturn(['id' => 'u-x', 'username' => 'x']);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        $result = $mgr->register('xanthe', 'x@example.com', 'longenough-pw');

        $claims = $result['claims'];
        self::assertSame(JwtClaims::ISS_PHLIX_HUB, $claims['iss']);
        self::assertSame(JwtClaims::AUD_HUB, $claims['aud']);
        self::assertSame('u-x', $claims['sub']);
        self::assertSame(JwtClaims::TYPE_ACCESS, $claims['type']);
    }

    /**
     * S1/B1: a single client IP is locked out after the configured number of
     * failed attempts within the window — the next attempt is rejected with a
     * {@see RateLimitException} BEFORE credentials are checked.
     */
    public function testLoginLocksOutSingleIpAfterFiveFailures(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findByUsername')->willReturn(['id' => 'u-1', 'username' => 'alice']);
        $repo->method('verifyPassword')->willReturn(false);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        // Five bad-password attempts from the same IP record five failures.
        for ($i = 0; $i < 5; $i++) {
            try {
                $mgr->login('alice', 'wrong-pw', '198.51.100.1', 'dev');
                self::fail('Bad password must throw.');
            } catch (InvalidArgumentException) {
                // expected — wrong credentials
            }
        }

        // The sixth attempt from the SAME IP is blocked by the limiter,
        // regardless of credentials.
        $this->expectException(RateLimitException::class);
        $mgr->login('alice', 'wrong-pw', '198.51.100.1', 'dev');
    }

    /**
     * S1: rate-limit buckets are keyed on the real client IP, so a second IP
     * is unaffected by another IP's exhausted bucket.
     */
    public function testDifferentIpsGetIndependentRateLimitBuckets(): void
    {
        $hash = password_hash('correct-pw', PASSWORD_ARGON2ID);
        self::addToAssertionCount(1);

        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findByUsername')->willReturn(['id' => 'u-1', 'username' => 'alice']);
        $repo->method('findById')->willReturn(['id' => 'u-1', 'username' => 'alice', 'password_hash' => $hash]);
        // Wrong password fails; correct password succeeds.
        $repo->method('verifyPassword')->willReturnCallback(
            static fn (string $id, string $pw): bool => $pw === 'correct-pw'
        );

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        // Exhaust IP-A's bucket with five failures.
        for ($i = 0; $i < 5; $i++) {
            try {
                $mgr->login('alice', 'wrong-pw', '203.0.113.10', 'dev-a');
                self::fail('Bad password must throw.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        // IP-A is now locked out.
        $lockedOut = false;
        try {
            $mgr->login('alice', 'wrong-pw', '203.0.113.10', 'dev-a');
        } catch (RateLimitException) {
            $lockedOut = true;
        } catch (InvalidArgumentException) {
            // not reached
        }
        self::assertTrue($lockedOut, 'IP-A must be locked out after five failures.');

        // A DIFFERENT IP has its own fresh bucket and can still log in.
        $result = $mgr->login('alice', 'correct-pw', '203.0.113.99', 'dev-b');
        self::assertArrayHasKey('access_token', $result);
    }

    /**
     * S1/B1: a successful login clears the failed-attempt bucket for that IP,
     * so the next attempt window starts clean.
     */
    public function testSuccessfulLoginResetsTheIpBucket(): void
    {
        $hash = password_hash('correct-pw', PASSWORD_ARGON2ID);
        self::addToAssertionCount(1);

        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findByUsername')->willReturn(['id' => 'u-1', 'username' => 'alice']);
        $repo->method('findById')->willReturn(['id' => 'u-1', 'username' => 'alice', 'password_hash' => $hash]);
        $repo->method('verifyPassword')->willReturnCallback(
            static fn (string $id, string $pw): bool => $pw === 'correct-pw'
        );

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        // Four failures (one short of the lockout threshold).
        for ($i = 0; $i < 4; $i++) {
            try {
                $mgr->login('alice', 'wrong-pw', '192.0.2.5', 'dev');
                self::fail('Bad password must throw.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        // A successful login resets the bucket for this IP.
        $ok = $mgr->login('alice', 'correct-pw', '192.0.2.5', 'dev');
        self::assertArrayHasKey('access_token', $ok);

        // The bucket is clean: five MORE failures are tolerated as
        // InvalidArgument (had the reset NOT happened, lockout would have
        // tripped far sooner — the pre-reset four plus these would exceed the
        // threshold long before the fifth here).
        for ($i = 0; $i < 5; $i++) {
            try {
                $mgr->login('alice', 'wrong-pw', '192.0.2.5', 'dev');
                self::fail('Bad password must throw.');
            } catch (InvalidArgumentException) {
                // expected — still InvalidArgument, NOT RateLimit
            }
        }

        // Confirm the bucket really was reset: only now (after a fresh five
        // failures) does lockout trip, proving the counter restarted at 0.
        $this->expectException(RateLimitException::class);
        $mgr->login('alice', 'wrong-pw', '192.0.2.5', 'dev');
    }

    // ------------------------------------------------------------------
    // Auth audit fixes (2026-09-28): regression guards.
    // ------------------------------------------------------------------

    public function testRegisterRejectsOverlongPassword(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 4096');
        $mgr->register('alice', 'a@example.com', str_repeat('x', 4097));
    }

    public function testRegisterThrowsSignupsDisabledBeforeAnyWork(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        // Nothing on the repo may be touched while signups are closed.
        $repo->expects(self::never())->method('usernameExists');
        $repo->expects(self::never())->method('create');

        $mgr = new AuthManager(
            $repo,
            $jwt,
            $audit,
            $logger,
            $rl,
            null,
            null,
            null,
            false,
        );

        $this->expectException(SignupsDisabledException::class);
        $mgr->register('alice', 'a@example.com', 'longenough-pw');
    }

    /**
     * Wire the manager with an explicit live-gate resolver, leaving every
     * other optional collaborator at its default.
     *
     * @param (callable(): ?bool)|null  $resolver
     * @param array{
     *     0: UserRepository&\PHPUnit\Framework\MockObject\MockObject,
     *     1: JwtHandler,
     *     2: AuditLogger&\PHPUnit\Framework\MockObject\MockObject,
     *     3: StructuredLogger&\PHPUnit\Framework\MockObject\MockObject,
     *     4: RateLimiter
     * } $deps
     */
    private function managerWithResolver(
        bool $bootEnabled,
        ?callable $resolver,
        array $deps,
    ): AuthManager {
        [$repo, $jwt, $audit, $logger, $rl] = $deps;
        return new AuthManager(
            $repo,
            $jwt,
            $audit,
            $logger,
            $rl,
            null,
            null,
            null,
            $bootEnabled,
            null,
            $resolver,
        );
    }

    /**
     * Stub the repo so a register() call would SUCCEED if the gate opens.
     */
    private function stubOpenSignupRepo(
        UserRepository&\PHPUnit\Framework\MockObject\MockObject $repo,
    ): void {
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(2);
        $repo->method('create')->willReturn('u-live');
        $repo->method('findById')->willReturn([
            'id'            => 'u-live',
            'username'      => 'live',
            'email'         => 'l@example.com',
            'password_hash' => 'secret',
        ]);
    }

    public function testLiveResolverClosesSignupsEvenWhenBootFlagIsOpen(): void
    {
        $deps = $this->deps();
        $deps[0]->expects(self::never())->method('usernameExists');
        $deps[0]->expects(self::never())->method('create');

        $mgr = $this->managerWithResolver(true, static fn(): bool => true, $deps);

        $this->expectException(SignupsDisabledException::class);
        $mgr->register('alice', 'a@example.com', 'longenough-pw');
    }

    public function testLiveResolverOpensSignupsEvenWhenBootFlagIsClosed(): void
    {
        $deps = $this->deps();
        $this->stubOpenSignupRepo($deps[0]);

        // The admin setting (resolver → false = not disabled) overrides the
        // closed HUB_SIGNUPS_ENABLED boot flag — live re-open, no restart.
        $mgr = $this->managerWithResolver(false, static fn(): bool => false, $deps);

        $result = $mgr->register('alice', 'a@example.com', 'longenough-pw');
        self::assertArrayHasKey('access_token', $result);
    }

    public function testResolverReturningNullDefersToBootFlag(): void
    {
        // Null = "the store cannot answer" (not booted, non-bool junk row).
        $deps = $this->deps();
        $this->stubOpenSignupRepo($deps[0]);
        $open = $this->managerWithResolver(true, static fn(): ?bool => null, $deps);
        self::assertArrayHasKey('access_token', $open->register('alice', 'a@example.com', 'longenough-pw'));

        $depsClosed = $this->deps();
        $depsClosed[0]->expects(self::never())->method('create');
        $closed = $this->managerWithResolver(false, static fn(): ?bool => null, $depsClosed);
        $this->expectException(SignupsDisabledException::class);
        $closed->register('alice', 'a@example.com', 'longenough-pw');
    }

    public function testThrowingResolverFailsSafeToBootFlag(): void
    {
        // A DB blowing up mid-lookup must NOT silently open (or close)
        // registration — the gate keeps whatever HUB_SIGNUPS_ENABLED said.
        $deps = $this->deps();
        $deps[0]->expects(self::never())->method('create');
        $mgr = $this->managerWithResolver(
            false,
            static fn(): bool => throw new RuntimeException('db is down'),
            $deps,
        );

        $this->expectException(SignupsDisabledException::class);
        $mgr->register('alice', 'a@example.com', 'longenough-pw');
    }

    public function testThrowingResolverDoesNotCloseWhenEnvSaysOpen(): void
    {
        $deps = $this->deps();
        $this->stubOpenSignupRepo($deps[0]);
        $mgr = $this->managerWithResolver(
            true,
            static fn(): bool => throw new RuntimeException('db is down'),
            $deps,
        );

        self::assertArrayHasKey('access_token', $mgr->register('alice', 'a@example.com', 'longenough-pw'));
    }

    public function testSignupRateLimiterCountsEveryAttemptAndTrips(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(5);
        $repo->method('create')->willReturn('u-s');
        $repo->method('findById')->willReturn(['id' => 'u-s', 'username' => 's']);

        // RateLimiter reports limited once count >= max, so with a budget of
        // 2 the SECOND attempt is already refused.
        $signupLimiter = new RateLimiter(windowSeconds: 3600, maxAttempts: 2, cap: 100);
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl, null, null, $signupLimiter);

        $mgr->register('alice', 'a@example.com', 'longenough-pw', '198.51.100.7');

        $this->expectException(RateLimitException::class);
        $mgr->register('bob', 'b@example.com', 'longenough-pw', '198.51.100.7');
    }

    public function testRefreshRejectsDeletedUser(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        // Token still verifies cryptographically, but the account is gone.
        $repo->method('findById')->willReturn(null);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired refresh token');
        $mgr->refresh($jwt->createRefreshToken('u-gone'));
    }

    public function testRefreshRejectsRevokedJti(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findById')->willReturn(['id' => 'u-r', 'username' => 'erin']);

        $claims = $jwt->validateRefreshToken($token = $jwt->createRefreshToken('u-r'));
        self::assertNotNull($claims);

        $revocations = $this->createMock(RefreshTokenRevocationService::class);
        $revocations->expects(self::once())
            ->method('isRevoked')
            ->with($claims->jti)
            ->willReturn(true);

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl, null, null, null, true, $revocations);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired refresh token');
        $mgr->refresh($token);
    }

    public function testLogoutWithRefreshTokenRevokesItsJtiAndAuditsSignedSubject(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $claims = $jwt->validateRefreshToken($token = $jwt->createRefreshToken('u-cut'));
        self::assertNotNull($claims);

        $revocations = $this->createMock(RefreshTokenRevocationService::class);
        $revocations->expects(self::once())
            ->method('revoke')
            ->with($claims->jti, 'u-cut', $claims->exp)
            ->willReturn(true);

        // The signed sub wins even though the caller passed an empty userId
        // (the logout route runs unauthenticated — see AuthController).
        $audit->expects(self::once())->method('logLogout')->with('u-cut', 'ip-1');

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl, null, null, null, true, $revocations);
        $mgr->logout('', 'ip-1', UserLoggedOut::REASON_EXPLICIT, $token);
    }

    public function testLogoutWithGarbageTokenIsFriendlyNoOp(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $revocations = $this->createMock(RefreshTokenRevocationService::class);
        $revocations->expects(self::never())->method('revoke');
        $audit->expects(self::once())->method('logLogout')->with('u-1', 'session-1');

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl, null, null, null, true, $revocations);
        $mgr->logout('u-1', 'session-1', UserLoggedOut::REASON_EXPLICIT, 'not-a-jwt');
    }

    public function testUnknownUserLoginStillPaysOnePasswordVerify(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);
        // The real verifyPassword must NOT run (no user row); the timing
        // equalisation happens against the dummy hash instead.
        $repo->expects(self::never())->method('verifyPassword');

        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);
        try {
            $mgr->login('ghost', 'some-password', '203.0.113.7');
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // expected
        }

        // Proof that the dummy-hash burn ran: the lazy property is populated.
        $prop = new \ReflectionProperty(AuthManager::class, 'dummyPasswordHash');
        self::assertIsString($prop->getValue($mgr), 'unknown-user path must burn one Argon2id verify');
    }
}
