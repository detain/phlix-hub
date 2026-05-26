<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Common\WebPortal\PageRenderer;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuthController}.
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
        $renderer = $this->createMock(PageRenderer::class);
        $renderer->method('render')->willReturn('<html>stub</html>');
        return new AuthController($auth, $renderer);
    }

    private function authMgr(): AuthManager
    {
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn(new JwtHandler(self::SECRET));
        return $mgr;
    }

    public function testSignupFormCreatesUserAndSetsCookies(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $accessToken = $jwt->createAccessToken('u-1');
        $refreshToken = $jwt->createRefreshToken('u-1');

        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn($jwt);
        $mgr->expects(self::once())
            ->method('register')
            ->with('alice', 'a@example.com', 'correct-horse-battery')
            ->willReturn([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type'    => 'Bearer',
                'expires_in'    => 3600,
                'user'          => ['id' => 'u-1', 'username' => 'alice'],
                'claims'        => [],
            ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/signup';
        $request->body = ['username' => 'alice', 'email' => 'a@example.com', 'password' => 'correct-horse-battery'];

        $response = $controller($request);
        self::assertSame(302, $response->statusCode);
        self::assertSame('/my-servers', $response->headers['Location']);
        self::assertCount(2, $response->cookies);
        self::assertSame(AuthMiddleware::COOKIE_ACCESS, $response->cookies[0]['name']);
        self::assertSame($accessToken, $response->cookies[0]['value']);
    }

    public function testSignupFormRendersErrorOnValidationFailure(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('register')->willThrowException(new InvalidArgumentException('Email already registered'));

        $renderer = $this->createMock(PageRenderer::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with('auth/signup.tpl', self::callback(static function ($vars): bool {
                return is_array($vars) && ($vars['error'] ?? null) === 'Email already registered';
            }))
            ->willReturn('<html>error</html>');

        $controller = new AuthController($mgr, $renderer);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/signup';
        $request->body = ['username' => 'a', 'email' => 'a@example.com', 'password' => 'short'];

        $response = $controller($request);
        self::assertSame(400, $response->statusCode);
    }

    public function testLoginFormSuccessSetsCookies(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $access = $jwt->createAccessToken('u-2');
        $refresh = $jwt->createRefreshToken('u-2');

        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn($jwt);
        $mgr->expects(self::once())
            ->method('login')
            ->with('alice', 'pwd', self::anything())
            ->willReturn([
                'access_token' => $access, 'refresh_token' => $refresh,
                'token_type' => 'Bearer', 'expires_in' => 3600,
                'user' => ['id' => 'u-2'], 'claims' => [],
            ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/login';
        $request->body = ['username' => 'alice', 'password' => 'pwd'];

        $response = $controller($request);
        self::assertSame(302, $response->statusCode);
        self::assertCount(2, $response->cookies);
    }

    public function testLoginFormFallsBackToEmailField(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('jwt')->willReturn($jwt);
        $mgr->expects(self::once())
            ->method('login')
            ->with('a@example.com', 'pwd', self::anything())
            ->willReturn([
                'access_token' => $jwt->createAccessToken('u-3'),
                'refresh_token' => $jwt->createRefreshToken('u-3'),
                'token_type' => 'Bearer', 'expires_in' => 3600,
                'user' => [], 'claims' => [],
            ]);

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/login';
        $request->body = ['email' => 'a@example.com', 'password' => 'pwd'];

        $response = $controller($request);
        self::assertSame(302, $response->statusCode);
    }

    public function testLoginFormReturns401OnBadCredentials(): void
    {
        $mgr = $this->authMgr();
        $mgr->method('login')->willThrowException(new InvalidArgumentException('Invalid'));

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/login';
        $request->body = ['username' => 'a', 'password' => 'wrong'];

        $response = $controller($request);
        self::assertSame(401, $response->statusCode);
    }

    public function testLogoutClearsCookies(): void
    {
        $mgr = $this->authMgr();
        $mgr->expects(self::once())->method('logout');

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/logout';
        $request->userId = 'u-1';

        $response = $controller($request);
        self::assertSame(302, $response->statusCode);
        self::assertSame('/', $response->headers['Location']);
        self::assertCount(2, $response->cookies);
        self::assertSame(0, $response->cookies[0]['max_age']);
        self::assertSame('', $response->cookies[0]['value']);
    }

    public function testLogoutSkipsAuthCallWithoutUserId(): void
    {
        $mgr = $this->authMgr();
        $mgr->expects(self::never())->method('logout');

        $controller = $this->controller($mgr);
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/logout';

        $response = $controller($request);
        self::assertSame(302, $response->statusCode);
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
