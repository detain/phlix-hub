<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuthMiddleware}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 * @since 0.2.0
 *
 * @covers \Phlix\Hub\Http\Middleware\AuthMiddleware
 */
final class AuthMiddlewareTest extends TestCase
{
    private const SECRET = 'this-is-a-32-byte-or-larger-test-secret';

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
        self::assertIsArray($request->user);
        self::assertSame('alice', $request->user['username'] ?? null);
        self::assertArrayNotHasKey('password_hash', $request->user);
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
        $repo->method('findById')->willReturn(null);

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
}
