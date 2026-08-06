<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;

use function array_diff;
use function array_keys;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_file;
use function is_string;
use function ksort;
use function preg_match;
use function preg_split;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * S66 — pin `openapi.yaml` to the route table `Application::registerRoutes()`
 * actually composes.
 *
 * ## Why this test exists
 *
 * A hand-written API description drifts from its subject the moment either side
 * changes, and by default nothing notices: the specification and the router are
 * two artefacts, and no suite compares them. That is the same defect family as
 * S204, where the hub registered `/servers/{id}/subdomain` at the router root
 * while its own controller docblock, `phlix-docs` and phlix-server all named
 * `/api/v1/servers/{id}/subdomain` — every gate in both repositories green while
 * the endpoint 404'd in production.
 *
 * So `openapi.yaml` is not decoration here. `paths` must be an EXACT bijection
 * with the composed router, and each operation must declare the middleware chain
 * the router really holds. Add, move, rename or re-gate a route without editing
 * the specification and this test names the difference.
 *
 * ## The specification is NOT generated
 *
 * `openapi.yaml` is a committed, hand-maintained file. It is deliberately not
 * regenerated from the router in CI: a description derived from its own subject
 * self-adjusts with it and could never go red, which would make this test a
 * decoration too. The generator used to author the first cut was a throwaway.
 *
 * ## The YAML is read with a deliberately narrow extractor
 *
 * CI installs `json, pcntl, posix, swoole` and no YAML extension, and the repo
 * has no YAML library, so this reads the file line-wise against a fixed layout
 * (path keys at two spaces, operations at four, extensions at six). The
 * extractor FAILS rather than returning less when the layout does not hold: a
 * reader that quietly finds nothing would turn both directions of the
 * comparison into a vacuous pass, which is the failure mode the whole test
 * exists to prevent.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class OpenApiSpecMatchesRouterTest extends RouteRegistrationTestCase
{
    /**
     * Floor on the size of the surface, so a silently-empty extraction (or a
     * silently-empty router build) reports as "nothing was measured" rather than
     * as a pass. Deliberately a floor and not the exact figure: the exact figure
     * is what the bijection below already asserts, route by route.
     */
    private const MINIMUM_OPERATIONS = 100;

    /**
     * HTTP verbs a `paths` item may key an operation under.
     *
     * @var list<string>
     */
    private const HTTP_VERBS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * Every route the production registrar composes must be documented, under
     * the router's own template.
     */
    public function testEveryRegisteredRouteIsDocumented(): void
    {
        $router = $this->routerKeys();
        $spec = array_keys($this->specOperations());

        $undocumented = array_diff($router, $spec);

        self::assertSame(
            [],
            $undocumented,
            sprintf(
                "openapi.yaml does not document %d route(s) the hub actually serves:\n  %s\n"
                . 'Add them to `paths` (and give the operation an `x-phlix-middleware` line). If a '
                . 'route template contains a `{name:regex}` constraint, write the OpenAPI path as '
                . '`{name}` and add `x-phlix-route` with the router template verbatim.',
                count($undocumented),
                implode("\n  ", $undocumented),
            ),
        );
    }

    /**
     * ...and nothing may be documented that the hub does not serve. This is the
     * direction S204 needed: a path written in a document but never registered
     * answers 404 for every caller that believes the document.
     */
    public function testEveryDocumentedOperationIsRegistered(): void
    {
        $router = $this->routerKeys();
        $spec = array_keys($this->specOperations());

        $phantom = array_diff($spec, $router);

        self::assertSame(
            [],
            $phantom,
            sprintf(
                "openapi.yaml documents %d operation(s) the hub does not register — a caller that "
                . "believes the specification gets a 404:\n  %s",
                count($phantom),
                implode("\n  ", $phantom),
            ),
        );
    }

    /**
     * Each documented operation must declare the middleware chain the router
     * really holds for it, in order. Catches a route that stays present but
     * quietly loses (or gains) its auth or admin gate.
     */
    public function testDocumentedMiddlewareChainsMatchTheRouter(): void
    {
        $actual = $this->routerMiddleware();
        $documented = $this->specOperations();

        $mismatches = [];
        foreach ($documented as $key => $chain) {
            if (!isset($actual[$key])) {
                continue; // reported by testEveryDocumentedOperationIsRegistered
            }
            if ($actual[$key] !== $chain) {
                $mismatches[] = sprintf(
                    '%s — router has [%s], openapi.yaml says [%s]',
                    $key,
                    implode(', ', $actual[$key]),
                    implode(', ', $chain),
                );
            }
        }

        self::assertSame(
            [],
            $mismatches,
            "x-phlix-middleware disagrees with the composed route table:\n  " . implode("\n  ", $mismatches),
        );
    }

    /**
     * The WebSocket surfaces are not `paths` (the `:8802` tunnel mounts at the
     * bare root, whose path key `/` the `:8800` SPA redirect already owns), so
     * they cannot ride the bijection above. Pin the one fact that goes stale
     * silently instead: the port each surface is documented on.
     *
     * The phlix-server surface is not asserted — this repository's CI clones one
     * checkout, and a conditional assertion here would be the skipping-test lie
     * `scripts/assert-integration-tests-ran.php` exists to catch.
     */
    public function testHubWebSocketPortsMatchTheSource(): void
    {
        $documented = $this->specWebSocketPorts();

        $expected = [
            'Server relay tunnel' => $this->relayPortDefaultFromApplication(),
            'Client mount' => ClientRelayWorker::DEFAULT_PORT,
            'SyncPlay relay' => SyncPlayRelayWorker::DEFAULT_PORT,
            'Hub federation' => FederationWorker::DEFAULT_PORT,
        ];

        foreach ($expected as $surface => $port) {
            self::assertArrayHasKey(
                $surface,
                $documented,
                sprintf('openapi.yaml no longer describes the "%s" WebSocket surface.', $surface),
            );
            self::assertSame(
                $port,
                $documented[$surface],
                sprintf(
                    'openapi.yaml documents the "%s" WebSocket surface on port %d, but the source says %d.',
                    $surface,
                    $documented[$surface],
                    $port,
                ),
            );
        }
    }

    /**
     * The specification must also still describe phlix-server's socket, which is
     * the half of S66's acceptance criteria this repository cannot verify.
     */
    public function testTheCrossRepositoryWebSocketSurfaceIsStillDescribed(): void
    {
        self::assertArrayHasKey(
            'Media server events and SyncPlay',
            $this->specWebSocketPorts(),
            'openapi.yaml no longer describes phlix-server\'s :8097 WebSocket surface.',
        );
    }

    // -----------------------------------------------------------------------
    // Router side
    // -----------------------------------------------------------------------

    /**
     * `"METHOD path"` for every route the real composition registers.
     *
     * @return list<string>
     */
    private function routerKeys(): array
    {
        return array_keys($this->routerMiddleware());
    }

    /**
     * `"METHOD path"` => middleware class short names, in registration order.
     *
     * @return array<string, list<string>>
     */
    private function routerMiddleware(): array
    {
        $router = $this->runRegistrar('registerRoutes');

        $table = [];
        foreach ($router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $chain = [];
                foreach ($route['middleware'] as $middleware) {
                    $chain[] = (new \ReflectionClass($middleware))->getShortName();
                }
                $table[$method . ' ' . $route['path']] = $chain;
            }
        }
        ksort($table);

        return $table;
    }

    /**
     * The `relay_port` fallback is a literal in `Application::run()` rather than
     * a class constant, so read it out of the production source the way
     * `scripts/assert-cross-repo-hub-paths.php` reads path literals. Restating
     * `8802` here would be a constant checking itself.
     */
    private function relayPortDefaultFromApplication(): int
    {
        $source = $this->readOrFail(dirname(__DIR__, 4) . '/src/Application.php');

        $matched = preg_match(
            '/\$this->config\[\'relay_port\'\]\s*\?\?\s*(\d+)/',
            $source,
            $matches,
        );

        self::assertSame(
            1,
            $matched,
            'the `relay_port` default could not be read out of src/Application.php, so the '
            . 'documented :8802 surface could not be checked against anything. Fix the pattern '
            . 'deliberately rather than hard-coding the port here.',
        );

        return (int) $matches[1];
    }

    // -----------------------------------------------------------------------
    // Specification side
    // -----------------------------------------------------------------------

    /**
     * `"METHOD path"` => the operation's declared middleware chain, read out of
     * `openapi.yaml`. The key uses `x-phlix-route` when present (the router
     * template with its `{name:regex}` constraint, which OpenAPI cannot spell)
     * and the OpenAPI path otherwise — so the comparison is against the router's
     * own literal, never against a normalised approximation of it.
     *
     * @return array<string, list<string>>
     */
    private function specOperations(): array
    {
        $lines = $this->specLines();

        $operations = [];
        $inPaths = false;
        $path = null;
        $routerPath = null;
        $verb = null;
        $sawMiddleware = true;
        $operationsForPath = 0;

        foreach ($lines as $number => $line) {
            if ($line === 'paths:') {
                $inPaths = true;
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            // A new top-level key ends the paths section.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*:/', $line) === 1) {
                break;
            }

            if (preg_match('/^  "(\/[^"]*)":$/', $line, $m) === 1) {
                if ($path !== null) {
                    self::assertGreaterThan(
                        0,
                        $operationsForPath,
                        sprintf('openapi.yaml path "%s" declares no operations.', $path),
                    );
                }
                self::assertTrue($sawMiddleware, $this->missingMiddlewareMessage($verb, $path));
                $path = $m[1];
                $routerPath = null;
                $verb = null;
                $operationsForPath = 0;
                continue;
            }

            if (preg_match('/^    x-phlix-route: "(.+)"$/', $line, $m) === 1) {
                self::assertNotNull(
                    $path,
                    sprintf('openapi.yaml line %d: x-phlix-route outside a path item.', $number + 1),
                );
                $routerPath = $m[1];
                continue;
            }

            if (preg_match('/^    ([a-z]+):$/', $line, $m) === 1 && in_array($m[1], self::HTTP_VERBS, true)) {
                self::assertTrue($sawMiddleware, $this->missingMiddlewareMessage($verb, $path));
                self::assertNotNull(
                    $path,
                    sprintf('openapi.yaml line %d: operation outside a path item.', $number + 1),
                );
                $verb = $m[1];
                $sawMiddleware = false;
                ++$operationsForPath;
                continue;
            }

            if (preg_match('/^      x-phlix-middleware: \[(.*)\]$/', $line, $m) === 1) {
                self::assertNotNull(
                    $verb,
                    sprintf('openapi.yaml line %d: x-phlix-middleware outside an operation.', $number + 1),
                );
                /** @var string $path */
                $chain = [];
                foreach (explode(',', $m[1]) as $piece) {
                    $piece = trim($piece);
                    if ($piece !== '') {
                        $chain[] = $piece;
                    }
                }
                $operations[strtoupper((string) $verb) . ' ' . ($routerPath ?? $path)] = $chain;
                $sawMiddleware = true;
            }
        }

        self::assertTrue($inPaths, 'openapi.yaml has no top-level `paths:` key — nothing was compared.');
        self::assertTrue($sawMiddleware, $this->missingMiddlewareMessage($verb, $path));
        self::assertGreaterThanOrEqual(
            self::MINIMUM_OPERATIONS,
            count($operations),
            sprintf(
                'only %d operation(s) could be read out of openapi.yaml, which is below the %d floor. '
                . 'Either the hub lost most of its API or the layout this extractor reads has changed; '
                . 'either way nothing meaningful was compared.',
                count($operations),
                self::MINIMUM_OPERATIONS,
            ),
        );

        ksort($operations);

        return $operations;
    }

    /**
     * WebSocket surface name => documented port, read from `x-phlix-websockets`.
     *
     * @return array<string, int>
     */
    private function specWebSocketPorts(): array
    {
        $lines = $this->specLines();

        $ports = [];
        $name = null;
        $inSurfaces = false;

        foreach ($lines as $line) {
            if ($line === 'x-phlix-websockets:') {
                $inSurfaces = true;
                continue;
            }
            if (!$inSurfaces) {
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*:/', $line) === 1) {
                break;
            }
            if (preg_match('/^  - name: "(.+)"$/', $line, $m) === 1) {
                $name = $m[1];
                continue;
            }
            if ($name !== null && preg_match('/^    port: (\d+)$/', $line, $m) === 1) {
                $ports[$name] = (int) $m[1];
            }
        }

        self::assertTrue(
            $inSurfaces,
            'openapi.yaml has no top-level `x-phlix-websockets:` key, so the WebSocket surfaces '
            . 'S66 documents are gone and nothing about them was checked.',
        );
        self::assertNotSame([], $ports, 'no WebSocket surface could be read out of openapi.yaml.');

        return $ports;
    }

    /**
     * @return list<string>
     */
    private function specLines(): array
    {
        $source = $this->readOrFail(dirname(__DIR__, 4) . '/openapi.yaml');
        $lines = preg_split('/\r\n|\n/', $source);
        self::assertIsArray($lines, 'openapi.yaml could not be split into lines.');

        /** @var list<string> $lines */
        return $lines;
    }

    /**
     * Read a required file, failing (never skipping, never returning empty) when
     * it is absent.
     */
    private function readOrFail(string $path): string
    {
        self::assertTrue(
            is_file($path),
            sprintf('%s does not exist, so nothing could be compared against it.', $path),
        );

        $source = file_get_contents($path);
        self::assertTrue(is_string($source) && $source !== '', sprintf('%s could not be read.', $path));

        /** @var string $source */
        return $source;
    }

    private function missingMiddlewareMessage(?string $verb, ?string $path): string
    {
        return sprintf(
            'openapi.yaml operation `%s %s` has no `x-phlix-middleware` line, so its gate could not '
            . 'be compared with the router. Every operation must declare one (`[]` for a route that '
            . 'is deliberately ungated).',
            strtoupper($verb ?? '?'),
            $path ?? '?',
        );
    }
}
