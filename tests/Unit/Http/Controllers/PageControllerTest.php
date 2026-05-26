<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\WebPortal\PageRenderer;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\PageController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PageController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\PageController
 */
final class PageControllerTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $expectedVars
     */
    private function controller(string $expectedTemplate, ?array $expectedVars = null): PageController
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
        return new PageController($renderer, $auth, $serverInfo, $this->adminMiddleware());
    }

    /**
     * Build a real AdminMiddleware backed by mocked dependencies — the
     * controller only calls its `checkAccess()` helper, which is pure and
     * cheap to exercise. Default repo mock returns null from findAdminById,
     * so callers that want admin access should override per-test.
     */
    private function adminMiddleware(?UserRepository $users = null): AdminMiddleware
    {
        return new AdminMiddleware(
            $users ?? $this->createMock(UserRepository::class),
            $this->createMock(AuditLogger::class),
        );
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
        $controller = new PageController($renderer, $auth, $serverInfo, $this->adminMiddleware());

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
        $controller = new PageController($renderer, $auth, $serverInfo, $this->adminMiddleware());

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
        $controller = new PageController($renderer, $auth, $serverInfo, $this->adminMiddleware());

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
        $controller = new PageController($renderer, $auth, $serverInfo, $this->adminMiddleware());

        $request = new Request();
        $request->path = '/claim-server';

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }

    public function testAdminRequestsReturns403ForNonAdmin(): void
    {
        $renderer = $this->createMock(PageRenderer::class);
        $renderer->expects(self::never())->method('render');

        $auth = $this->createMock(AuthManager::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);

        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturn(null);

        $controller = new PageController($renderer, $auth, $serverInfo, $this->adminMiddleware($users));

        $request = new Request();
        $request->path = '/admin/requests';
        $request->userId = 'u-1';

        $response = $controller($request);
        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('Forbidden', $response->body);
    }

    public function testAdminRequestsRendersTemplateForAdmin(): void
    {
        $renderer = $this->createMock(PageRenderer::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with('home/admin-requests.tpl', self::callback(static function ($vars): bool {
                return is_array($vars) && ($vars['is_admin'] ?? null) === true;
            }))
            ->willReturn('<html>admin</html>');

        $auth = $this->createMock(AuthManager::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);

        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturn(['id' => 'u-1', 'is_admin' => 1]);

        $controller = new PageController($renderer, $auth, $serverInfo, $this->adminMiddleware($users));

        $request = new Request();
        $request->path = '/admin/requests';
        $request->userId = 'u-1';
        $request->user = ['id' => 'u-1', 'is_admin' => 1];

        $response = $controller($request);
        self::assertSame(200, $response->statusCode);
    }
}
