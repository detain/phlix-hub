<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\CorsPolicy;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Router-integration tests for the CORS preflight and the response
 * decoration (review hub__crossfe MAJOR-1; position rework M9r3).
 *
 * These pin the dispatch contract end of the seam the browser actually
 * probes: a preflight is answered ONLY where the path's middleware chain
 * passes (public endpoints; authed endpoints get the same challenge an
 * unauthenticated GET gets — the pre-AUTH allow-answer oracle of 72707074
 * is dead and stays dead), deny must be indistinguishable from an unknown
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
     * A router with the desktop hub-mode surface: a public GET/POST control
     * endpoint (mirroring the real hub's credential-free `/api/v1` members,
     * e.g. server-claims initiation), a GET list endpoint and a POST create
     * endpoint behind a recording middleware that short-circuits 401
     * (byte-identical to AuthMiddleware's `auth.required` envelope) unless a
     * bearer token is present, and the SPA catch-all.
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

        // Public control surface — NO middleware (real-hub analogue:
        // /api/v1/server-claims/new, registered outside the authed group).
        $router->group('/api/v1/auth', static function (Router $r): void {
            $r->get('/status', static fn (): Response => self::jsonReply(200, ['status' => 'ok']));
            $r->get('/refresh', static fn (): Response => self::jsonReply(200, ['refreshed' => 'get']));
            $r->post('/refresh', static fn (): Response => self::jsonReply(200, ['refreshed' => true]));
        });
        $router->group('/api/v1', static function (Router $r): void {
            $r->get('/me/servers', static fn (): Response => self::jsonReply(200, ['servers' => []]));
            $r->post('/me/invite-links', static fn (): Response => self::jsonReply(201, ['created' => true]));
        }, [$auth]);
        $router->get('/app/{path:.*}', static fn (): Response => self::jsonReply(200, ['shell' => true]));

        return [$router, $calls];
    }

    // ---- preflight: public paths pre-answer, authed paths never do ----

    public function testAllowedPreflightForPublicRouteReturnsExactHeaderSetAndRunsNoMiddleware(): void
    {
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/auth/refresh', [
            'Origin' => self::ALLOWED,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ]));

        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body, 'preflight replies carry no JSON body (contracts law)');
        self::assertArrayNotHasKey('Content-Type', $response->headers);
        self::assertSame('app://-', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('GET, OPTIONS, POST', $response->headers['Access-Control-Allow-Methods']);
        self::assertSame('authorization, content-type', $response->headers['Access-Control-Allow-Headers']);
        self::assertSame('600', $response->headers['Access-Control-Max-Age']);
        self::assertSame('Origin', $response->headers['Vary']);

        // Public route = empty chain: the preflight clears it trivially.
        self::assertCount(0, $calls, 'a middleware-less public path must run no chain');
    }

    public function testUnauthenticatedPreflightForAuthedRouteGetsTheChallengeNotTheAllowAnswer(): void
    {
        // THE M9r3 law (red at 72707074, which answered 204 + Allow-Methods
        // here): an allowlisted origin probing an AUTHED path pre-credentials
        // receives the same 401 `auth.required` an unauthenticated GET gets —
        // never a preflight allow. Browsers block the actual request on any
        // non-2xx preflight, so authed REST is not elicitable cross-origin
        // without clearing auth, and the verb list never leaks pre-auth.
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/me/invite-links', [
            'Origin' => self::ALLOWED,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ]));

        self::assertSame(401, $response->statusCode);
        /** @var array{code?: string}|null $envelope */
        $envelope = json_decode($response->body, true);
        self::assertSame('auth.required', is_array($envelope) ? ($envelope['code'] ?? null) : null);
        self::assertArrayNotHasKey(
            'Access-Control-Allow-Methods',
            $response->headers,
            'the registered verb set of an authed path must never be named pre-auth',
        );
        // The challenge stays CORS-decorated — the estate's 401 envelope shape
        // (error+code) is untouched, only the readability headers ride on top.
        self::assertSame('app://-', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response->headers['Vary']);
        self::assertSame(['OPTIONS'], (array) $calls->getArrayCopy(), 'the route chain saw the probe');
    }

    public function testNoOriginOptionsToAuthedRouteNoLongerEnumeratesVerbs(): void
    {
        // 72707074's truthful no-Origin 204 arm ran the same enumeration for
        // every socket without even an Origin header. Post-rework the chain
        // gates it: no token, no verb list.
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/me/servers'));

        self::assertSame(401, $response->statusCode);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headers);
        self::assertSame(['OPTIONS'], (array) $calls->getArrayCopy());
    }

    public function testNoOriginOptionsToPublicRouteStaysTruthful(): void
    {
        // Non-browser probes keep the honest verb list ONLY where the path is
        // public anyway (the openapi spec documents these paths in the clear).
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/auth/refresh'));

        self::assertSame(204, $response->statusCode);
        self::assertSame('GET, OPTIONS, POST', $response->headers['Access-Control-Allow-Methods']);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers, 'no Origin -> no grant');
        self::assertCount(0, $calls);
    }

    public function testPreflightCarryingValidCredentialsClearsTheChainAndGetTheAllowAnswer(): void
    {
        // The reworked position is AFTER auth, not NEVER: a probe that really
        // presents the token AuthMiddleware accepts gets the normal preflight
        // allow (curl/desktop probes can enumerate what they are entitled to).
        [$router, $calls] = $this->desktopRouter();

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/me/invite-links', [
            'Origin' => self::ALLOWED,
            'Authorization' => 'Bearer tok',
        ]));

        self::assertSame(204, $response->statusCode);
        self::assertSame('OPTIONS, POST', $response->headers['Access-Control-Allow-Methods']);
        self::assertSame(['OPTIONS'], (array) $calls->getArrayCopy());
    }

    public function testSharedGroupMiddlewareRunsOnceForAMultiVerbPreflight(): void
    {
        // Dedup law: a path registered under several verbs of one group shares
        // the middleware INSTANCE; the preflight clears the union, so the
        // shared gate runs once, not once per verb.
        $calls = new \ArrayObject();
        $router = new Router(new CorsPolicy([self::ALLOWED]));
        $auth = static function (Request $request) use ($calls): ?Response {
            $calls->append($request->method);
            if ($request->getHeader('Authorization') === null) {
                return (new Response())->status(401)->json(['error' => 'Unauthorized', 'code' => 'auth.required']);
            }
            return null;
        };
        $router->group('/api/v1', static function (Router $r): void {
            $r->get('/me/servers', static fn (): Response => self::jsonReply(200, ['servers' => []]));
            $r->delete('/me/servers', static fn (): Response => self::jsonReply(200, ['cleared' => true]));
        }, [$auth]);

        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/me/servers', [
            'Origin' => self::ALLOWED,
            'Authorization' => 'Bearer tok',
        ]));

        self::assertSame(204, $response->statusCode);
        self::assertSame('DELETE, GET, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
        self::assertSame(['OPTIONS'], (array) $calls->getArrayCopy(), 'shared middleware must run exactly once');
    }

    public function testPreflightReportsTheRegisteredVerbsNotTheRequestedOne(): void
    {
        [$router, $calls] = $this->desktopRouter();

        // The public GET/POST control path answers a DELETE request-method
        // with exactly what is registered — mirror, don't invent (class law).
        $response = $router->dispatch($this->request('OPTIONS', '/api/v1/auth/refresh', [
            'Origin' => self::ALLOWED,
            'Access-Control-Request-Method' => 'DELETE',
        ]));

        self::assertSame(204, $response->statusCode);
        self::assertSame('GET, OPTIONS, POST', $response->headers['Access-Control-Allow-Methods']);
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
        // M9r3: the authed path's chain now DOES see the probe (and rejects
        // it) — reading the table to answer must still never write it.
        self::assertSame(['OPTIONS'], (array) $calls->getArrayCopy());
    }
}
