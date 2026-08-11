<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Http\Controllers\AdminUpdatesController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckService;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckWorker;
use Phlix\Hub\Hub\Updates\VersionMarkerFetcherInterface;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Router;
use Phlix\Hub\Tests\Support\InMemoryHubSettingsConnection;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * S75 acceptance criterion #1, end to end: **the status endpoint correctly
 * reports "update available" against a seeded newer `VERSION` marker.**
 *
 * Everything here is production code except the marker fetcher (which is the
 * only thing that would otherwise reach the network) and the DB socket:
 *
 *  - the routes come from the REAL private
 *    {@see Application::registerAdminUpdatesRoutes()}, reflection-invoked, so a
 *    deleted route line 404s here rather than passing quietly (S41/S174: a
 *    suite that re-declares its own route table is blind to the registrar);
 *  - the gate is the real {@see AuthMiddleware} over a real {@see JwtHandler}
 *    minting real HS256 tokens, plus the real {@see AdminMiddleware};
 *  - the check that seeds the marker is run by the REAL
 *    {@see CoreUpdateCheckWorker}/{@see CoreUpdateCheckService} against the
 *    same `hub_settings` store the endpoint reads, so "the check wrote it" and
 *    "the endpoint reads it" cannot both be true of two different stores.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 */
final class AdminUpdatesRouteRegistrationTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

    /** A >=32-byte HS256 secret so the real JwtHandler accepts it. */
    private const JWT_SECRET = 'S75-admin-updates-route-secret-key-0123456789';

    private const STATUS_PATH = '/api/v1/admin/updates/status';

    private const SETTINGS_PATH = '/api/v1/admin/updates/settings';

    private const CURRENT_VERSION = '0.5.0';

    private const UPDATE_COMMAND = 'curl -fsSL https://example.invalid/install.sh | sudo bash -s -- --update -y';

    private string $tmpDir = '';

    private InMemoryHubSettingsConnection $db;

    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $users;

    /** @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject */
    private AuditLogger $audit;

    private JwtHandler $jwt;

    private Router $router;

    /** Marker body the fake fetcher will answer with. */
    private string $marker = '0.5.0';

    protected function setUp(): void
    {
        parent::setUp();

        AuthMiddleware::resetCache();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-updates-route-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        file_put_contents($this->tmpDir . '/updates.php', "<?php\n\nreturn ['check_enabled' => true];\n");
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        $this->db    = new InMemoryHubSettingsConnection();
        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);
        $this->jwt   = new JwtHandler(self::JWT_SECRET);

        $this->router = $this->buildProductionRouter();
    }

    protected function tearDown(): void
    {
        AuthMiddleware::resetCache();
        LoggerFactory::reset();
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** The service under test, over the shared in-memory `hub_settings` store. */
    private function service(): CoreUpdateCheckService
    {
        $marker  = &$this->marker;
        $fetcher = new class ($marker) implements VersionMarkerFetcherInterface {
            /** @param string $marker Live reference to the test's marker body. */
            public function __construct(private string &$marker)
            {
            }

            public function fetch(string $url, callable $onDone): void
            {
                $onDone($this->marker, null);
            }
        };

        return new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $fetcher,
            LoggerFactory::get('hub'),
            'https://example.invalid/VERSION',
            self::UPDATE_COMMAND,
            self::CURRENT_VERSION,
        );
    }

    /**
     * Run the REAL {@see Application::registerAdminUpdatesRoutes()} against a
     * fresh Router. Nothing about the route table is restated here.
     */
    private function buildProductionRouter(): Router
    {
        $controller = new AdminUpdatesController($this->service(), $this->users, $this->audit);
        $auth       = new AuthMiddleware($this->jwt, $this->users);
        $admin      = new AdminMiddleware($this->users, $this->audit);

        $container = new class ($controller, $auth, $admin) implements ContainerInterface {
            public function __construct(
                private readonly AdminUpdatesController $controller,
                private readonly AuthMiddleware $auth,
                private readonly AdminMiddleware $admin,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    AdminUpdatesController::class => $this->controller,
                    AuthMiddleware::class         => $this->auth,
                    AdminMiddleware::class        => $this->admin,
                    default                       => throw new \RuntimeException("unexpected service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array(
                    $id,
                    [AdminUpdatesController::class, AuthMiddleware::class, AdminMiddleware::class],
                    true,
                );
            }
        };

        $router     = new Router();
        $reflection = new ReflectionClass(Application::class);
        $app        = $reflection->newInstanceWithoutConstructor();

        $routerProp = $reflection->getProperty('router');
        $routerProp->setAccessible(true);
        $routerProp->setValue($app, $router);

        $containerProp = $reflection->getProperty('container');
        $containerProp->setAccessible(true);
        $containerProp->setValue($app, $container);

        $register = new ReflectionMethod(Application::class, 'registerAdminUpdatesRoutes');
        $register->setAccessible(true);
        $register->invoke($app);

        return $router;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, ?string $token = null, array $body = []): Request
    {
        $request         = new Request();
        $request->method = $method;
        $request->path   = $path;
        $request->body   = $body;
        if ($token !== null) {
            $request->headers['authorization'] = 'Bearer ' . $token;
            $request->bearerToken              = $token;
        }

        return $request;
    }

    private function adminToken(string $userId): string
    {
        $this->users->method('userExists')->willReturn(true);
        $this->users->method('findAdminById')->willReturn(['id' => $userId, 'is_admin' => 1]);

        return $this->jwt->createAccessToken($userId);
    }

    private function nonAdminToken(string $userId): string
    {
        $this->users->method('userExists')->willReturn(true);
        $this->users->method('findAdminById')->willReturn(null);

        return $this->jwt->createAccessToken($userId);
    }

    /**
     * Build the request the way the WIRE does: raw HTTP bytes → Workerman's
     * parser → {@see Request::fromWorkerman()}.
     *
     * WHY THIS EXISTS ALONGSIDE {@see request()} — a hand-assembled
     * `new Request()` with `$request->body = [...]` skips every boundary that
     * can silently disagree with the real client: `Content-Type`-conditional
     * JSON decoding (`Request.php:166-171` — a non-JSON content type falls
     * through to `$wr->post()`, where a JSON `false` would arrive as the STRING
     * `"false"` and the controller's `is_bool()` check would 400 forever),
     * header-name normalisation (S205: `collectHeadersFromWorkerman()`
     * uppercases, so a hand-set mixed-case literal misses), and bearer-token
     * extraction. The shared `@phlix/ui` `ApiClient` always sends
     * `Content-Type: application/json` + `JSON.stringify(data)`
     * (`phlix-ui/src/api/client.ts:530,543`), so these tests reproduce exactly
     * that and let the production parser produce `Request::$body`.
     *
     * @param array<string, mixed>|null $json Body to JSON-encode, or null for none.
     */
    private function wireRequest(
        string $method,
        string $path,
        ?string $token = null,
        ?array $json = null,
    ): Request {
        $body  = $json === null ? '' : (string) json_encode($json, JSON_THROW_ON_ERROR);
        $lines = [
            $method . ' ' . $path . ' HTTP/1.1',
            'Host: hub.example.com',
        ];
        if ($token !== null) {
            $lines[] = 'Authorization: Bearer ' . $token;
        }
        if ($json !== null) {
            $lines[] = 'Content-Type: application/json';
            $lines[] = 'Content-Length: ' . strlen($body);
        }

        $raw = implode("\r\n", $lines) . "\r\n\r\n" . $body;

        return Request::fromWorkerman(new WorkermanRequest($raw));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $payload = json_decode($body, true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * THE acceptance criterion. Seed a NEWER marker, let the real worker run
     * its boot check, then ask the registered status route.
     */
    public function testStatusReportsUpdateAvailableAgainstASeededNewerMarker(): void
    {
        $token = $this->adminToken('s75-admin');

        // Control: before any check, the very same endpoint says "no update".
        $before = $this->decode(
            (string) $this->router->dispatch($this->request('GET', self::STATUS_PATH, $token))->body,
        );
        self::assertIsArray($before['data']);
        self::assertFalse($before['data']['updateAvailable']);
        self::assertNull($before['data']['latestVersion']);

        // Seed a newer marker and run the REAL worker's boot catch-up.
        $this->marker = "0.9.9\n";
        (new CoreUpdateCheckWorker($this->service(), LoggerFactory::get('hub'), 86400))->tick();

        $response = $this->router->dispatch($this->request('GET', self::STATUS_PATH, $token));
        self::assertNotSame(
            404,
            $response->statusCode,
            'GET ' . self::STATUS_PATH . ' must be registered by Application::registerAdminUpdatesRoutes()',
        );
        self::assertSame(200, $response->statusCode);

        $payload = $this->decode((string) $response->body);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
        $data = $payload['data'];

        self::assertTrue($data['updateAvailable'], 'a newer VERSION marker must surface as an available update');
        self::assertSame('0.9.9', $data['latestVersion']);
        self::assertSame(self::CURRENT_VERSION, $data['currentVersion']);
        self::assertIsInt($data['lastCheckedAt']);
        self::assertNull($data['lastError']);
    }

    /**
     * The mirror image: an identical marker must NOT nag the operator. Without
     * this, "reports update available" could be satisfied by a constant true.
     */
    public function testStatusReportsNoUpdateWhenTheMarkerMatchesTheCompiledVersion(): void
    {
        $token = $this->adminToken('s75-admin-same');

        $this->marker = self::CURRENT_VERSION;
        (new CoreUpdateCheckWorker($this->service(), LoggerFactory::get('hub'), 86400))->tick();

        $payload = $this->decode(
            (string) $this->router->dispatch($this->request('GET', self::STATUS_PATH, $token))->body,
        );
        self::assertIsArray($payload['data']);
        self::assertFalse($payload['data']['updateAvailable']);
        self::assertSame(self::CURRENT_VERSION, $payload['data']['latestVersion']);
    }

    /**
     * The status payload must carry the copy-to-clipboard command — the ONLY
     * update affordance S75 ships (no inline apply, ever).
     */
    public function testStatusCarriesTheCopyToClipboardUpdateCommand(): void
    {
        $token = $this->adminToken('s75-admin-cmd');

        $payload = $this->decode(
            (string) $this->router->dispatch($this->request('GET', self::STATUS_PATH, $token))->body,
        );
        self::assertIsArray($payload['data']);
        self::assertSame(self::UPDATE_COMMAND, $payload['data']['updateCommand']);
    }

    /** No apply/upgrade verb may exist on this surface. */
    public function testThereIsNoInlineApplyRoute(): void
    {
        $registered = [];
        foreach ($this->router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $registered[] = $method . ' ' . $route['path'];
            }
        }
        sort($registered);

        self::assertSame(
            [
                'GET /api/v1/admin/updates/status',
                'PUT /api/v1/admin/updates/settings',
            ],
            $registered,
            'registerAdminUpdatesRoutes() must register exactly the read + toggle routes — '
            . 'an apply/upgrade route would mean the hub runs git/composer/systemctl for a web caller',
        );
    }

    public function testStatusRequiresAuthentication(): void
    {
        $response = $this->router->dispatch($this->request('GET', self::STATUS_PATH));
        self::assertSame(401, $response->statusCode);
    }

    public function testStatusRefusesANonAdmin(): void
    {
        $token    = $this->nonAdminToken('s75-plain');
        $response = $this->router->dispatch($this->request('GET', self::STATUS_PATH, $token));
        self::assertSame(403, $response->statusCode);
    }

    public function testSettingsRequiresAuthentication(): void
    {
        $response = $this->router->dispatch(
            $this->request('PUT', self::SETTINGS_PATH, null, ['checkEnabled' => false]),
        );
        self::assertSame(401, $response->statusCode);
    }

    public function testSettingsRefusesANonAdmin(): void
    {
        $token    = $this->nonAdminToken('s75-plain-put');
        $response = $this->router->dispatch(
            $this->request('PUT', self::SETTINGS_PATH, $token, ['checkEnabled' => false]),
        );
        self::assertSame(403, $response->statusCode);
    }

    /**
     * The toggle must be registered, must persist, and must be visible on the
     * status endpoint afterwards.
     */
    public function testSettingsTogglePersistsAndIsReflectedByTheStatusEndpoint(): void
    {
        $token = $this->adminToken('s75-admin-toggle');

        $response = $this->router->dispatch(
            $this->request('PUT', self::SETTINGS_PATH, $token, ['checkEnabled' => false]),
        );
        self::assertNotSame(
            404,
            $response->statusCode,
            'PUT ' . self::SETTINGS_PATH . ' must be registered by Application::registerAdminUpdatesRoutes()',
        );
        self::assertSame(200, $response->statusCode);

        $payload = $this->decode((string) $response->body);
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
        self::assertFalse($payload['data']['checkEnabled']);

        $status = $this->decode(
            (string) $this->router->dispatch($this->request('GET', self::STATUS_PATH, $token))->body,
        );
        self::assertIsArray($status['data']);
        self::assertFalse($status['data']['checkEnabled']);

        // And back on again.
        $this->router->dispatch($this->request('PUT', self::SETTINGS_PATH, $token, ['checkEnabled' => true]));
        $status = $this->decode(
            (string) $this->router->dispatch($this->request('GET', self::STATUS_PATH, $token))->body,
        );
        self::assertIsArray($status['data']);
        self::assertTrue($status['data']['checkEnabled']);
    }

    public function testSettingsRejectsAMissingField(): void
    {
        $token    = $this->adminToken('s75-admin-missing');
        $response = $this->router->dispatch($this->request('PUT', self::SETTINGS_PATH, $token, []));

        self::assertSame(400, $response->statusCode);
        $payload = $this->decode((string) $response->body);
        self::assertFalse($payload['success']);
        self::assertSame('invalid_payload', $payload['code']);
    }

    public function testSettingsRejectsANonBooleanValue(): void
    {
        $token    = $this->adminToken('s75-admin-bad-type');
        $response = $this->router->dispatch(
            $this->request('PUT', self::SETTINGS_PATH, $token, ['checkEnabled' => 'yes']),
        );

        self::assertSame(400, $response->statusCode);
        $payload = $this->decode((string) $response->body);
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('checkEnabled', $payload['errors']);
    }

    // ------------------------------------------------------- WIRE-SHAPE TESTS

    /**
     * The status endpoint, driven end-to-end from raw HTTP bytes through the
     * production parser — header normalisation, bearer extraction and all.
     */
    public function testStatusReportsUpdateAvailableOverTheRealWire(): void
    {
        $token = $this->adminToken('s75-wire-status');

        $this->marker = "0.9.9\n";
        (new CoreUpdateCheckWorker($this->service(), LoggerFactory::get('hub'), 86400))->tick();

        $response = $this->router->dispatch($this->wireRequest('GET', self::STATUS_PATH, $token));
        self::assertSame(200, $response->statusCode);

        $payload = $this->decode((string) $response->body);
        self::assertIsArray($payload['data']);
        self::assertTrue($payload['data']['updateAvailable']);
        self::assertSame('0.9.9', $payload['data']['latestVersion']);
    }

    /**
     * THE call-shape pin for the toggle: a JSON body exactly as the shared
     * `@phlix/ui` `ApiClient` sends it (`Content-Type: application/json` +
     * `JSON.stringify({checkEnabled: false})`), decoded by the production
     * {@see Request::fromWorkerman()} rather than hand-assigned.
     *
     * Without this, the controller's `is_bool()` requirement is only ever met
     * because the test itself put a real PHP `false` into `$request->body`.
     */
    public function testSettingsToggleAcceptsTheClientsRealJsonPayload(): void
    {
        $token = $this->adminToken('s75-wire-toggle');

        $response = $this->router->dispatch(
            $this->wireRequest('PUT', self::SETTINGS_PATH, $token, ['checkEnabled' => false]),
        );

        self::assertSame(
            200,
            $response->statusCode,
            'the toggle must accept the exact JSON payload @phlix/ui ApiClient sends; a 400 here '
            . 'means the controller reads a key or type the real client never produces',
        );

        $payload = $this->decode((string) $response->body);
        self::assertIsArray($payload['data']);
        self::assertFalse($payload['data']['checkEnabled']);

        // And it really persisted — read it back over the wire too.
        $status = $this->decode(
            (string) $this->router->dispatch($this->wireRequest('GET', self::STATUS_PATH, $token))->body,
        );
        self::assertIsArray($status['data']);
        self::assertFalse($status['data']['checkEnabled']);
    }

    /**
     * The response envelope the S76 banner will consume: `{success, data}` with
     * EXACTLY these camelCase keys.
     *
     * Pinned as an exact key set because the shared UI unwraps `{ success, data }`
     * and reads camelCase fields directly
     * (`phlix-ui/src/api/admin/hubDashboard.ts:102-113`). A renamed or dropped
     * key is invisible to a `assertTrue($data['updateAvailable'])` style test
     * on its own, but breaks the consumer.
     */
    public function testStatusEnvelopeCarriesExactlyTheContractedKeys(): void
    {
        $token = $this->adminToken('s75-wire-envelope');

        $payload = $this->decode(
            (string) $this->router->dispatch($this->wireRequest('GET', self::STATUS_PATH, $token))->body,
        );

        self::assertSame(['success', 'data'], array_keys($payload));
        self::assertIsArray($payload['data']);
        self::assertSame(
            [
                'currentVersion',
                'latestVersion',
                'updateAvailable',
                'checkEnabled',
                'lastCheckedAt',
                'lastError',
                'updateCommand',
            ],
            array_keys($payload['data']),
            'S76 consumes these camelCase keys from the {success, data} envelope; '
            . 'renaming or dropping one silently breaks the banner',
        );
    }

    /**
     * A form-encoded PUT (no `application/json`) must NOT be silently accepted
     * as a toggle: `Request::fromWorkerman()` routes it to `$wr->post()`, where
     * the value is the string `"false"`, and a controller that accepted that
     * would flip the setting to `true` (a non-empty string is truthy).
     */
    public function testAFormEncodedToggleIsRejectedRatherThanMisread(): void
    {
        $token = $this->adminToken('s75-wire-form');

        $raw = implode("\r\n", [
            'PUT ' . self::SETTINGS_PATH . ' HTTP/1.1',
            'Host: hub.example.com',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: 18',
        ]) . "\r\n\r\n" . 'checkEnabled=false';

        $response = $this->router->dispatch(Request::fromWorkerman(new WorkermanRequest($raw)));

        self::assertSame(
            400,
            $response->statusCode,
            'a string "false" must be refused, never coerced — silently storing true would '
            . 'turn "disable the update check" into "enable it"',
        );

        // WHICH branch fired matters: the key IS present (measured — the form
        // path yields ['checkEnabled' => 'false'], a non-empty and therefore
        // TRUTHY string), so this must be the TYPE rejection, not the
        // missing-field one. A 400 from the wrong branch would still be a 400
        // if the body had failed to parse at all.
        $payload = $this->decode((string) $response->body);
        self::assertSame('Validation failed', $payload['error'] ?? null);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('checkEnabled', $payload['errors']);
        self::assertStringContainsString('bool', (string) $payload['errors']['checkEnabled']);

        // And nothing was written: the effective value is still the default.
        $status = $this->decode(
            (string) $this->router->dispatch($this->wireRequest('GET', self::STATUS_PATH, $token))->body,
        );
        self::assertIsArray($status['data']);
        self::assertTrue($status['data']['checkEnabled']);
    }
}
