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
        self::assertSame('/login', $response->headers['Location'] ?? '');
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
