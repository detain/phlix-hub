<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Http\Controllers;

use Phlex\Hub\Auth\AuthManager;
use Phlex\Hub\Auth\JwtHandler;
use Phlex\Hub\Http\Controllers\MeController;
use Phlex\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MeController}.
 *
 * @package Phlex\Hub\Tests\unit\Http\Controllers
 * @since 0.2.0
 *
 * @covers \Phlex\Hub\Http\Controllers\MeController
 */
final class MeControllerTest extends TestCase
{
    public function testReturns401WhenUserIdMissing(): void
    {
        $mgr = $this->createMock(AuthManager::class);
        $controller = new MeController($mgr);

        $request = new Request();
        $response = $controller($request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testReturns404WhenUserNotFound(): void
    {
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('getCurrentUser')->willReturn(null);
        $controller = new MeController($mgr);

        $request = new Request();
        $request->userId = 'u-1';
        $response = $controller($request);

        self::assertSame(404, $response->statusCode);
    }

    public function testReturnsUserAndClaimsForKnownUser(): void
    {
        $jwt = new JwtHandler(str_repeat('a', 32));
        $token = $jwt->createAccessToken('u-2');
        $claims = $jwt->validateAccessToken($token);
        self::assertNotNull($claims);

        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('getCurrentUser')->willReturn(['id' => 'u-2', 'username' => 'alice']);
        $controller = new MeController($mgr);

        $request = new Request();
        $request->userId = 'u-2';
        $request->claims = $claims;

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"user"', $response->body);
        self::assertStringContainsString('"alice"', $response->body);
        self::assertStringContainsString('"claims"', $response->body);
        self::assertStringContainsString('"u-2"', $response->body);
    }

    public function testEmptyClaimsArrayWhenNotSet(): void
    {
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('getCurrentUser')->willReturn(['id' => 'u-3']);
        $controller = new MeController($mgr);

        $request = new Request();
        $request->userId = 'u-3';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }
}
