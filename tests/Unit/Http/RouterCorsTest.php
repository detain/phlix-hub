<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\CorsPolicy;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Router-integration tests for the CORS preflight short-circuit and the
 * response decoration (review hub__crossfe MAJOR-1).
 *
 * These pin the dispatch contract end of the seam the browser actually
 * probes: OPTIONS must answer BEFORE middleware (preflights carry no
 * Authorization by spec), deny must be indistinguishable from an unknown
 * path, and every dispatched response a cross-origin caller can read must
 * carry the `Access-Control-Allow-Origin`/`Vary` pair for allowlisted
 * origins — including error replies, or the client sees an opaque network
 * failure instead of the hub's own 401.
 */
final class RouterCorsTest extends TestCase
{
    private const ALLOWED = 'app://-';

    /**
     * @param array<string, string> $headers name => value (dashed names, uppercased like Workerman)
     */
    private function request(string $method, string $path, array $headers = []): Request
    {
        $r = new Request();
        $r->method = $method;
        $r->path = $path;
        foreach ($headers as $name => $value) {
            // Workerman hands header keys uppercased with dashes intact; mirror that.
            $r->headers[strtoupper($name)] = $value;
        }
        return $r;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonReply(int $status, array $body): Response
    {
        return (new Response())->status($status)->json($body);
    }

    /**
     * A router with the desktop hub-mode surface: a GET list endpoint, a POST
     * create endpoint behind a recording middleware that short-circuits 401
     * unless a bearer token is present, and the SPA catch-all.
     *
     * @return array{0: Router, 1: \ArrayObject<int,string>} router + middleware call recorder
     */
    private function desktopRouter(?CorsPolicy $policy = null): array
    {
        $calls = new \ArrayObject();

        $router = new Router($policy ?? new CorsPolicy([self::ALLOWED]));
        $auth = static function (Request $request) use ($calls): ?Response {
            $calls->append($request->method);
            if ($request->getHeader('Authorization') === null) {
                return (new Response())->status(401)->json(['error' => 'Unauthorized', 'code' => 'auth.required']);
            }
            return null;
        };

        $router->group('/api/v1', static function (Router $r): void {
            $r->get('/me/servers', static fn (): Response => self::jsonReply(200, ['servers' => []]));
            $r->post('/me/invite-links', static fn (): Response => self::jsonReply(201, ['created' => true]));
        }, [$auth]);
        $router->get('/app/{path:.*}', static fn (): Response => self::jsonReply(200, ['shell' => true]));

        return [$router, $calls];
    }

    // ---- preflight short-circuit ----

    public function testAllowedPreflightForPostRouteReturnsExactHeaderSetAndRunsNoMiddleware(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/me/invite-links', [
            'Origin' => self::ALLOWED,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ]));

        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body, 'preflight replies carry no JSON body (contracts law)');
        self::assertArrayNotHasKey('Content-Type', $response->headers);
        self::assertSame('app://-', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('OPTIONS, POST', $response->headers['Access-Control-Allow-Methods']);
        self::assertSame('authorization, content-type', $response->headers['Access-Control-Allow-Headers']);
        self::assertSame('600', $response->headers['Access-Control-Max-Age']);
        self::assertSame('Origin', $response->headers['Vary']);

        // THE short-circuit law: the auth middleware never saw the probe.
        self::assertCount(0, $calls, 'OPTIONS must run NO route middleware');
    }

    public function testPreflightForGetRouteReportsOnlyTheRegisteredVerbs(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/me/servers', ['Origin' => self::ALLOWED]));

        self::assertSame(204, $response->statusCode);
        self::assertSame('GET, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
        self::assertCount(0, $calls);
    }

    public function testPreflightForUnknownPathIsA404AndRunsNoMiddleware(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/no/such/path', ['Origin' => self::ALLOWED]));

        self::assertSame(404, $response->statusCode);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headers);
        self::assertCount(0, $calls);
    }

    public function testPreflightForGreedySpaCatchAllMatchesDeepLink(): void
    {
        $router = new Router(new CorsPolicy([self::ALLOWED]));
        $router->get('/app/{path:.*}', static fn (Request $r, array $p): Response => (new Response())->status(200));

        $response = $router->dispatch($this->request('OPTIONS', '/app/player/abc', ['Origin' => self::ALLOWED]));

        self::assertSame(204, $response->statusCode);
        self::assertSame('GET, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
    }

    // ---- deny is silent ----

    public function testDisallowedOriginPreflightIsIndistinguishableFromUnknownPath(): void
    {
        $policy = new CorsPolicy([self::ALLOWED]);
        [$router, $calls] = $this->desktopRouter($policy);

        $knownPath = $router->dispatch($this->request('OPTIONS', '/api/v1/me/invite-links', [
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ]));
        $unknownPath = $router->dispatch($this->request('OPTIONS', '/api/v1/never/registered', [
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ]));

        foreach ([$knownPath, $unknownPath] as $denied) {
            self::assertSame(404, $denied->statusCode);
            self::assertArrayNotHasKey(
                'Access-Control-Allow-Origin',
                $denied->headers,
                'deny must grant nothing — asserted, not merely "no crash"',
            );
            self::assertArrayNotHasKey('Access-Control-Allow-Methods', $denied->headers);
            self::assertSame('Origin', $denied->headers['Vary']);
        }

        self::assertSame($knownPath->body, $unknownPath->body, 'same envelope; probing cannot map the route table');
        self::assertCount(0, $calls);
    }

    public function testDefaultPolicyDeniesWhenEnvironmentIsUnset(): void
    {
        $previous = getenv(CorsPolicy::ENV_ALLOW_ORIGINS);
        putenv(CorsPolicy::ENV_ALLOW_ORIGINS);
        try {
            $router = new Router(); // boot-default construction path
            $response = $router->dispatch($this->request('OPTIONS', '/api/v1/me/servers', ['Origin' => 'app://-']));

            self::assertSame(404, $response->statusCode, 'shipped default is deny-all');
            self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        } finally {
            if ($previous === false) {
                putenv(CorsPolicy::ENV_ALLOW_ORIGINS);
            } else {
                putenv(CorsPolicy::ENV_ALLOW_ORIGINS . '=' . $previous);
            }
        }
    }

    // ---- the actual response must be readable too ----

    public function testAllowedGetCarriesAllowOriginAndVary(): void
    {
        [$router, $calls] = $this->desktopRouter();

        // Authorization present so the route middleware passes and the handler answers.
        $response = $router->dispatch($this->request('GET', '/api/v1/me/servers', [
            'Origin' => self::ALLOWED,
            'Authorization' => 'Bearer tok',
        ]));

        self::assertSame(200, $response->statusCode);
        self::assertSame('app://-', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response->headers['Vary']);
        self::assertSame(['GET'], (array) $calls->getArrayCopy());
    }

    public function testMiddlewareShortCircuitReplyIsDecoratedForAllowedOrigin(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('GET', '/api/v1/me/servers', ['Origin' => self::ALLOWED]));

        self::assertSame(401, $response->statusCode);
        // a bare 401 without ACAO reads to the browser as a network error, not an auth failure
        self::assertSame('app://-', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame(['GET'], (array) $calls->getArrayCopy());
    }

    public function testDisallowedGetKeepsTheExactHandlerEnvelopeWithoutCorsGrant(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $baseline = $router->dispatch($this->request('GET', '/api/v1/me/servers', ['Authorization' => 'Bearer tok']));
        $response = $router->dispatch($this->request('GET', '/api/v1/me/servers', [
            'Origin' => 'https://evil.example',
            'Authorization' => 'Bearer tok',
        ]));

        self::assertSame(200, $response->statusCode);
        // the handler envelope is untouched; only the CORS grant is withheld
        self::assertSame($baseline->body, $response->body);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertSame('Origin', $response->headers['Vary']);
    }

    public function testSameOriginRequestIsByteIdenticalToPreCorsHub(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('GET', '/api/v1/me/servers', ['Authorization' => 'Bearer tok']));

        self::assertSame(200, $response->statusCode);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertArrayNotHasKey('Vary', $response->headers, 'no Origin header -> the router adds NOTHING');
    }

    // ---- method-key regression: the router still keys routes by verb ----

    public function testPostToGetOnlyRouteStillNotFound(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('POST', '/api/v1/me/servers', ['Authorization' => 'Bearer tok']));

        self::assertSame(404, $response->statusCode);
        // a method-keyed miss must not run the GET route's middleware
        self::assertSame([], (array) $calls->getArrayCopy());
    }

    public function testOptionsDispatchDoesNotMutateTheRouteTable(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $before = $router->getRoutes();
        $router->dispatch($this->request('OPTIONS', '/api/v1/me/servers', ['Origin' => self::ALLOWED]));

        self::assertSame($before, $router->getRoutes(), 'preflight answering must not register an OPTIONS route');
        self::assertArrayNotHasKey('OPTIONS', $router->getRoutes());
        self::assertCount(0, $calls);
    }
}
