<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\RequestContext;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Context;

/**
 * Unit tests for {@see AuthMiddleware}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 *
 * @covers \Phlix\Hub\Http\Middleware\AuthMiddleware
 */
final class AuthMiddlewareTest extends TestCase
{
    private const SECRET = 'this-is-a-32-byte-or-larger-test-secret';

    /**
     * Reset the coroutine-local request context and the user-existence
     * cache between tests so neither leaks state into the next case.
     */
    protected function setUp(): void
    {
        Context::destroy();
        AuthMiddleware::resetCache();
    }

    public function testMissingTokenReturns401ForApiRoute(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $mw = new AuthMiddleware(new JwtHandler(self::SECRET), $repo);

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('"code"', $response->body);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testMissingTokenRedirectsForPageRoute(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $mw = new AuthMiddleware(new JwtHandler(self::SECRET), $repo);

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/my-servers';

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(302, $response->statusCode);
        self::assertSame('/app/login', $response->headers['Location'] ?? '');
    }

    public function testValidTokenPopulatesRequestUserAndClaims(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-7');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->with('u-7')->willReturn(true);
        $repo->method('findById')->with('u-7')->willReturn([
            'id' => 'u-7', 'username' => 'alice', 'password_hash' => 'secret',
        ]);

        $mw = new AuthMiddleware($jwt, $repo);
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = $token;

        $result = $mw($request);
        self::assertNull($result);
        self::assertSame('u-7', $request->userId);
        // HB-1.4: middleware only calls userExists() (lightweight) and sets
        // userId + claims. The full user row is loaded by downstream
        // controllers that need it via AuthManager::getCurrentUser().
        self::assertNotNull($request->claims);
        self::assertSame('u-7', $request->claims->sub);
    }

    public function testExpiredTokenReturns401(): void
    {
        // Use a handler with negative TTL so the issued token is already expired.
        $jwt = new JwtHandler(self::SECRET, 'phlix-hub', 'hub', -1, 1);
        $token = $jwt->createAccessToken('u-7');

        // Validation handler is the same one (so it doesn't reject by iss/aud).
        $repo = $this->createMock(UserRepository::class);
        $mw = new AuthMiddleware($jwt, $repo);

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = $token;

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
    }

    public function testInvalidTokenReturns401(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $mw = new AuthMiddleware(new JwtHandler(self::SECRET), $repo);

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = 'not-a-jwt';

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.invalid_token', $response->body);
    }

    public function testValidTokenButUnknownUserReturns401(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-missing');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->willReturn(false);

        $mw = new AuthMiddleware($jwt, $repo);
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = $token;

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.user_not_found', $response->body);
    }

    public function testCookieTokenIsAcceptedWhenBearerMissing(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-c');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->with('u-c')->willReturn(true);
        $repo->method('findById')->willReturn(['id' => 'u-c', 'username' => 'cookie']);

        $mw = new AuthMiddleware($jwt, $repo);
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/my-servers';
        $request->headers = ['COOKIE' => AuthMiddleware::COOKIE_ACCESS . '=' . $token . '; other=1'];

        $result = $mw($request);
        self::assertNull($result);
        self::assertSame('u-c', $request->userId);
    }

    /**
     * Step S3: a MUTATING `/api/v1` request authenticated ONLY by the
     * session cookie (no Authorization header) must be rejected — the API
     * surface is bearer-only for state-changing methods, closing the
     * cookie-based CSRF vector.
     */
    public function testMutatingApiRequestWithOnlyCookieIsRejected(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-api');

        $repo = $this->createMock(UserRepository::class);
        // findById must never be reached because the cookie is ignored.
        $repo->expects(self::never())->method('findById');

        $mw = new AuthMiddleware($jwt, $repo);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/me/shares';
        $request->headers = ['COOKIE' => AuthMiddleware::COOKIE_ACCESS . '=' . $token];

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    /**
     * Conversely, a MUTATING `/api/v1` request with a valid Bearer header
     * still authenticates (the header path is unaffected).
     */
    public function testMutatingApiRequestWithBearerStillAuthenticates(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-api2');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->with('u-api2')->willReturn(true);
        $repo->method('findById')->willReturn(['id' => 'u-api2', 'username' => 'api']);

        $mw = new AuthMiddleware($jwt, $repo);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/me/shares';
        $request->bearerToken = $token;

        self::assertNull($mw($request));
        self::assertSame('u-api2', $request->userId);
    }

    /**
     * A non-mutating (GET) `/api/v1` request authenticated by cookie is
     * still accepted — the cookie path stays valid for reads.
     */
    public function testGetApiRequestWithCookieIsStillAccepted(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-get');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->with('u-get')->willReturn(true);
        $repo->method('findById')->willReturn(['id' => 'u-get', 'username' => 'getter']);

        $mw = new AuthMiddleware($jwt, $repo);
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->headers = ['COOKIE' => AuthMiddleware::COOKIE_ACCESS . '=' . $token];

        self::assertNull($mw($request));
        self::assertSame('u-get', $request->userId);
    }

    /**
     * Build a fresh GET `/api/v1/me` request carrying the bearer token. A new
     * Request per call mirrors production (the middleware mutates
     * `$request->userId` / `$request->claims` per request).
     */
    private function authedRequest(string $token): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = $token;
        return $request;
    }

    /**
     * HB-1.4 [H-W6]: the authenticated hot path only needs `$request->userId`
     * from the already-validated JWT, so it MUST NOT load the full user row
     * (`findById`) — a lean `userExists` probe is all the gate runs.
     */
    public function testHotPathNeverLoadsFullUserRow(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-lean');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->with('u-lean')->willReturn(true);
        // The whole point of H-W6: the full row load is skipped on the hot path.
        $repo->expects(self::never())->method('findById');

        $mw = new AuthMiddleware($jwt, $repo);
        $request = $this->authedRequest($token);

        self::assertNull($mw($request));
        self::assertSame('u-lean', $request->userId);
    }

    /**
     * HB-1.4 [H-W6] query-count: N authenticated requests for the same user
     * within the TTL window hit the DB existence-check exactly ONCE — every
     * subsequent request is served from the short-TTL in-worker cache.
     */
    public function testExistenceProbeIsCachedWithinTtl(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-cache');

        $repo = $this->createMock(UserRepository::class);
        // Exactly ONE DB existence probe across all requests in the window.
        $repo->expects(self::once())->method('userExists')->with('u-cache')->willReturn(true);

        $mw = new AuthMiddleware($jwt, $repo);

        for ($i = 0; $i < 5; $i++) {
            $request = $this->authedRequest($token);
            self::assertNull($mw($request), "request #{$i} should authenticate");
            self::assertSame('u-cache', $request->userId);
        }
    }

    /**
     * HB-1.4 SECURITY REGRESSION GUARD (the negative-cache defect): a
     * deleted/revoked user (the existence probe returns false) MUST be rejected
     * with `auth.user_not_found` on EVERY request for the whole TTL window —
     * never silently re-admitted by a cache hit. Before the fix, request #2..N
     * within the 5 s TTL returned `true` unconditionally and bypassed the gate.
     * The probe is also run exactly once (the negative result is cached too).
     */
    public function testDeletedUserIsRejectedForWholeTtlAndProbedOnce(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-gone');

        $repo = $this->createMock(UserRepository::class);
        // Deleted user: the probe returns false, and thanks to the negative
        // cache it is queried only once across the whole window.
        $repo->expects(self::once())->method('userExists')->with('u-gone')->willReturn(false);

        $mw = new AuthMiddleware($jwt, $repo);

        for ($i = 0; $i < 5; $i++) {
            $request = $this->authedRequest($token);
            $response = $mw($request);
            self::assertNotNull($response, "request #{$i} must be rejected");
            self::assertSame(401, $response->statusCode, "request #{$i} must be 401");
            self::assertStringContainsString('auth.user_not_found', $response->body);
            self::assertNull($request->userId, "request #{$i} must not set a userId");
        }
    }

    /**
     * HB-1.4: after the TTL expires the gate RE-EVALUATES existence — so a user
     * who existed when first probed but has since been deleted is caught and
     * rejected on the next request past the window (near-instant revocation,
     * bounded by the short TTL). Time is advanced by back-dating the cache
     * entry via reflection (no blocking sleep on the event loop).
     */
    public function testExistenceReprobedAfterTtlAndCatchesDeletedUser(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-revoke');

        $repo = $this->createMock(UserRepository::class);
        // First probe: user exists → authorized. Second probe (post-TTL):
        // user is gone → rejected. Exactly two DB probes.
        $repo->expects(self::exactly(2))
            ->method('userExists')
            ->with('u-revoke')
            ->willReturnOnConsecutiveCalls(true, false);

        $mw = new AuthMiddleware($jwt, $repo);

        // Request #1: cached positive.
        $first = $this->authedRequest($token);
        self::assertNull($mw($first));
        self::assertSame('u-revoke', $first->userId);

        // Age the cache entry past the TTL so the next request re-probes.
        $prop = new ReflectionProperty(AuthMiddleware::class, 'userExistsCache');
        /** @var array<string, array{exists: bool, at: int}> $cache */
        $cache = $prop->getValue();
        self::assertArrayHasKey('u-revoke', $cache);
        $cache['u-revoke']['at'] -= 3600;
        $prop->setValue(null, $cache);

        // Request #2: stale → re-probe → now non-existent → rejected.
        $second = $this->authedRequest($token);
        $response = $mw($second);
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.user_not_found', $response->body);
        self::assertNull($second->userId);
    }

    public function testIsJsonRequestDetectsApiPrefix(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me';
        self::assertTrue(AuthMiddleware::isJsonRequest($request));
    }

    public function testIsJsonRequestDetectsAcceptHeader(): void
    {
        $request = new Request();
        $request->path = '/anything';
        $request->headers = ['ACCEPT' => 'application/json'];
        self::assertTrue(AuthMiddleware::isJsonRequest($request));
    }

    public function testIsJsonRequestFalseForHtml(): void
    {
        $request = new Request();
        $request->path = '/my-servers';
        $request->headers = ['ACCEPT' => 'text/html'];
        self::assertFalse(AuthMiddleware::isJsonRequest($request));
    }

    public function testClaimsForUserHelperReturnsClaims(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-h');

        $claims = AuthMiddleware::claimsForUser($jwt, $token);
        self::assertNotNull($claims);
        self::assertSame('u-h', $claims->sub);
    }

    /**
     * On a successful auth, the middleware publishes the authenticated
     * user-id into the coroutine-local request context (step 0.2c).
     * Downstream services read it via {@see RequestContext::getUserId()}
     * instead of relying on static/global state, which is unsafe under
     * the Workerman 5 + Swoole coroutine runtime.
     */
    public function testPublishesUserIdToRequestContextOnSuccessfulAuth(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-ctx');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->with('u-ctx')->willReturn(true);
        $repo->method('findById')->with('u-ctx')->willReturn([
            'id' => 'u-ctx', 'username' => 'ctx-user', 'password_hash' => 'secret',
        ]);

        $mw = new AuthMiddleware($jwt, $repo);

        self::assertNull(RequestContext::getUserId(), 'baseline: no user-id in context');

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = $token;

        $result = $mw($request);
        self::assertNull($result, 'middleware returns null to continue routing');
        self::assertSame('u-ctx', RequestContext::getUserId());
        self::assertTrue(RequestContext::hasUserId());
    }

    /**
     * Conversely, every rejected-auth path (missing token, invalid
     * token, unknown user) MUST NOT publish a user-id — otherwise a
     * rejected caller could leak an identity into a downstream service
     * that defensively reads the context.
     */
    public function testDoesNotPublishUserIdOnAnyRejectionPath(): void
    {
        $repo = $this->createMock(UserRepository::class);

        // (1) Missing token
        $mw = new AuthMiddleware(new JwtHandler(self::SECRET), $repo);
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        self::assertNotNull($mw($request));
        self::assertNull(RequestContext::getUserId(), 'no user-id on missing-token path');

        // (2) Invalid token
        Context::destroy();
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = 'not-a-jwt';
        self::assertNotNull($mw($request));
        self::assertNull(RequestContext::getUserId(), 'no user-id on invalid-token path');

        // (3) Valid token but unknown user
        Context::destroy();
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-missing');
        $repo->method('userExists')->willReturn(false);
        $mw2 = new AuthMiddleware($jwt, $repo);
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = $token;
        self::assertNotNull($mw2($request));
        self::assertNull(RequestContext::getUserId(), 'no user-id on unknown-user path');
    }
}
