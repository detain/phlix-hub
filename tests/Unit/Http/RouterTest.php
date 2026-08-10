<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use BadMethodCallException;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Router} path matching — focused on the `{name}` single-segment
 * vs `{name:regex}` (multi-segment) placeholder support that lets the SPA shell serve
 * deep links like `/app/player/abc` (phlix_ui_missing.md #5).
 */
final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        $r = new Request();
        $r->method = $method;
        $r->path = $path;
        return $r;
    }

    public function testSingleSegmentParamDoesNotMatchAcrossSlashes(): void
    {
        $router = new Router();
        $router->get(
            '/api/v1/servers/{id}',
            static fn (Request $r, array $p): Response => (new Response())->status(200)->json($p)
        );

        $hit = $router->dispatch($this->request('GET', '/api/v1/servers/abc'));
        self::assertSame(200, $hit->statusCode);

        // a second segment must NOT be swallowed by the single-segment {id}
        $miss = $router->dispatch($this->request('GET', '/api/v1/servers/abc/extra'));
        self::assertSame(404, $miss->statusCode);
    }

    public function testGreedyParamMatchesMultipleSegments(): void
    {
        $captured = null;
        $router = new Router();
        $router->get('/app/{path:.*}', static function (Request $r, array $p) use (&$captured): Response {
            $captured = $p['path'] ?? null;
            return (new Response())->status(200)->json(['ok' => true]);
        });

        foreach (['/app/browse', '/app/player/abc', '/app/media/123'] as $path) {
            $res = $router->dispatch($this->request('GET', $path));
            self::assertSame(200, $res->statusCode, "deep link {$path} should serve the shell");
        }
        self::assertSame('media/123', $captured); // last dispatch captured the full tail
    }

    public function testGreedyParamCapturesTheFullTail(): void
    {
        $captured = null;
        $router = new Router();
        $router->get('/app/{path:.*}', static function (Request $r, array $p) use (&$captured): Response {
            $captured = $p['path'] ?? null;
            return (new Response())->status(200)->json(['ok' => true]);
        });

        $router->dispatch($this->request('GET', '/app/player/abc'));
        self::assertSame('player/abc', $captured);
    }

    // --- POST route tests ---

    public function testPostRouteIsRegistered(): void
    {
        $router = new Router();
        $router->post('/api/v1/servers', static function (Request $r, array $p) use (&$router): Response {
            return (new Response())->status(201)->json(['created' => true]);
        });

        $res = $router->dispatch($this->request('POST', '/api/v1/servers'));
        self::assertSame(201, $res->statusCode);
    }

    public function testPostRouteDoesNotMatchGet(): void
    {
        $router = new Router();
        $router->post('/api/v1/servers', static fn (Request $r, array $p): Response => (new Response())->status(201));

        $res = $router->dispatch($this->request('GET', '/api/v1/servers'));
        self::assertSame(404, $res->statusCode);
    }

    // --- PUT route tests ---

    public function testPutRouteIsRegistered(): void
    {
        $router = new Router();
        $router->put(
            '/api/v1/servers/{id}',
            static fn (Request $r, array $p): Response => (new Response())->status(200)->json($p)
        );

        $res = $router->dispatch($this->request('PUT', '/api/v1/servers/123'));
        self::assertSame(200, $res->statusCode);
    }

    // --- PATCH route tests ---

    public function testPatchRouteIsRegistered(): void
    {
        $router = new Router();
        $router->patch(
            '/api/v1/servers/{id}',
            static fn (Request $r, array $p): Response => (new Response())->status(200)->json($p)
        );

        $res = $router->dispatch($this->request('PATCH', '/api/v1/servers/456'));
        self::assertSame(200, $res->statusCode);
    }

    // --- DELETE route tests ---

    public function testDeleteRouteIsRegistered(): void
    {
        $router = new Router();
        $router->delete(
            '/api/v1/servers/{id}',
            static fn (Request $r, array $p): Response => (new Response())->status(204)
        );

        $res = $router->dispatch($this->request('DELETE', '/api/v1/servers/789'));
        self::assertSame(204, $res->statusCode);
    }

    // --- group() tests ---

    public function testGroupAppliesPrefixToChildRoutes(): void
    {
        $router = new Router();
        $router->group('/api/v1', static function (Router $r): void {
            $r->get('/servers', static fn (Request $r, array $p): Response => (new Response())->status(200));
        });

        $res = $router->dispatch($this->request('GET', '/api/v1/servers'));
        self::assertSame(200, $res->statusCode);
    }

    public function testGroupAppliesMiddlewareToChildRoutes(): void
    {
        $middlewareCalled = false;
        $router = new Router();
        $router->group(
            '/api/v1',
            static function (Router $r) use (&$middlewareCalled): void {
                $r->get('/servers', static fn (Request $r, array $p): Response => (new Response())->status(200));
            },
            [static function (Request $req) use (&$middlewareCalled): ?Response {
                $middlewareCalled = true;
                return null;
            }]
        );

        $router->dispatch($this->request('GET', '/api/v1/servers'));
        self::assertTrue($middlewareCalled);
    }

    public function testGroupRestoresPrefixAfterCallback(): void
    {
        $router = new Router();
        $router->group('/first', static function (Router $r): void {
            $r->get('/path', static fn (Request $r, array $p): Response => (new Response())->status(200));
        });
        // After group, prefix should be restored - this route should be at root
        $router->get('/other', static fn (Request $r, array $p): Response => (new Response())->status(200));

        self::assertSame(200, $router->dispatch($this->request('GET', '/other'))->statusCode);
        self::assertSame(200, $router->dispatch($this->request('GET', '/first/path'))->statusCode);
    }

    // --- getRoutes() tests ---

    public function testGetRoutesReturnsRegisteredRoutes(): void
    {
        $router = new Router();
        $router->get('/path1', static fn (Request $r, array $p): Response => (new Response()));
        $router->post('/path2', static fn (Request $r, array $p): Response => (new Response()));

        $routes = $router->getRoutes();
        self::assertArrayHasKey('GET', $routes);
        self::assertArrayHasKey('POST', $routes);
    }

    // --- Method not allowed tests ---

    public function testDispatchReturns404WhenMethodHasNoRoutes(): void
    {
        $router = new Router();
        $router->get('/path', static fn (Request $r, array $p): Response => (new Response()));

        $res = $router->dispatch($this->request('POST', '/path'));
        self::assertSame(404, $res->statusCode);
    }

    // --- Middleware returning response early ---

    public function testGroupMiddlewareCanReturnEarlyResponse(): void
    {
        $router = new Router();
        $earlyResponse = (new Response())->status(401);
        $router->group(
            '/api',
            static function (Router $r) use ($earlyResponse): void {
                $r->get('/protected', static fn (Request $r, array $p): Response => (new Response())->status(200));
            },
            [static function (Request $req) use ($earlyResponse): ?Response {
                return $earlyResponse;
            }]
        );

        $res = $router->dispatch($this->request('GET', '/api/protected'));
        self::assertSame(401, $res->statusCode);
    }

    // --- Multiple params in path ---

    public function testMultipleParamsInPath(): void
    {
        $captured = null;
        $router = new Router();
        $router->get('/api/v1/{resource}/{id}', static function (Request $r, array $p) use (&$captured): Response {
            $captured = $p;
            return (new Response())->status(200)->json($p);
        });

        $res = $router->dispatch($this->request('GET', '/api/v1/servers/123'));
        self::assertSame(200, $res->statusCode);
        self::assertSame('servers', $captured['resource']);
        self::assertSame('123', $captured['id']);
    }

    // --- Path params are passed to handler ---

    public function testPathParamsArePassedToHandler(): void
    {
        $receivedParams = null;
        $router = new Router();
        $router->get('/servers/{id}/details/{detail}', static function (
            Request $r,
            array $p,
        ) use (&$receivedParams): Response {
            $receivedParams = $p;
            return (new Response())->status(200)->json($p);
        });

        $router->dispatch($this->request('GET', '/servers/abc/details/def'));
        self::assertSame(['id' => 'abc', 'detail' => 'def'], $receivedParams);
    }
}
