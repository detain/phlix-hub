<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\unit\Http\Controllers;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Common\WebPortal\PageRenderer;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\PageController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PageController}.
 *
 * @package Phlix\Hub\Tests\unit\Http\Controllers
 * @since 0.2.0
 *
 * @covers \Phlix\Hub\Http\Controllers\PageController
 */
final class PageControllerTest extends TestCase
{
    private function controller(string $expectedTemplate, array $expectedVars = null): PageController
    {
        $renderer = $this->createMock(PageRenderer::class);
        if ($expectedTemplate !== '') {
            $renderer->expects(self::once())
                ->method('render')
                ->with($expectedTemplate, $expectedVars ?? self::anything())
                ->willReturn('<html>rendered</html>');
        }
        $auth = $this->createMock(AuthManager::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 'u-1', 'username' => 'alice']);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServersForUser')->willReturn([]);
        return new PageController($renderer, $auth, $serverInfo);
    }

    public function testHomeRendersIndexTemplate(): void
    {
        $controller = $this->controller('home/index.tpl');
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
        self::assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testHomePassesAuthenticatedFlag(): void
    {
        $renderer = $this->createMock(PageRenderer::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with('home/index.tpl', self::callback(static function ($vars): bool {
                return is_array($vars) && ($vars['is_authenticated'] ?? null) === true;
            }))
            ->willReturn('<html></html>');

        $auth = $this->createMock(AuthManager::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $controller = new PageController($renderer, $auth, $serverInfo);

        $request = new Request();
        $request->path = '/';
        $request->userId = 'u-1';

        $controller($request);
    }

    public function testSignupRendersAuthSignupTemplate(): void
    {
        $controller = $this->controller('auth/signup.tpl');
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/signup';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }

    public function testLoginRendersAuthLoginTemplate(): void
    {
        $controller = $this->controller('auth/login.tpl');
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/login';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }

    public function testMyServersRendersDashboardTemplate(): void
    {
        $renderer = $this->createMock(PageRenderer::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with('home/my-servers.tpl', self::callback(static function ($vars): bool {
                return is_array($vars) && isset($vars['user']) && isset($vars['servers']);
            }))
            ->willReturn('<html>dashboard</html>');

        $auth = $this->createMock(AuthManager::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 'u-1']);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServersForUser')->willReturn([]);
        $controller = new PageController($renderer, $auth, $serverInfo);

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/my-servers';
        $request->userId = 'u-1';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }

    public function testUnknownPathReturns404(): void
    {
        $renderer = $this->createMock(PageRenderer::class);
        $renderer->expects(self::never())->method('render');

        $auth = $this->createMock(AuthManager::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $controller = new PageController($renderer, $auth, $serverInfo);

        $request = new Request();
        $request->path = '/nope';

        $response = $controller($request);
        self::assertSame(404, $response->statusCode);
    }

    public function testClaimServerRendersClaimTemplate(): void
    {
        $renderer = $this->createMock(PageRenderer::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with('home/claim-server.tpl', self::anything())
            ->willReturn('<html>claim</html>');

        $auth = $this->createMock(AuthManager::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $controller = new PageController($renderer, $auth, $serverInfo);

        $request = new Request();
        $request->path = '/claim-server';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }
}
