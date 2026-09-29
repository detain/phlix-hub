<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Auth\SignupsDisabledException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\RecordingRateLimiter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuthController}.
 *
 * The legacy form-driven SSR routes (`POST /signup|/login|/logout`) have been
 * retired with the Smarty UI, and the dead session-cookie surface (read in
 * refresh, cleared on logout) was deleted with the auth audit — the JSON API
 * under `/api/v1/auth/*` is bearer/body-only.
 *
 * Every failure path is asserted against its registered contract code
 * (`auth.invalid_credentials`, `auth.missing_credentials`,
 * `auth.signups_disabled`, `auth.invalid_token`, `validation_failed`,
 * `rate_limited`) plus the legacy top-level `message` key the SPA still reads.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class AuthControllerTest extends TestCase
{
    private const SECRET = 'this-secret-is-at-least-32-bytes-long!';

    private function controller(AuthManager $auth): AuthController
    {
        return new AuthController($auth);
    }

    private function authMgr(): AuthManager&MockObject
    {
        return $this->createMock(AuthManager::class);
    }

    /**
     * Build a REAL {@see AuthManager} whose {@see UserRepository} always fails
     * the lookup (so every login records a failed attempt) backed by the given
     * REAL {@see RateLimiter}. Driving the login limiter to an actual trip —
     * rather than mocking the exception — is the HB-4.6g/h coverage the plan
     * asked for.
     */
    private function trippableAuthManager(RateLimiter $rl): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);

        return new AuthManager(
            $repo,
            new JwtHandler(self::SECRET),
            $this->createMock(AuditLogger::class),
            $this->createMock(StructuredLogger::class),
            $rl,
        );
    }

    public function testLoginJsonRateLimitedReturns429WithRetryAfter(): void
    {
        $rl = new RateLimiter(windowSeconds: 900, maxAttempts: 2, cap: 1000);
        $controller = $this->controller($this->trippableAuthManager($rl));

        $makeRequest = static function (): Request {
            $request = new Request();
            $request->method = 'POST';
            $request->path = '/api/v1/auth/login';
            $request->remoteIp = '203.0.113.9';
            $request->body = ['username' => 'nobody', 'password' => 'wrong'];
            return $request;
        };

        // Two bad-credential attempts (each mapped to a 401 JSON, no throw out).
        self::assertSame(401, $controller($makeRequest())->statusCode);
        self::assertSame(401, $controller($makeRequest())->statusCode);

        $response = $controller($makeRequest());

        self::assertSame(429, $response->statusCode);
        self::assertArrayHasKey('Retry-After', $response->headers);
        self::assertGreaterThan(0, (int) $response->headers['Retry-After']);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('rate_limited', $decoded['code']);
        self::assertSame('Too Many Requests', $decoded['error']);
    }

    /**
     * A recording {@see RateLimiterInterface} double: captures every key passed
     * to hit()/peek()/reset() so a test can assert which IP the login limiter
     * buckets on. Always reports not-limited so login proceeds to the (failing)
     * credential check that records the hit.
     */
    private function recordingLimiter(): RecordingRateLimiter
    {
        return new RecordingRateLimiter();
    }

    /**
     * Build a REAL {@see AuthManager} whose {@see UserRepository} always fails
     * the lookup, backed by the given limiter — so each login records one hit.
     */
    private function loginManagerWithLimiter(RateLimiterInterface $rl): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);

        return new AuthManager(
            $repo,
            new JwtHandler(self::SECRET),
            $this->createMock(AuditLogger::class),
            $this->createMock(StructuredLogger::class),
            $rl,
        );
    }

    /**
     * The hub trusted-proxy fix (mirrors SV-4.15): behind the shipped loopback
     * HAProxy front, the raw peer is `127.0.0.1` for EVERY login and the forged
     * leftmost X-Forwarded-For entry is client-controlled. The login limiter must
     * bucket on the REAL client (the rightmost appended hop) — NOT `0.0.0.0`, NOT
     * the loopback peer, and NOT the forged leftmost value.
     */
    public function testLoginKeysRateLimitOnTrustedClientIp(): void
    {
        $limiter = $this->recordingLimiter();
        $controller = $this->controller($this->loginManagerWithLimiter($limiter));

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/login';
        // Loopback proxy peer + forged leftmost XFF, real client appended rightmost.
        $request->remoteIp = '127.0.0.1';
        $request->headers = ['X-FORWARDED-FOR' => '198.51.100.66, 203.0.113.50'];
        $request->body = ['username' => 'nobody', 'password' => 'wrong'];

        $controller($request);

        self::assertSame(['auth:login:203.0.113.50'], $limiter->hits);
        self::assertNotContains('auth:login:0.0.0.0', $limiter->hits);
        self::assertNotContains('auth:login:127.0.0.1', $limiter->hits);
        self::assertNotContains('auth:login:198.51.100.66', $limiter->hits);
    }

    public function testSignupJsonReturns201(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('register')->willReturn([
            'access_token' => $jwt->createAccessToken('u-4'),
            'refresh_token' => $jwt->createRefreshToken('u-4'),
            'token_type' => 'Bearer', 'expires_in' => 3600,
            'user' => ['id' => 'u-4'], 'claims' => ['sub' => 'u-4'],
        ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/signup';
        $request->remoteIp = '203.0.113.7';
        $request->body = ['username' => 'alice', 'email' => 'a@example.com', 'password' => 'longenough'];

        $response = $controller($request);
        self::assertSame(201, $response->statusCode);
        self::assertStringContainsString('access_token', $response->body);
        self::assertStringContainsString('claims', $response->body);
    }

    /**
     * Signup must forward the TRUSTED client IP to the limiter key — the raw
     * loopback peer behind HAProxy would collapse every signup into one
     * bucket (same vector the login limiter fix closed).
     */
    public function testSignupPassesTrustedClientIpToRegister(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->expects(self::once())
            ->method('register')
            ->with('bob', 'b@example.com', 'longenough', '203.0.113.50')
            ->willReturn([
                'access_token' => $jwt->createAccessToken('u-9'),
                'refresh_token' => $jwt->createRefreshToken('u-9'),
                'token_type' => 'Bearer', 'expires_in' => 3600,
                'user' => ['id' => 'u-9'], 'claims' => ['sub' => 'u-9'],
            ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/register';
        $request->remoteIp = '127.0.0.1';
        $request->headers = ['X-FORWARDED-FOR' => '203.0.113.50'];
        $request->body = ['username' => 'bob', 'email' => 'b@example.com', 'password' => 'longenough'];

        self::assertSame(201, $controller($request)->statusCode);
    }

    public function testRegisterJsonAliasReturns201(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('register')->willReturn([
            'access_token' => $jwt->createAccessToken('u-9'),
            'refresh_token' => $jwt->createRefreshToken('u-9'),
            'token_type' => 'Bearer', 'expires_in' => 3600,
            'user' => ['id' => 'u-9'], 'claims' => ['sub' => 'u-9'],
        ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        // The shared @phlix/ui SPA posts signup to /register (not /signup).
        $request->path = '/api/v1/auth/register';
        $request->body = ['username' => 'bob', 'email' => 'b@example.com', 'password' => 'longenough'];

        $response = $controller($request);
        self::assertSame(201, $response->statusCode, '/api/v1/auth/register must alias signupJson');
        self::assertStringContainsString('access_token', $response->body);
    }

    public function testSignupJsonReturns400ValidationFailedOnInvalidInput(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('register')->willThrowException(
            new InvalidArgumentException('Password must be at least 8 characters')
        );

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/signup';
        $request->body = ['username' => 'a', 'email' => 'a@example.com', 'password' => 'x'];

        $response = $controller($request);
        self::assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('validation_failed', $decoded['code']);
        self::assertSame('Password must be at least 8 characters', $decoded['message']);
    }

    public function testSignupJsonReturns400MissingCredentialsWhenFieldAbsent(): void
    {
        $mgr = $this->authMgr();
        $mgr->expects(self::never())->method('register');

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/signup';
        $request->body = ['username' => 'alice', 'email' => 'a@example.com'];

        $response = $controller($request);
        self::assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('auth.missing_credentials', $decoded['code']);
    }

    public function testSignupJsonReturns403SignupsDisabled(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('register')->willThrowException(
            new SignupsDisabledException('Registration is disabled on this hub')
        );

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/register';
        $request->body = ['username' => 'alice', 'email' => 'a@example.com', 'password' => 'longenough'];

        $response = $controller($request);
        self::assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('auth.signups_disabled', $decoded['code']);
    }

    /**
     * Build a REAL AuthManager whose live-gate resolver answers `$disabled`
     * on top of the given boot flag, with a repo stubbed so an open gate
     * actually mints a session.
     */
    private function managerWithLiveGate(?bool $disabled, bool $bootEnabled = true): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
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

        return new AuthManager(
            $repo,
            new JwtHandler(self::SECRET),
            $this->createMock(AuditLogger::class),
            $this->createMock(StructuredLogger::class),
            new RateLimiter(windowSeconds: 900, maxAttempts: 9, cap: 100),
            null,
            null,
            null,
            $bootEnabled,
            null,
            static fn(): ?bool => $disabled,
        );
    }

    /**
     * End-to-end live toggle: the admin setting flipped ON (signups disabled)
     * answers the registered 403 frame through the real stack — boot flag
     * says open, the settings row wins.
     */
    public function testLiveSignupToggleClosedAnswers403ThroughRealManager(): void
    {
        $controller = $this->controller($this->managerWithLiveGate(true));
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/register';
        $request->remoteIp = '203.0.113.9';
        $request->body = ['username' => 'alice', 'email' => 'a@example.com', 'password' => 'longenough'];

        $response = $controller($request);
        self::assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('auth.signups_disabled', $decoded['code']);
        self::assertSame('Registration is disabled on this hub', $decoded['error']);
        self::assertSame('Registration is disabled on this hub', $decoded['message']);
    }

    /**
     * …and flipped OFF the same request registers (201) even though the
     * HUB_SIGNUPS_ENABLED boot flag said closed — live re-open, no restart.
     */
    public function testLiveSignupToggleOpenRegistersThroughRealManager(): void
    {
        // Boot flag closed (HUB_SIGNUPS_ENABLED=false shape) + live setting
        // open → the admin's live re-open wins; signup mints 201.
        $controller = $this->controller($this->managerWithLiveGate(false, bootEnabled: false));
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/signup';
        $request->remoteIp = '203.0.113.9';
        $request->body = ['username' => 'alice', 'email' => 'a@example.com', 'password' => 'longenough'];

        $response = $controller($request);
        self::assertSame(201, $response->statusCode);
        self::assertStringContainsString('access_token', $response->body);
    }

    public function testSignupJsonReturns429WhenSignupLimiterTrips(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('register')->willThrowException(
            new RateLimitException(resetAt: time() + 600, remaining: 0)
        );

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/register';
        $request->body = ['username' => 'alice', 'email' => 'a@example.com', 'password' => 'longenough'];

        $response = $controller($request);
        self::assertSame(429, $response->statusCode);
        self::assertArrayHasKey('Retry-After', $response->headers);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('rate_limited', $decoded['code']);
    }

    public function testLoginJsonReturns200(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('login')->willReturn([
            'access_token' => $jwt->createAccessToken('u-5'),
            'refresh_token' => $jwt->createRefreshToken('u-5'),
            'token_type' => 'Bearer', 'expires_in' => 3600,
            'user' => ['id' => 'u-5'], 'claims' => [],
        ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/login';
        $request->body = ['username' => 'a', 'password' => 'pwd'];

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }

    public function testLoginJsonReturns401InvalidCredentials(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('login')->willThrowException(new InvalidArgumentException('Invalid username or password'));

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/login';
        $request->body = ['username' => 'a', 'password' => 'b'];

        $response = $controller($request);
        self::assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('auth.invalid_credentials', $decoded['code']);
        // Legacy top-level message key stays for SPA compatibility.
        self::assertSame('Invalid username or password', $decoded['message']);
        self::assertSame('Invalid username or password', $decoded['error']);
    }

    public function testLoginJsonReturns400MissingCredentialsWhenPasswordAbsent(): void
    {
        $mgr = $this->authMgr();
        $mgr->expects(self::never())->method('login');

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/login';
        $request->body = ['username' => 'a'];

        $response = $controller($request);
        self::assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('auth.missing_credentials', $decoded['code']);
    }

    public function testLogoutJsonReturns204WithoutCookies(): void
    {
        $mgr = $this->authMgr();
        $mgr->expects(self::once())->method('logout');

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/logout';
        $request->userId = 'u-6';

        $response = $controller($request);
        self::assertSame(204, $response->statusCode);
        // The dead cookie-clearing surface is gone: bearer-only logout.
        self::assertCount(0, $response->cookies);
    }

    /**
     * Finding 5: logout with a presented refresh token forwards it so the
     * manager can actually revoke the lineage (jti registry).
     */
    public function testLogoutJsonForwardsRefreshTokenFromBody(): void
    {
        $mgr = $this->authMgr();
        $mgr->expects(self::once())
            ->method('logout')
            ->with('u-6', self::anything(), self::anything(), 'logout-tok');

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/logout';
        $request->userId = 'u-6';
        $request->body = ['refresh_token' => 'logout-tok'];

        self::assertSame(204, $controller($request)->statusCode);
    }

    public function testLogoutJsonWithNothingToRevokeStillReturns204(): void
    {
        $mgr = $this->authMgr();
        $mgr->expects(self::never())->method('logout');

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/logout';

        self::assertSame(204, $controller($request)->statusCode);
    }

    public function testRefreshJsonRequiresTokenWithMissingCredentialsCode(): void
    {
        $mgr = $this->authMgr();

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/refresh';

        $response = $controller($request);
        self::assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('auth.missing_credentials', $decoded['code']);
    }

    public function testRefreshJsonUsesBodyToken(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->expects(self::once())
            ->method('refresh')
            ->with('refresh-tok')
            ->willReturn([
                'access_token' => $jwt->createAccessToken('u-7'),
                'refresh_token' => $jwt->createRefreshToken('u-7'),
                'token_type' => 'Bearer', 'expires_in' => 3600,
                'user' => [], 'claims' => [],
            ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/refresh';
        $request->body = ['refresh_token' => 'refresh-tok'];

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }

    /**
     * The retired cookie fallback must NOT authenticate: a Cookie header
     * carrying the legacy refresh name behaves exactly like an empty body.
     */
    public function testRefreshJsonIgnoresLegacyCookie(): void
    {
        $mgr = $this->authMgr();
        $mgr->expects(self::never())->method('refresh');

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/refresh';
        $request->headers = ['COOKIE' => 'phlix_hub_refresh=cookie-tok'];

        self::assertSame(400, $controller($request)->statusCode);
    }

    public function testRefreshJsonReturns401InvalidToken(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('refresh')->willThrowException(new InvalidArgumentException('Invalid or expired refresh token'));

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/refresh';
        $request->body = ['refresh_token' => 'whatever'];

        $response = $controller($request);
        self::assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('auth.invalid_token', $decoded['code']);
        self::assertSame('Invalid or expired refresh token', $decoded['message']);
    }

    public function testInvokeUnknownPathReturns404(): void
    {
        $controller = $this->controller($this->authMgr());
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/some/other/path';

        $response = $controller($request);
        self::assertSame(404, $response->statusCode);
    }
}
