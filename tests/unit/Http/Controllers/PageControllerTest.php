<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Http\Controllers;

use Phlex\Hub\Auth\AuthManager;
use Phlex\Hub\Common\WebPortal\PageRenderer;
use Phlex\Hub\Http\Controllers\PageController;
use Phlex\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PageController}.
 *
 * @package Phlex\Hub\Tests\unit\Http\Controllers
 * @since 0.2.0
 *
 * @covers \Phlex\Hub\Http\Controllers\PageController
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
        return new PageController($renderer, $auth);
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
        $controller = new PageController($renderer, $auth);

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
        $controller = new PageController($renderer, $auth);

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
        $controller = new PageController($renderer, $auth);

        $request = new Request();
        $request->path = '/nope';

        $response = $controller($request);
        self::assertSame(404, $response->statusCode);
    }
}
