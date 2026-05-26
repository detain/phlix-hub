<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\MeController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MeController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\MeController
 */
final class MeControllerTest extends TestCase
{
    private function controller(AuthManager $auth, ?ServerInfoHandler $serverInfo = null): MeController
    {
        return new MeController($auth, $serverInfo ?? $this->createMock(ServerInfoHandler::class));
    }

    public function testReturns401WhenUserIdMissing(): void
    {
        $mgr = $this->createMock(AuthManager::class);
        $controller = $this->controller($mgr);

        $request = new Request();
        $response = $controller($request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testReturns404WhenUserNotFound(): void
    {
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('getCurrentUser')->willReturn(null);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $controller = $this->controller($mgr, $serverInfo);

        $request = new Request();
        $request->userId = 'u-1';
        $response = $controller($request);

        self::assertSame(404, $response->statusCode);
    }

    public function testReturnsUserAndClaimsAndServersForKnownUser(): void
    {
        $jwt = new JwtHandler(str_repeat('a', 32));
        $token = $jwt->createAccessToken('u-2');
        $claims = $jwt->validateAccessToken($token);
        self::assertNotNull($claims);

        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('getCurrentUser')->willReturn(['id' => 'u-2', 'username' => 'alice']);

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServersForUser')->willReturn([]);

        $controller = $this->controller($mgr, $serverInfo);

        $request = new Request();
        $request->userId = 'u-2';
        $request->claims = $claims;

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"user"', $response->body);
        self::assertStringContainsString('"alice"', $response->body);
        self::assertStringContainsString('"claims"', $response->body);
        self::assertStringContainsString('"servers"', $response->body);
        self::assertStringContainsString('"u-2"', $response->body);
    }

    public function testIncludesServersInResponse(): void
    {
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('getCurrentUser')->willReturn(['id' => 'u-3', 'username' => 'bob']);

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServersForUser')
            ->with('u-3')
            ->willReturn([]);

        $controller = $this->controller($mgr, $serverInfo);

        $request = new Request();
        $request->userId = 'u-3';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"servers"', $response->body);
    }

    public function testEmptyClaimsArrayWhenNotSet(): void
    {
        $mgr = $this->createMock(AuthManager::class);
        $mgr->method('getCurrentUser')->willReturn(['id' => 'u-4']);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServersForUser')->willReturn([]);
        $controller = $this->controller($mgr, $serverInfo);

        $request = new Request();
        $request->userId = 'u-4';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }
}
