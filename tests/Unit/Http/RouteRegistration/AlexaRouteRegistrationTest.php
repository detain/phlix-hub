<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Phlix\Hub\Http\Controllers\AlexaSkillController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Router;

use function array_key_exists;
use function count;

/**
 * S91 — the defects this suite catches in `Application::registerAlexaRoutes()`.
 *
 * **1. The signature gate quietly leaving the chain.** `POST /alexa/skill` is a
 * public HTTPS endpoint that anybody on the internet can reach, and
 * {@see AlexaSignatureMiddleware} is the ONLY thing standing in front of it. A
 * wrapper that replaced it — even one that called through — or a chain that
 * gained a second entry, changes the security posture of the endpoint without
 * changing any response anybody tests. So the chain is pinned by exact class
 * name, compared with `assertSame` over a whole array.
 *
 * **2. The sibling-wildcard absorption trap.** Dispatching `POST /alexa/skill`
 * and asserting a 400 is NOT evidence that the literal route exists: delete the
 * registration and some other pattern in the composed table can answer, and a
 * gate-level 400 looks identical. This program has shipped that bug shape
 * (`/api/v1/media/most-watched2` matching `/api/v1/media/most-watched`). So the
 * assertions here are on the registration LIST, by exact string equality —
 * never `str_contains`, which would accept `/alexa/skillX` — and the composed
 * table is examined as a keyed map so the literal's presence is an
 * `array_key_exists` fact rather than a dispatch outcome.
 *
 * **3. A registrar that stops being called.** The per-registrar assertion below
 * is paired with one against the FULL composed table, because a registrar can be
 * perfectly correct and simply never invoked from `registerRoutes()`.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class AlexaRouteRegistrationTest extends RouteRegistrationTestCase
{
    /** The one path literal. Never used as a substring, only compared whole. */
    private const ALEXA_PATH = '/alexa/skill';

    /** The exact, complete middleware chain. */
    private const EXPECTED_CHAIN = [AlexaSignatureMiddleware::class];

    /**
     * The registrar registers EXACTLY one route: verb, path literal and
     * middleware chain, as one whole-array comparison.
     */
    public function testTheRegistrarRegistersExactlyOneRouteWithExactlyOneMiddleware(): void
    {
        self::assertSame(
            [
                [
                    'method' => 'POST',
                    'path' => self::ALEXA_PATH,
                    'middleware' => self::EXPECTED_CHAIN,
                ],
            ],
            self::tableOf($this->runRegistrar('registerAlexaRoutes')),
            'registerAlexaRoutes() must register one route — POST /alexa/skill — gated by the '
            . 'signature middleware and by nothing else. A second route here is a second public '
            . 'Alexa surface; a changed chain is a changed security posture.',
        );
    }

    /**
     * The route is deliberately NOT behind `AuthMiddleware`/`AdminMiddleware`:
     * an Alexa request carries no hub session. Asserted as an explicit absence,
     * because "the chain has one entry" and "the entry is the right one" are two
     * different facts and the second is the one that matters here.
     */
    public function testTheRouteIsGatedByTheSignatureMiddlewareAndNotByTheSessionGates(): void
    {
        $chain = self::tableOf($this->runRegistrar('registerAlexaRoutes'))[0]['middleware'];

        self::assertSame(self::EXPECTED_CHAIN, $chain);
        self::assertNotContains(AuthMiddleware::class, $chain);
        self::assertNotContains(AdminMiddleware::class, $chain);
    }

    /**
     * The registrar resolves exactly the gate and the controller — which pins
     * the HANDLER target. Deleting `resolveAlexaSkillController()` (and wiring
     * some other object into the closure) changes this list.
     */
    public function testTheRegistrarResolvesExactlyTheGateAndTheSkillController(): void
    {
        $this->runRegistrar('registerAlexaRoutes');

        self::assertSame(
            [AlexaSignatureMiddleware::class, AlexaSkillController::class],
            $this->container->requestedIds(),
        );
    }

    /**
     * The composed table — the one `Application::__construct()` actually builds
     * — still carries the exact path literal with the exact chain.
     *
     * This is the sibling-wildcard-absorption assertion. It compares KEYS with
     * `===` (via `array_key_exists` on a map built from the routes' own `path`
     * strings), so a table in which `/alexa/skill` was deleted and
     * `/alexa/{anything}` answered instead fails here even though a dispatch
     * would still 400.
     */
    public function testTheComposedTableCarriesTheExactPathLiteralWithTheExactChain(): void
    {
        $map = self::postPathMap($this->runRegistrar('registerRoutes'));

        // Non-vacuity: the composed table must be the real, whole one. An empty
        // or tiny map would make the lookup below meaningless.
        self::assertGreaterThan(
            30,
            count($map),
            'the composed POST table is far smaller than the production hub has — '
            . 'registerRoutes() did not compose, so this assertion is measuring nothing',
        );

        self::assertTrue(
            array_key_exists(self::ALEXA_PATH, $map),
            'POST /alexa/skill is no longer registered as a literal path. A dispatch test would '
            . 'still see a 400 if a sibling wildcard absorbed it, so this is the assertion that '
            . 'catches the deletion.',
        );
        self::assertSame(self::EXPECTED_CHAIN, $map[self::ALEXA_PATH]);

        // The exact-comparison point, made explicit: a longer sibling must NOT
        // be mistaken for the route. `str_contains('/alexa/skillX', '/alexa/skill')`
        // is true; `array_key_exists` is not.
        self::assertFalse(array_key_exists('/alexa/skillX', $map));
        self::assertFalse(array_key_exists('/alexa', $map));
    }

    /**
     * The endpoint exists only under POST. A GET registration would be a second
     * public Alexa surface nobody gated deliberately.
     */
    public function testTheAlexaPathIsRegisteredUnderPostAndNoOtherVerb(): void
    {
        $router = $this->runRegistrar('registerRoutes');

        $verbs = [];
        foreach ($router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                if ($route['path'] === self::ALEXA_PATH) {
                    $verbs[] = $method;
                }
            }
        }
        sort($verbs);

        self::assertSame(['POST'], $verbs);
    }

    /**
     * A live control beside the structural assertions: the composed table really
     * does run the real gate on that path, and the gate really does refuse an
     * unsigned request with its own code (not a generic 404 or 500).
     *
     * This is evidence about the CHAIN being wired, not about the path existing —
     * which is exactly why it is not the only assertion in this suite.
     */
    public function testAnUnsignedRequestToTheComposedRouteIsRefusedByTheSignatureGate(): void
    {
        $router = $this->runRegistrar('registerRoutes');

        $response = $this->dispatchExpectingRegistered(
            $router,
            $this->request('POST', self::ALEXA_PATH),
            'the Alexa skill endpoint must be registered',
        );

        self::assertSame(400, $response->statusCode);
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);
        self::assertSame('ALEXA_MISSING_CERT_CHAIN_URL', $decoded['code'] ?? null);
        self::assertSame(
            ['ALEXA_MISSING_CERT_CHAIN_URL'],
            $this->alexaAuditor->codes(),
            'the rejection must reach the auditor exactly once',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A router's whole table as comparable arrays: verb, path LITERAL, and the
     * middleware chain by class name in order.
     *
     * @return list<array{method: string, path: string, middleware: list<string>}>
     */
    private static function tableOf(Router $router): array
    {
        $table = [];
        foreach ($router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $chain = [];
                foreach ($route['middleware'] as $middleware) {
                    $chain[] = is_object($middleware) ? $middleware::class : 'closure';
                }
                $table[] = ['method' => $method, 'path' => $route['path'], 'middleware' => $chain];
            }
        }

        return $table;
    }

    /**
     * The composed POST table as `path literal => middleware class names`.
     *
     * Keyed by the route's own `path` string so membership is an exact-equality
     * fact. `Router::getRoutes()` keys by compiled REGEX, which is why the map is
     * rebuilt here rather than read off directly.
     *
     * @return array<string, list<string>>
     */
    private static function postPathMap(Router $router): array
    {
        $map = [];
        foreach (self::tableOf($router) as $route) {
            if ($route['method'] !== 'POST') {
                continue;
            }
            $map[$route['path']] = $route['middleware'];
        }

        return $map;
    }
}
