<?php

/**
 * S332 — regenerate the vendored phlix-server route snapshot that drives the
 * self-maintaining S107 deny enumeration.
 *
 * The hub's deny list (`ServerProxyController::SCOPE_DENY_PATTERNS`) protects
 * every WRITE route that sits under an allowlisted READ prefix. S107 shipped
 * that list as a hand-written enumeration; S284 added `regenerate-assets` six
 * days later and the hole was invisible because the TESTS grew with the LIST,
 * not with the ROUTES. This script closes that decay mode at its source: it
 * boots phlix-server's two production registrars in-process and dumps the
 * routes the hub's relay dispatcher actually consults, into
 * `tests/Unit/Http/Controllers/Fixtures/phlix-server-route-manifest.json`.
 *
 * ## The route table that is dumped
 *
 * The exact pair `RelayRequestDispatcher::dispatch()` consults for a relayed
 * request (per the comments at `ServerProxyController.php:165-167`):
 *
 *  - `Phlix\Server\Core\Application::loadRoutes()`  (invoked from the
 *    `Application` constructor)
 *  - `Phlix\Server\WebPortal\WebPortalRouter::registerRoutes()`
 *
 * Both are read through `Router::getRoutes()` (`phlix-server
 * src/Server/Http/Router.php`), which merges the static and regex route maps.
 * The regex keys (`#^(?P<id>[^/]+)$#`) are normalised back to `{id}` templates
 * so the manifest reads like the route files that were written by hand.
 *
 * ## Cross-repo boundary and staleness
 *
 * phlix-hub's CI clones ONE repository, so it cannot boot phlix-server at test
 * time. The snapshot is therefore VENDORED and the test that consumes it pins
 * the recorded `source_sha` — when phlix-server master moves, that pin goes RED
 * and the fix is to re-run this script and commit the regenerated fixture in
 * the same commit. `--check` is the byte-identical comparison used by the
 * premerge ritual so the snapshot that MERGES is proven current against the
 * real server tree.
 *
 * ## Usage
 *
 * ```
 * php tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php [server-root] [--check]
 * ```
 *
 *  - `server-root` defaults to `<hub-repo>/../phlix-server`.
 *  - Without `--check`: writes the fixture in place (and prints the route
 *    count + sha256 + source sha).
 *  - With `--check`: regenerates to a temporary file and fails (exit 1) unless
 *    it is byte-identical to the committed fixture. Writes nothing.
 *
 * This script is deliberately not part of the hub's CI: CI has no phlix-server
 * checkout. It is run by a maintainer (or the premerge ritual) whenever the
 * server moves.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Router;
use Phlix\Server\WebPortal\WebPortalRouter;
use Workerman\MySQL\Connection;
use DI\ContainerBuilder;

use function DI\factory;

const FIXTURE_RELATIVE = 'tests/Unit/Http/Controllers/Fixtures/phlix-server-route-manifest.json';

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];

$check = false;
$serverRoot = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check') {
        $check = true;
        continue;
    }
    if ($serverRoot === null) {
        $serverRoot = $arg;
    }
}

// This generator lives NEXT to its fixture (in the tests tree, deliberately:
// it boots phlix-server classes that exist in neither this repository nor its
// dependencies, so it is outside the phpstan/psalm paths — its OUTPUT is what
// is statically pinned, by the sha256 asserted in ServerProxyControllerTest).
$hubRoot = dirname(__DIR__, 5);
$serverRoot ??= dirname($hubRoot) . '/phlix-server';

/**
 * @param non-empty-string $message
 */
$fail = static function (string $message): never {
    fwrite(STDERR, 'S332 route-manifest generator: ' . $message . "\n");
    exit(1);
};

if (!is_dir($serverRoot)) {
    $fail(sprintf('phlix-server was not found at %s. Pass the checkout explicitly.', $serverRoot));
}

if (!is_file($serverRoot . '/vendor/autoload.php')) {
    $fail(
        sprintf(
            '%s/vendor/autoload.php is missing — run composer install in the server checkout first.',
            $serverRoot,
        ),
    );
}

// This script runs STANDALONE from the hub checkout: it boots phlix-server, so
// it loads the SERVER's autoloader (the hub's own autoloader is not loaded and
// is not wanted — the hub does not even depend on Workerman\MySQL).
require $serverRoot . '/vendor/autoload.php';

$sourceSha = trim((string) shell_exec('git -C ' . escapeshellarg($serverRoot) . ' rev-parse HEAD 2>/dev/null'));
if ($sourceSha === '') {
    $fail(sprintf('could not read the phlix-server HEAD sha from %s (is it a git checkout?).', $serverRoot));
}

// The Application constructor needs a logger config path and a compile dir that
// it may write; point both at a throwaway temp directory rather than the
// server tree, and stub the database connection so boot never touches MySQL.
$tmp = sys_get_temp_dir() . '/phlix_s332_manifest_' . bin2hex(random_bytes(6));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    $fail('could not create a temporary boot directory in ' . sys_get_temp_dir());
}
$loggerConfigPath = $tmp . '/logger.php';
$loggerConfig = "<?php\nreturn [\n"
    . "    'default' => 'file',\n"
    . "    'handlers' => [\n"
    . "        'file' => [\n"
    . "            'type' => 'stream',\n"
    . "            'path' => " . var_export($tmp . '/app.log', true) . ",\n"
    . "            'level' => 'debug',\n"
    . "        ],\n"
    . "    ],\n"
    . "];\n";
file_put_contents($loggerConfigPath, $loggerConfig);

$connection = new class ('', 0, '', '', '') extends Connection {
    protected function connect(): void
    {
    }
};

$providers = ContainerFactory::defaultProviders();
$providers[] = new class ($connection) implements ServiceProviderInterface {
    public function __construct(private Connection $connection)
    {
    }

    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $connection = $this->connection;
        $builder->addDefinitions([Connection::class => factory(static fn (): Connection => $connection)]);
    }
};

$container = ContainerFactory::create([
    'logger_config_path' => $loggerConfigPath,
    'db_config_path' => null,
    'compile_dir' => $tmp . '/container',
], $providers);

$pool = new class ($connection) extends ConnectionPool {
    public function __construct(private Connection $connection)
    {
    }

    public function getPooledConnection(string $name = 'mysql'): Connection
    {
        return $this->connection;
    }
};

$app = new Application($container, [], $pool);

$appRouterProp = (new ReflectionClass(Application::class))->getProperty('router');
$appRouter = $appRouterProp->getValue($app);
if (!$appRouter instanceof Router) {
    $fail('Application::$router is not a Router — the server boot changed.');
}

$webRouter = $container->get(WebPortalRouter::class);
if (!$webRouter instanceof WebPortalRouter) {
    $fail('the container did not resolve a WebPortalRouter — the server boot changed.');
}
$webRouterProp = (new ReflectionClass(WebPortalRouter::class))->getProperty('router');
$webRouterRouter = $webRouterProp->getValue($webRouter);
if (!$webRouterRouter instanceof Router) {
    $fail('WebPortalRouter::$router is not a Router — the server boot changed.');
}

$merged = [];
foreach ([$appRouter, $webRouterRouter] as $router) {
    foreach ($router->getRoutes() as $method => $entries) {
        foreach (array_keys($entries) as $key) {
            $merged[$method][$key] = true;
        }
    }
}

/**
 * Convert a `Router` map key to the route template it was written from:
 * static paths stay literal, regex keys (`#^(?P<id>[^/]+)$#`) return to
 * `{id}`.
 */
$normalise = static function (string $key): string {
    if (!str_starts_with($key, '#')) {
        return $key;
    }

    $body = preg_replace('/^#\^/', '', $key) ?? $key;
    $body = preg_replace('/\$\#$/', '', $body) ?? $body;

    return preg_replace('/\(\?P<([a-zA-Z_]+)>\[\^\/\]\+\)/', '{$1}', $body) ?? $body;
};

$lines = [];
foreach ($merged as $method => $entries) {
    foreach (array_keys($entries) as $key) {
        $lines[] = $method . ' ' . $normalise($key);
    }
}
$lines = array_values(array_unique($lines));
sort($lines);

$routes = [];
foreach ($lines as $line) {
    $parts = explode(' ', $line, 2);
    if (count($parts) !== 2) {
        $fail(sprintf('could not split manifest line %s into METHOD + path.', $line));
    }
    $routes[] = ['method' => $parts[0], 'path' => $parts[1]];
}

$sha = hash('sha256', implode("\n", $lines));

$fixture = [
    'generator' => 'tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php',
    'source_repo' => 'detain/phlix-server',
    'source_sha' => $sourceSha,
    'route_count' => count($routes),
    'sha256' => $sha,
    'routes' => $routes,
];

$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    $fail('json_encode failed — the route list is not serialisable.');
}
$json .= "\n";

$fixturePath = $hubRoot . '/' . FIXTURE_RELATIVE;

if ($check) {
    $existing = is_file($fixturePath) ? (string) file_get_contents($fixturePath) : '';
    $denominator = count($routes);
    if ($existing === $json) {
        printf(
            "S332 route manifest CHECK OK: %d route(s), sha256=%s, source_sha=%s — byte-identical to %s\n",
            $denominator,
            $sha,
            $sourceSha,
            FIXTURE_RELATIVE,
        );
        exit(0);
    }

    fwrite(
        STDERR,
        sprintf(
            "S332 route manifest CHECK FAILED: regenerated %d route(s) (sha256=%s, source_sha=%s) differ from %s.\n"
            . 'The vendored snapshot is stale or was hand-edited. Commit the regenerated fixture and the new '
            . "S332_EXPECTED_SERVER_SOURCE_SHA pin in the same commit.\n",
            $denominator,
            $sha,
            $sourceSha,
            FIXTURE_RELATIVE,
        ),
    );
    exit(1);
}

if (file_put_contents($fixturePath, $json) === false) {
    $fail(sprintf('could not write %s.', $fixturePath));
}

printf(
    "WROTE %s: route_count=%d sha256=%s source_sha=%s\n",
    FIXTURE_RELATIVE,
    count($routes),
    $sha,
    $sourceSha,
);

exit(0);
