<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuthController}.
 *
 * The legacy form-driven SSR routes (`POST /signup|/login|/logout`) have been
 * retired with the Smarty UI; this suite covers only the JSON API surface
 * under `/api/v1/auth/*`.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\AuthController
 */
final class AuthControllerTest extends TestCase
{
    private const SECRET = 'this-secret-is-at-least-32-bytes-long!';

    private function controller(AuthManager $auth): AuthController
    {
        return new AuthController($auth);
    }

    private function authMgr(): AuthManager
    {
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn(new JwtHandler(self::SECRET));
        return $mgr;
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
    private function recordingLimiter(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            /** @var list<string> */
            public array $hits = [];

            public function hit(string $key): RateLimitState
            {
                $this->hits[] = $key;

                return new RateLimitState(1, 4, time() + 900, false, 5);
            }

            public function reset(string $key): void
            {
            }

            public function peek(string $key): RateLimitState
            {
                return new RateLimitState(0, 5, 0, false, 5);
            }
        };
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
        $mgr->method('jwt')->willReturn($jwt);
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
        $request->body = ['username' => 'alice', 'email' => 'a@example.com', 'password' => 'longenough'];

        $response = $controller($request);
        self::assertSame(201, $response->statusCode);
        self::assertStringContainsString('access_token', $response->body);
        self::assertStringContainsString('claims', $response->body);
    }

    public function testRegisterJsonAliasReturns201(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn($jwt);
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

    public function testSignupJsonReturns400OnInvalidInput(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('register')->willThrowException(new InvalidArgumentException('Password must be at least 8 characters'));

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/signup';
        $request->body = ['username' => 'a', 'email' => 'a@example.com', 'password' => 'x'];

        $response = $controller($request);
        self::assertSame(400, $response->statusCode);
    }

    public function testLoginJsonReturns200(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn($jwt);
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

    public function testLoginJsonReturns401(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('login')->willThrowException(new InvalidArgumentException('bad'));

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/login';
        $request->body = ['username' => 'a', 'password' => 'b'];

        $response = $controller($request);
        self::assertSame(401, $response->statusCode);
    }

    public function testLogoutJsonReturns204AndClearsCookies(): void
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
        self::assertCount(2, $response->cookies);
    }

    public function testRefreshJsonRequiresToken(): void
    {
        $mgr = $this->authMgr();

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/refresh';

        $response = $controller($request);
        self::assertSame(400, $response->statusCode);
    }

    public function testRefreshJsonUsesBodyToken(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn($jwt);
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

    public function testRefreshJsonFallsBackToCookie(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn($jwt);
        $mgr->expects(self::once())
            ->method('refresh')
            ->with('cookie-tok')
            ->willReturn([
                'access_token' => $jwt->createAccessToken('u-8'),
                'refresh_token' => $jwt->createRefreshToken('u-8'),
                'token_type' => 'Bearer', 'expires_in' => 3600,
                'user' => [], 'claims' => [],
            ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/refresh';
        $request->headers = ['COOKIE' => AuthMiddleware::COOKIE_REFRESH . '=cookie-tok'];

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }

    public function testRefreshJsonReturns401OnInvalidToken(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('refresh')->willThrowException(new InvalidArgumentException('bad'));

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/refresh';
        $request->body = ['refresh_token' => 'whatever'];

        $response = $controller($request);
        self::assertSame(401, $response->statusCode);
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
