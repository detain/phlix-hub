<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Phlix\Hub\Http\Router;

/**
 * Run the ONE registrar the hub's constructor actually calls —
 * `Application::registerRoutes()` — and assert the WHOLE production route table
 * it produces.
 *
 * The per-registrar suite ({@see RegistrarRouteTableTest}) invokes each
 * `register*Routes()` method directly, so it stays green if the
 * `$this->registerMetricsRoutes();` line inside `registerRoutes()` is deleted
 * while every metrics endpoint 404s in production. This suite is the one that
 * goes red for that: it drives the real composition and requires the union of
 * every sub-registrar's manifest to be present.
 *
 * It also catches route SHADOWING, which a per-registrar table cannot see: a
 * pattern registered earlier in the combined table can swallow a later route's
 * URL, and `Router::dispatch()` returns on the FIRST match. Each gated route is
 * therefore dispatched against the full table and must still answer its own
 * gate.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class ApplicationRouteCompositionTest extends RouteRegistrationTestCase
{
    /**
     * Routes deliberately registered with NO route middleware.
     *
     * Pinned as an exact set so a new endpoint cannot be added ungated without
     * a test going red. Everything here either needs to be reachable before the
     * caller has a hub session, or authenticates INSIDE its controller:
     *
     *  - `/health`, `/`, `/signup`, `/login`, `/app*` — public surface.
     *  - `POST /api/v1/auth/*` — these MINT the credentials.
     *  - `/.well-known/jwks.json` — public key discovery.
     *  - `GET /invite/{token}` — an invite link must open for a logged-out user.
     *  - `/api/v1/servers/{id}/relay`, `/client/{server_id}`,
     *    `/api/v1/servers/{id}/subdomain` — server-facing;
     *    RelayController/ClientMountController/SubdomainController each
     *    validate an Ed25519 enrollment JWT themselves.
     *
     * @var list<string>
     */
    private const UNGATED_ROUTES = [
        'DELETE /api/v1/servers/{id}/subdomain',
        'GET /',
        'GET /.well-known/jwks.json',
        'GET /app',
        'GET /app/{path:.*}',
        'GET /client/{server_id}',
        'GET /health',
        'GET /invite/{token}',
        'GET /login',
        'GET /signup',
        'POST /api/v1/auth/login',
        'POST /api/v1/auth/logout',
        'POST /api/v1/auth/refresh',
        'POST /api/v1/auth/register',
        'POST /api/v1/auth/signup',
        'POST /api/v1/servers/{id}/relay',
        'POST /api/v1/servers/{id}/subdomain',
    ];

    /**
     * The full production table must be exactly the manifest — no route lost,
     * no route added without declaring its gate.
     */
    public function testFullRouteTableIsExactlyTheManifest(): void
    {
        $expected = [];
        foreach (RouteManifest::allRoutes() as $route) {
            $expected[] = RouteManifest::key($route);
        }
        $expected = array_values(array_unique($expected));
        sort($expected);

        $actual = $this->registeredKeys($this->runRegistrar('registerRoutes'));

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                "Application::registerRoutes() no longer produces the pinned route table.\n"
                . "Missing (would 404 in production): [%s]\nUnexpected (undeclared gate): [%s]",
                implode(', ', array_diff($expected, $actual)),
                implode(', ', array_diff($actual, $expected)),
            ),
        );
    }

    /**
     * Every sub-registrar must actually be REACHED from `registerRoutes()`.
     * Deleting a `$this->registerXRoutes();` call leaves the per-registrar
     * suite green; this names the registrar that went missing.
     */
    public function testEverySubRegistrarContributesToTheComposedTable(): void
    {
        $actual = $this->registeredKeys($this->runRegistrar('registerRoutes'));

        foreach (RouteManifest::subRegistrarRoutes() as $registrar => $routes) {
            $missing = [];
            foreach ($routes as $route) {
                if (!in_array(RouteManifest::key($route), $actual, true)) {
                    $missing[] = RouteManifest::key($route);
                }
            }

            self::assertSame(
                [],
                $missing,
                sprintf(
                    'Application::registerRoutes() no longer reaches %s() — missing [%s]',
                    $registrar,
                    implode(', ', $missing),
                ),
            );
        }
    }

    /**
     * No two registrars may claim the same METHOD+path: `Router::addRoute()`
     * keys its table by compiled pattern, so the second registration silently
     * REPLACES the first (handler and middleware alike).
     */
    public function testNoTwoRegistrarsClaimTheSameRoute(): void
    {
        $owners = [];
        foreach (RouteManifest::subRegistrarRoutes() as $registrar => $routes) {
            foreach ($routes as $route) {
                $owners[RouteManifest::key($route)][] = $registrar;
            }
        }
        foreach (RouteManifest::topLevelRoutes() as $route) {
            $owners[RouteManifest::key($route)][] = 'registerRoutes';
        }

        $collisions = [];
        foreach ($owners as $key => $registrars) {
            if (count($registrars) > 1) {
                $collisions[$key] = $registrars;
            }
        }

        self::assertSame(
            [],
            $collisions,
            'two registrars register the same METHOD+path; the later one silently replaces the earlier: '
            . json_encode($collisions, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Gated routes must still answer their own gate when dispatched against the
     * COMBINED table — i.e. no earlier pattern shadows them.
     */
    public function testGatedRoutesAreNotShadowedInTheComposedTable(): void
    {
        $router = $this->runRegistrar('registerRoutes');

        foreach (RouteManifest::allRoutes() as $route) {
            if ($route['gate'] === RouteManifest::GATE_PUBLIC) {
                continue;
            }

            $response = $router->dispatch($this->request($route['method'], $route['url']));

            self::assertNotSame(
                404,
                $response->statusCode,
                RouteManifest::key($route) . ' is missing from the composed route table',
            );

            $expected = match ($route['gate']) {
                RouteManifest::GATE_AUTH_JSON, RouteManifest::GATE_ADMIN, RouteManifest::GATE_ENROLLMENT => 401,
                RouteManifest::GATE_AUTH_HTML, RouteManifest::GATE_ADMIN_HTML => 302,
                RouteManifest::GATE_HUB_PROTOCOL => 400,
                default => self::fail('unhandled gate: ' . $route['gate']),
            };

            self::assertSame(
                $expected,
                $response->statusCode,
                sprintf(
                    '%s answered %d in the COMPOSED table but its gate (%s) requires %d — '
                    . 'an earlier route pattern is probably shadowing it',
                    RouteManifest::key($route),
                    $response->statusCode,
                    $route['gate'],
                    $expected,
                ),
            );
        }
    }

    /**
     * The set of routes carrying NO route middleware must not grow silently.
     */
    public function testUngatedRoutesAreExactlyTheKnownSet(): void
    {
        $router = $this->runRegistrar('registerRoutes');

        $ungated = [];
        foreach ($router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                if ($route['middleware'] === []) {
                    $ungated[] = $method . ' ' . $route['path'];
                }
            }
        }
        sort($ungated);

        $expected = self::UNGATED_ROUTES;
        sort($expected);

        self::assertSame(
            $expected,
            $ungated,
            "the set of routes registered WITHOUT route middleware changed.\nNewly ungated: ["
            . implode(', ', array_diff($ungated, $expected))
            . "]\nNo longer ungated: ["
            . implode(', ', array_diff($expected, $ungated))
            . ']',
        );
    }

    /**
     * Every admin route in the manifest must be gated by BOTH middleware, in
     * that order — proven structurally as well as by dispatch, so a chain that
     * ran the admin check BEFORE auth (which would 401 for the wrong reason)
     * cannot masquerade as correct.
     */
    public function testAdminRoutesCarryAuthThenAdminMiddleware(): void
    {
        $router = $this->runRegistrar('registerRoutes');
        $table  = $this->routeMiddlewareByKey($router);

        foreach (RouteManifest::allRoutes() as $route) {
            if (
                $route['gate'] !== RouteManifest::GATE_ADMIN
                && $route['gate'] !== RouteManifest::GATE_ADMIN_HTML
            ) {
                continue;
            }
            $key = RouteManifest::key($route);
            self::assertArrayHasKey($key, $table, $key . ' is not in the composed route table');

            self::assertSame(
                [$this->authMiddleware, $this->adminMiddleware],
                $table[$key],
                $key . ' must be gated [AuthMiddleware, AdminMiddleware], in that order',
            );
        }
    }

    /**
     * Auth-only routes must carry exactly the real AuthMiddleware.
     */
    public function testAuthOnlyRoutesCarryTheRealAuthMiddleware(): void
    {
        $router = $this->runRegistrar('registerRoutes');
        $table  = $this->routeMiddlewareByKey($router);

        foreach (RouteManifest::allRoutes() as $route) {
            if (
                $route['gate'] !== RouteManifest::GATE_AUTH_JSON
                && $route['gate'] !== RouteManifest::GATE_AUTH_HTML
            ) {
                continue;
            }
            $key = RouteManifest::key($route);
            self::assertArrayHasKey($key, $table, $key . ' is not in the composed route table');

            self::assertContains(
                $this->authMiddleware,
                $table[$key],
                $key . ' must be gated by the real AuthMiddleware',
            );
        }
    }

    /**
     * `"METHOD path"` → the middleware chain the router holds for it.
     *
     * @return array<string, array<int, callable>>
     */
    private function routeMiddlewareByKey(Router $router): array
    {
        $table = [];
        foreach ($router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $table[$method . ' ' . $route['path']] = $route['middleware'];
            }
        }

        return $table;
    }
}
