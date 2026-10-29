<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\CurrentPasswordInvalidException;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuthManager::changePassword()} (M-HUB-07).
 *
 * The contract under test is PROOF-FIRST: the current password is verified
 * before policy, before any write, and failed proofs drain the shared login
 * budget exactly like failed logins. The messages pinned here are the wire
 * text of the 400/401 frames — byte-stable.
 *
 * @package Phlix\Hub\Tests\Unit\Auth
 */
final class AuthManagerPasswordChangeTest extends TestCase
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

    public function testWrongCurrentPasswordThrowsTypedExceptionAndWritesNothing(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->expects(self::once())->method('verifyPassword')->with('u-1', 'wrong')->willReturn(false);
        $repo->expects(self::never())->method('update');
        $audit->expects(self::once())->method('logFailedAuth')
            ->with('password_change_current_invalid', ['user_id' => 'u-1']);

        $this->expectException(CurrentPasswordInvalidException::class);
        $this->expectExceptionMessage('Current password is incorrect');
        $mgr->changePassword('u-1', 'wrong', 'brand-new-secret', '203.0.113.7');
    }

    /**
     * No account oracle: an unknown user id fails with the exact same frame
     * text as a wrong password (verifyPassword() false collapses both).
     */
    public function testUnknownUserIdFailsIndistinguishablyFromWrongPassword(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->method('verifyPassword')->willReturn(false);

        try {
            $mgr->changePassword('u-ghost', 'whatever', 'brand-new-secret', '203.0.113.7');
            self::fail('Expected CurrentPasswordInvalidException');
        } catch (CurrentPasswordInvalidException $e) {
            self::assertSame('Current password is incorrect', $e->getMessage());
        }
        $repo->expects(self::never())->method('update');
    }

    public function testEmptyUserIdIsRefusedWithoutConsultingRepository(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->expects(self::never())->method('verifyPassword');
        $repo->expects(self::never())->method('update');

        $this->expectException(CurrentPasswordInvalidException::class);
        $mgr->changePassword('', 'whatever', 'brand-new-secret');
    }

    /**
     * The heart of the cure: policy runs AFTER the proof, so a caller who
     * holds a token but not the secret learns nothing about the policy and
     * can never reach the write. Both wrong at once → proof error wins.
     */
    public function testPolicyIsCheckedAfterTheProof(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->method('verifyPassword')->willReturn(false);

        try {
            $mgr->changePassword('u-1', 'wrong', 'short');
            self::fail('Expected CurrentPasswordInvalidException, not the policy error');
        } catch (CurrentPasswordInvalidException $e) {
            self::assertSame('Current password is incorrect', $e->getMessage());
        }
    }

    public function testTooShortNewPasswordKeepsTheRegistrationMessage(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->method('verifyPassword')->willReturn(true);
        $repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 8 characters');
        $mgr->changePassword('u-1', 'current-secret', 'short');
    }

    public function testTooLongNewPasswordKeepsTheRegistrationMessage(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->method('verifyPassword')->willReturn(true);
        $repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at most 4096 characters');
        $mgr->changePassword('u-1', 'current-secret', str_repeat('a', 4097));
    }

    public function testSuccessWritesThroughUpdateOnceForThatUserOnly(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->method('verifyPassword')->with('u-1', 'current-secret')->willReturn(true);
        // The write is id-scoped and password-keyed — the one shape that
        // makes UserRepository mint an Argon2ID hash. `once()` for this
        // exact user is the other-accounts-unaffected pin: no other id can
        // be touched by this call, and the method performs no other write.
        $repo->expects(self::once())->method('update')
            ->with('u-1', ['password' => 'brand-new-secret']);
        $audit->expects(self::once())->method('logAdminAction')
            ->with('u-1', 'user.password_changed', 'u-1', ['self_service' => true]);

        $mgr->changePassword('u-1', 'current-secret', 'brand-new-secret', '203.0.113.7');
    }

    public function testFailedProofsDrainTheSharedLoginBudget(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->method('verifyPassword')->willReturn(false);

        $ip = '203.0.113.9';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $mgr->changePassword('u-1', 'wrong', 'brand-new-secret', $ip);
                self::fail('Expected CurrentPasswordInvalidException on attempt ' . $attempt);
            } catch (CurrentPasswordInvalidException) {
                // expected — five failures = the login profile's maxAttempts
            }
        }

        // The 6th call must be refused BEFORE the proof is even attempted:
        // peek() runs first, so verifyPassword is never consulted again.
        $repo->expects(self::never())->method('verifyPassword');
        $this->expectException(RateLimitException::class);
        $mgr->changePassword('u-1', 'wrong', 'brand-new-secret', $ip);
    }

    /**
     * A successful change must NOT reset the per-IP login bucket — the
     * budget is a brute-force defence, not a courtesy. Discriminating:
     * 1 failure + 1 success + 4 failures = 5 consumed units; the next call
     * is refused by the pre-proof peek (RateLimitException) even though it
     * carries the CORRECT proof. Had the success cleared the bucket, only 4
     * units would count and the correct proof would instead succeed.
     */
    public function testSuccessDoesNotClearTheLoginBudget(): void
    {
        [$repo, $jwt, $audit, $logger, $rl] = $this->deps();
        $mgr = new AuthManager($repo, $jwt, $audit, $logger, $rl);

        $repo->method('verifyPassword')->willReturnCallback(
            static fn (string $id, string $pw): bool => $pw === 'current-secret'
        );

        $ip = '203.0.113.11';
        try {
            $mgr->changePassword('u-1', 'wrong', 'brand-new-secret', $ip);
        } catch (CurrentPasswordInvalidException) {
            // one consumed unit
        }
        $mgr->changePassword('u-1', 'current-secret', 'brand-new-secret', $ip);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $mgr->changePassword('u-1', 'wrong-' . $attempt, 'brand-new-secret', $ip);
            } catch (CurrentPasswordInvalidException) {
                // expected
            }
        }

        $this->expectException(RateLimitException::class);
        $mgr->changePassword('u-1', 'current-secret', 'brand-new-secret', $ip);
    }
}
