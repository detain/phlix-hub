<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Phlix\Hub\Http\Router;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Dispatch EVERY route the production registrars register through the REAL
 * middleware chain and assert the gate that route is supposed to sit behind.
 *
 * "The route exists" is only half the contract: a route can be registered and
 * ungated. Each case below drives a concrete URL into the router the real
 * `Application::register*Routes()` method built and asserts the production
 * outcome for a caller with NO credentials — 401 on the JSON surface, a 302 to
 * `/app/login` on the SSR page paths, 401 `ENROLLMENT_TOKEN_EXPIRED` on the
 * server-facing enrollment-JWT routes, 400 `HUB_PROTOCOL_UNSUPPORTED` on the
 * protocol-gated claim routes. Admin routes get a second pass with a REAL,
 * valid HS256 token for a non-admin user, which must be refused 403.
 *
 * Two independent failure modes are caught:
 *
 *  - the route line is deleted ⇒ dispatch 404s ⇒ red, naming the method+path;
 *  - a middleware is dropped from the group ⇒ the request sails through to a
 *    handler instead of being refused ⇒ red.
 *
 * Routes marked {@see RouteManifest::GATE_PUBLIC} are deliberately not gated by
 * route middleware; they are asserted for REGISTRATION only (and, where they are
 * a plain redirect closure, for their Location). The ungated set as a whole is
 * pinned by {@see ApplicationRouteCompositionTest::testUngatedRoutesAreExactlyTheKnownSet()}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class RegistrarAuthGateTest extends RouteRegistrationTestCase
{
    /**
     * Every gated route of every sub-registrar, one data set each.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function gatedRouteProvider(): iterable
    {
        foreach (RouteManifest::subRegistrarRoutes() as $registrar => $routes) {
            foreach ($routes as $route) {
                if ($route['gate'] === RouteManifest::GATE_PUBLIC) {
                    continue;
                }
                $label = $registrar . ': ' . RouteManifest::key($route);
                yield $label => [$registrar, $route];
            }
        }
    }

    /**
     * Every gated route of every sub-registrar that requires auth + admin.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function adminRouteProvider(): iterable
    {
        foreach (RouteManifest::subRegistrarRoutes() as $registrar => $routes) {
            foreach ($routes as $route) {
                if (
                    $route['gate'] !== RouteManifest::GATE_ADMIN
                    && $route['gate'] !== RouteManifest::GATE_ADMIN_HTML
                ) {
                    continue;
                }
                $label = $registrar . ': ' . RouteManifest::key($route);
                yield $label => [$registrar, $route];
            }
        }
    }

    /**
     * Public routes that are a bare redirect closure, so dispatching them is
     * side-effect free and their Location can be asserted.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function publicRedirectProvider(): iterable
    {
        foreach (RouteManifest::subRegistrarRoutes() as $registrar => $routes) {
            foreach ($routes as $route) {
                if (!isset($route['redirect'])) {
                    continue;
                }
                $label = $registrar . ': ' . RouteManifest::key($route);
                yield $label => [$registrar, $route];
            }
        }
    }

    /**
     * @param array<string, string> $route
     */
    #[DataProvider('gatedRouteProvider')]
    public function testRouteIsRegisteredAndRefusesAnUncredentialedCaller(string $registrar, array $route): void
    {
        $router = $this->runRegistrar($registrar);

        // No credentials and no protocol header — every gate must refuse.
        $response = $this->dispatchExpectingRegistered(
            $router,
            $this->request($route['method'], $route['url']),
            sprintf('Application::%s() must register %s', $registrar, RouteManifest::key($route)),
        );

        switch ($route['gate']) {
            case RouteManifest::GATE_AUTH_JSON:
            case RouteManifest::GATE_ADMIN:
                self::assertSame(
                    401,
                    $response->statusCode,
                    RouteManifest::key($route) . ' must be auth-gated (401 without credentials)',
                );
                break;

            case RouteManifest::GATE_AUTH_HTML:
            case RouteManifest::GATE_ADMIN_HTML:
                // An admin PAGE path is still fronted by AuthMiddleware, which
                // bounces an anonymous browser to the SPA login before the
                // admin check ever runs. The 403 half of GATE_ADMIN_HTML is
                // asserted by testAdminRouteRefusesAnAuthenticatedNonAdmin().
                self::assertSame(
                    302,
                    $response->statusCode,
                    RouteManifest::key($route) . ' must bounce an anonymous browser to the SPA login',
                );
                self::assertSame('/app/login', $response->headers['Location'] ?? null);
                break;

            case RouteManifest::GATE_ENROLLMENT:
                self::assertSame(
                    401,
                    $response->statusCode,
                    RouteManifest::key($route) . ' must require an enrollment JWT',
                );
                self::assertStringContainsString('ENROLLMENT_TOKEN_EXPIRED', $response->body);
                break;

            case RouteManifest::GATE_HUB_PROTOCOL:
                self::assertSame(
                    400,
                    $response->statusCode,
                    RouteManifest::key($route) . ' must require Accept-Phlix-Protocol: v1',
                );
                self::assertStringContainsString('HUB_PROTOCOL_UNSUPPORTED', $response->body);
                break;

            case RouteManifest::GATE_ALEXA:
                // The CODE, not just the 400: the protocol gate also answers 400,
                // so a status-only assertion would pass on a route that had lost
                // its signature gate and picked up some other 400-answering one.
                self::assertSame(
                    400,
                    $response->statusCode,
                    RouteManifest::key($route) . ' must require an Amazon request signature',
                );
                self::assertStringContainsString('ALEXA_MISSING_CERT_CHAIN_URL', $response->body);
                break;

            default:
                self::fail('unhandled gate: ' . $route['gate']);
        }
    }

    /**
     * An authenticated NON-admin (real HS256 token, real AuthMiddleware) must be
     * refused 403 by the admin routes. This is what catches an admin route that
     * kept `AuthMiddleware` but lost `AdminMiddleware`.
     *
     * @param array<string, string> $route
     */
    #[DataProvider('adminRouteProvider')]
    public function testAdminRouteRefusesAnAuthenticatedNonAdmin(string $registrar, array $route): void
    {
        $router = $this->runRegistrar($registrar);

        $response = $this->dispatchExpectingRegistered(
            $router,
            $this->request($route['method'], $route['url'], 'plain-user-' . md5(RouteManifest::key($route))),
            sprintf('Application::%s() must register %s', $registrar, RouteManifest::key($route)),
        );

        self::assertSame(
            403,
            $response->statusCode,
            RouteManifest::key($route)
            . ' must be admin-gated — an authenticated non-admin got '
            . $response->statusCode . ' instead of 403',
        );
    }

    /**
     * Control must PASS the protocol gate once `Accept-Phlix-Protocol: v1` is
     * present — proving the 400 asserted above comes from the gate rather than
     * from the route being absent (a missing route 404s for both header states,
     * so the negative case alone cannot tell the two apart).
     *
     * A gate that passes hands control to the controller stub, which has no
     * collaborators; reaching it (a plain response OR a throw out of the
     * handler) is itself the evidence. Only a `HUB_PROTOCOL_UNSUPPORTED` body
     * means the gate short-circuited again.
     */
    public function testProtocolGatedClaimRoutesPassTheGateWithTheHeader(): void
    {
        $router = $this->runRegistrar('registerServerRoutes');

        /** @var array<string, bool> $passedGate */
        $passedGate = [];
        foreach (RouteManifest::subRegistrarRoutes()['registerServerRoutes'] as $route) {
            if ($route['gate'] !== RouteManifest::GATE_HUB_PROTOCOL) {
                continue;
            }

            try {
                $response = $router->dispatch(
                    $this->hubProtocolRequest($route['method'], $route['url']),
                );
                $passedGate[RouteManifest::key($route)] = $response->statusCode !== 404
                    && !str_contains($response->body, 'HUB_PROTOCOL_UNSUPPORTED');
            } catch (\Throwable) {
                // The handler ran and tripped over the constructor-less stub —
                // which means both the route and the gate were passed.
                $passedGate[RouteManifest::key($route)] = true;
            }
        }

        self::assertNotSame([], $passedGate, 'the manifest declares no protocol-gated routes to check');
        self::assertSame(
            [],
            array_keys(array_filter($passedGate, static fn (bool $ok): bool => !$ok)),
            'these routes still refuse a request that carries Accept-Phlix-Protocol: v1',
        );
    }

    /**
     * The auth+protocol route (`POST /api/v1/server-claims/claim`) must be
     * refused by the AUTH gate even when the protocol header is correct.
     */
    public function testUserAuthenticatedClaimRouteIsAuthGatedIndependentlyOfTheProtocolHeader(): void
    {
        $router = $this->runRegistrar('registerServerRoutes');

        $response = $router->dispatch(
            $this->hubProtocolRequest('POST', '/api/v1/server-claims/claim'),
        );

        self::assertSame(
            401,
            $response->statusCode,
            'POST /api/v1/server-claims/claim must stay behind AuthMiddleware',
        );
    }

    /**
     * Public redirect routes must be registered and answer their documented
     * Location — the only ungated routes it is safe to dispatch here.
     *
     * @param array<string, string> $route
     */
    #[DataProvider('publicRedirectProvider')]
    public function testPublicRedirectRouteIsRegistered(string $registrar, array $route): void
    {
        $router = $this->runRegistrar($registrar);

        $response = $this->dispatchExpectingRegistered(
            $router,
            $this->request($route['method'], $route['url']),
            sprintf('Application::%s() must register %s', $registrar, RouteManifest::key($route)),
        );

        self::assertSame(302, $response->statusCode);
        self::assertSame($route['redirect'], $response->headers['Location'] ?? null);
    }

    /**
     * Guard for the provider itself: a manifest typo that produced a URL which
     * cannot match its own route template would make every case above pass for
     * the wrong reason. Assert each sample URL matches the template it belongs
     * to, using the SAME placeholder syntax {@see Router} compiles.
     */
    public function testEverySampleUrlMatchesItsRouteTemplate(): void
    {
        foreach (RouteManifest::allRoutes() as $route) {
            $pattern = preg_replace_callback(
                '/\{([a-zA-Z_]+)(?::([^{}]+))?\}/',
                static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
                $route['path'],
            );
            self::assertIsString($pattern);

            self::assertSame(
                1,
                preg_match('#^' . $pattern . '$#', $route['url']),
                sprintf(
                    'manifest sample URL %s cannot match its own template %s',
                    $route['url'],
                    $route['path'],
                ),
            );
        }
    }
}
