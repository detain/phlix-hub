<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

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
        $router->post('/api/v1/servers', static function (Request $r, array $p): Response {
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
        $middlewareState = new class {
            public bool $called = false;
        };
        $router = new Router();
        $router->group(
            '/api/v1',
            static function (Router $r): void {
                $r->get('/servers', static fn (Request $r, array $p): Response => (new Response())->status(200));
            },
            [static function (Request $req) use ($middlewareState): ?Response {
                $middlewareState->called = true;
                return null;
            }]
        );

        $router->dispatch($this->request('GET', '/api/v1/servers'));
        self::assertTrue($middlewareState->called);
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

    /**
     * R-HUB-W2-C1 law-pin: the router's unmatched-route fallback speaks the
     * unified member-facing 404 envelope minted in @phlix/contracts v0.5.5 —
     * the registered machine word `not_found` in `code`, and ONE human
     * sentence co-mirrored in `error` and `message` (the pinned `extractError`
     * read order is error-then-message, so both keys must carry the same
     * sentence). The bare HTTP phrase `Not Found` is on the member-channel ban
     * list (\Phlix\Hub\Tests\Unit\Http\Controllers\MemberErrorProseTest); the
     * sentence here is Router's own shipped wording, kept verbatim.
     * `assertSame` on the decoded body pins the key ORDER (error, code,
     * message) as well as the values — the canonical envelope shape.
     */
    public function testUnmatchedRouteEmitsTheUnifiedNotFoundEnvelope(): void
    {
        $router = new Router();
        $router->get('/path', static fn (Request $r, array $p): Response => (new Response())->status(200));

        $miss = $router->dispatch($this->request('GET', '/never/registered'));
        self::assertSame(404, $miss->statusCode);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $miss->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                'error' => 'The requested resource was not found',
                'code' => 'not_found',
                'message' => 'The requested resource was not found',
            ],
            $decoded,
            'router 404 must carry the unified error/code/message envelope (R-HUB-W2-C1)',
        );
    }

    // --- Middleware returning response early ---

    public function testGroupMiddlewareCanReturnEarlyResponse(): void
    {
        $router = new Router();
        $earlyResponse = (new Response())->status(401);
        $router->group(
            '/api',
            static function (Router $r): void {
                $r->get('/protected', static fn (Request $r, array $p): Response => (new Response())->status(200));
            },
            [static function (Request $req) use ($earlyResponse): Response {
                return $earlyResponse;
            }]
        );

        $res = $router->dispatch($this->request('GET', '/api/protected'));
        self::assertSame(401, $res->statusCode);
    }

    // --- Multiple params in path ---

    public function testMultipleParamsInPath(): void
    {
        $capturedState = new class {
            /** @var array<array-key, mixed> */
            public array $params = [];
        };
        $router = new Router();
        $router->get('/api/v1/{resource}/{id}', static function (Request $r, array $p) use ($capturedState): Response {
            $capturedState->params = $p;
            return (new Response())->status(200)->json($p);
        });

        $res = $router->dispatch($this->request('GET', '/api/v1/servers/123'));
        self::assertSame(200, $res->statusCode);
        self::assertSame('servers', $capturedState->params['resource']);
        self::assertSame('123', $capturedState->params['id']);
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

    // --- Group bookkeeping survives a throw (HUB-3.1 port of server SEC law) ---

    /**
     * A throw from inside a `group()` callback must not leak that group's
     * middleware onto routes registered AFTERWARDS.
     *
     * ## Why this is a security test and not a tidiness test
     *
     * `addRoute()` copies `$this->groupMiddleware` onto every route it creates, so a
     * leaked group middleware is silently attached to every later registration. The
     * live shape is `Application`'s route loaders — groups such as `/api/v1` carry
     * the auth gate, so one throw inside such a group (a logger, a config read, a
     * container miss) silently attaches that gate to every route registered
     * afterwards. The server's incident class was an allowlist middleware refusing
     * every non-LAN caller on later routes; the hub inherits the same defect from
     * the same shared lineage. `group()` restores its bookkeeping in a `finally`
     * for exactly that reason.
     *
     * DISCRIMINATING: this test is the pin for that `finally`. Replace the
     * `try/finally` with a plain post-callback restore and `/after` carries the
     * throwing group's middleware under the leaked `/boom` prefix, so the
     * registration assertions and the dispatch assertions below go red.
     */
    public function testAThrowInsideAGroupDoesNotLeakItsMiddlewareOntoLaterRoutes(): void
    {
        $ok = static fn (Request $r): Response => (new Response())->status(200)->json(['ok' => true]);
        $outerGate = static fn (Request $r): ?Response => null;
        // Stands in for an allowlist gate: if it leaks, later routes 403.
        $leakyGate = static fn (Request $r): Response => (new Response())->status(403)->json(['error' => 'nope']);

        $router = new Router();
        $router->group('/outer', static function (Router $r) use ($ok): void {
            $r->get('/a', $ok);
        }, [$outerGate]);

        $threw = false;
        try {
            $router->group('/boom', static function (Router $r) use ($ok): void {
                $r->get('/x', $ok);
                throw new \RuntimeException('registration blew up');
            }, [$leakyGate]);
        } catch (\RuntimeException $e) {
            $threw = true;
            self::assertSame('registration blew up', $e->getMessage());
        }
        self::assertTrue($threw, 'the exception itself must still propagate to the caller');

        $router->get('/after', $ok);

        // Hub routes are keyed by the COMPILED pattern (server keys by path).
        $routes = $router->getRoutes();

        // The PREFIX must be restored too, or the later route is registered at an
        // entirely different path (asserted first so the failure names that cause).
        self::assertArrayHasKey(
            '#^/after$#',
            $routes['GET'],
            'a route registered after a throwing group must not inherit its PREFIX either',
        );
        self::assertArrayNotHasKey('#^/boom/after$#', $routes['GET']);
        self::assertSame(
            [],
            $routes['GET']['#^/after$#']['middleware'],
            'a route registered after a throwing group must carry NO group middleware',
        );
        // Control: the non-throwing group's own route still has its middleware, so
        // this cannot pass by the router simply losing middleware everywhere.
        self::assertCount(1, $routes['GET']['#^/outer/a$#']['middleware']);

        // The consequence, asserted end to end: the later route is not gated.
        self::assertSame(200, $router->dispatch($this->request('GET', '/after'))->statusCode);
        self::assertSame(200, $router->dispatch($this->request('GET', '/outer/a'))->statusCode);
        // And the leaky middleware really would have produced a 403 had it leaked.
        self::assertSame(403, $router->dispatch($this->request('GET', '/boom/x'))->statusCode);
    }

    /**
     * NESTED case: the restore puts back the PREVIOUS prefix and middleware, not
     * empty ones — including when the nested group throws.
     *
     * Both halves matter. Restoring to empty would silently drop the OUTER group's
     * gate from every sibling route registered after a nested group, which is the
     * same class of defect as the leak, in the opposite direction: routes that were
     * meant to be gated would stop being gated.
     *
     * DISCRIMINATING: dropping the `finally` makes `/inner-boom` leak, so `/c` is
     * registered under the wrong prefix (`/inner-boom/c`) with two middleware
     * instead of one, and all three assertions on it fail.
     */
    public function testANestedGroupRestoresThePreviousPrefixAndMiddlewareNotEmptyOnes(): void
    {
        $ok = static fn (Request $r): Response => (new Response())->status(200)->json(['ok' => true]);
        $outerGate = static fn (Request $r): ?Response => null;
        $innerGate = static fn (Request $r): ?Response => null;

        $router = new Router();
        $router->group('/outer', static function (Router $r) use ($ok, $innerGate): void {
            $r->get('/a', $ok);

            // A nested group that completes normally.
            $r->group('/inner', static function (Router $n) use ($ok): void {
                $n->get('/b', $ok);
            }, [$innerGate]);

            // A nested group that blows up mid-registration.
            try {
                $r->group('/inner-boom', static function (Router $n): void {
                    throw new \RuntimeException('nested registration blew up');
                }, [$innerGate]);
            } catch (\RuntimeException) {
                // swallowed on purpose: the point is what the router looks like now
            }

            // Registered back at the OUTER level, after both nested groups.
            $r->get('/c', $ok);
        }, [$outerGate]);

        $routes = $router->getRoutes();

        self::assertArrayHasKey('#^/outer/a$#', $routes['GET']);
        self::assertCount(1, $routes['GET']['#^/outer/a$#']['middleware']);

        // Nested groups OVERWRITE the prefix rather than concatenating it — a
        // pre-existing quirk, asserted so this test documents the real behaviour
        // instead of an assumed one.
        self::assertArrayHasKey('#^/inner/b$#', $routes['GET']);
        self::assertCount(2, $routes['GET']['#^/inner/b$#']['middleware'], 'outer + inner');

        // THE POINT: after a nested group — throwing or not — the outer prefix and
        // the outer middleware are both back, and neither has been cleared.
        self::assertArrayHasKey(
            '#^/outer/c$#',
            $routes['GET'],
            'the outer prefix must be restored after a nested group throws, not left leaked',
        );
        self::assertCount(
            1,
            $routes['GET']['#^/outer/c$#']['middleware'],
            'the OUTER middleware must be restored — not cleared to [], and not left with the inner one',
        );
        self::assertSame($outerGate, $routes['GET']['#^/outer/c$#']['middleware'][0]);
    }
}
