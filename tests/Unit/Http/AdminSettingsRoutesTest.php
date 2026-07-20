<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Controllers\HubSettingsController;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Contract tests for the shared-admin-console settings routes wired by
 * {@see \Phlix\Hub\Application::registerAdminSettingsRoutes()} (hubby.md H1.2).
 *
 * These verify the two `/api/v1/admin/settings` paths the redesigned
 * `@phlix/ui` admin Settings page calls dispatch to the right
 * {@see HubSettingsController} method and are gated `[auth, admin]` (401 with
 * no user, 403 for a non-admin, 200 for an admin). The routes are registered
 * onto a fresh {@see Router} with the same group/middleware shape Application
 * uses, exercising the *real* AdminMiddleware, HubSettingsController, and
 * HubSettingsRepository over a mocked {@see Connection} and a temp config-dir
 * fixture so {@see HubSettingsRepository::getDefault()} resolves every
 * allow-listed key.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 *
 * @covers \Phlix\Hub\Http\Controllers\HubSettingsController
 * @covers \Phlix\Hub\Http\Middleware\AdminMiddleware
 */
final class AdminSettingsRoutesTest extends TestCase
{
    private string $configDir = '';

    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $users;

    private AdminMiddleware $admin;

    /** @var callable(Request): ?Response */
    private $auth;

    protected function setUp(): void
    {
        parent::setUp();

        // Temp config dir mirroring the shape of the real config/ files, so
        // getDefault() resolves every allow-listed key to a known default.
        $dir = sys_get_temp_dir() . '/phlix-hub-adminsettings-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        file_put_contents(
            $dir . '/server.php',
            "<?php\n\nreturn ['enrollment_ttl' => 3600];\n",
        );
        file_put_contents(
            $dir . '/auth.php',
            "<?php\n\nreturn ['access_ttl' => 900, 'refresh_ttl' => 1209600];\n",
        );
        $this->configDir = $dir;

        $this->users = $this->createMock(UserRepository::class);
        $audit = $this->createMock(AuditLogger::class);
        $this->admin = new AdminMiddleware($this->users, $audit);

        // Stand-in for AuthMiddleware: copy `X-Test-User` into Request::$userId
        // so the admin gate downstream sees the same populated field it would
        // in production. Keeps the test focused on the admin gate + path
        // mapping + controller envelope.
        $this->auth = static function (Request $request): ?Response {
            $header = $request->getHeader('X-Test-User');
            if ($header !== null && $header !== '') {
                $request->userId = $header;
            }
            return null;
        };
    }

    protected function tearDown(): void
    {
        if ($this->configDir !== '' && is_dir($this->configDir)) {
            foreach (glob($this->configDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->configDir);
        }
        parent::tearDown();
    }

    /**
     * Build a fresh Router with the admin-settings group wired exactly the
     * way {@see \Phlix\Hub\Application::registerAdminSettingsRoutes()} does,
     * over a controller backed by the given (per-case programmed) Connection.
     */
    private function buildRouter(Connection $db): Router
    {
        $repo = new HubSettingsRepository($db, $this->configDir);
        $controller = new HubSettingsController($repo);

        $auth = $this->auth;
        $admin = $this->admin;

        $router = new Router();
        $router->group('/api/v1/admin/settings', static function (Router $r) use ($controller): void {
            $r->get('', static fn (Request $req): Response => $controller->getSettings($req));
            $r->put('', static fn (Request $req): Response => $controller->putSettings($req));
        }, [$auth, $admin]);

        return $router;
    }

    /** Build a GET request, optionally authenticated as a given user id. */
    private function get(string $path, ?string $userId = null): Request
    {
        $req = new Request();
        $req->method = 'GET';
        $req->path = $path;
        if ($userId !== null) {
            $req->headers['x-test-user'] = $userId;
        }
        return $req;
    }

    /**
     * Build a PUT request, optionally authenticated as a given user id.
     *
     * @param array<string, mixed> $body
     */
    private function put(string $path, array $body, ?string $userId = null): Request
    {
        $req = new Request();
        $req->method = 'PUT';
        $req->path = $path;
        $req->body = $body;
        if ($userId !== null) {
            $req->headers['x-test-user'] = $userId;
        }
        return $req;
    }

    /** Configure the mocked repo so `$userId` is (not) an admin. */
    private function asAdmin(string $userId, bool $isAdmin): void
    {
        $this->users->method('findAdminById')
            ->willReturn($isAdmin ? ['id' => $userId, 'is_admin' => 1] : null);
    }

    public function testGetRequires401WhenUnauthenticated(): void
    {
        $db = $this->createMock(Connection::class);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/settings'));
        self::assertSame(401, $res->statusCode);
    }

    public function testGetRequires403WhenNotAdmin(): void
    {
        $this->asAdmin('u-plain', false);
        $db = $this->createMock(Connection::class);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/settings', 'u-plain'));
        self::assertSame(403, $res->statusCode);
    }

    public function testGetReturnsSettingsEnvelopeForAdmin(): void
    {
        $this->asAdmin('admin-1', true);

        $db = $this->createMock(Connection::class);
        // getSettings -> getEffectiveMany -> getAllOverrides: one no-param
        // SELECT. No overrides -> all eight keys resolve to fixture defaults.
        $db->method('query')->willReturnCallback(function (string $sql): array {
            self::assertStringContainsString('SELECT setting_key', $sql);
            return [];
        });
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/settings', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(true, $payload['success'] ?? null);

        self::assertArrayHasKey('data', $payload);
        $data = $payload['data'];
        self::assertIsArray($data);

        self::assertArrayHasKey('settings', $data);
        $settings = $data['settings'];
        self::assertIsArray($settings);
        // The envelope must expose exactly the allow-list — no more (a key the
        // UI cannot render), no fewer (a key the UI renders but PUT rejects).
        self::assertSame(
            array_keys(HubSettingsRepository::ALLOWED_KEYS),
            array_keys($settings),
        );
        self::assertSame(3600, $settings['server.enrollment_ttl']);
        self::assertSame(900, $settings['auth.access_ttl']);
        self::assertSame(1209600, $settings['auth.refresh_ttl']);

        // Every value must resolve — a null here means the dotted key names a
        // config path that does not exist (the Phase 6 orphaned-key defect).
        foreach ($settings as $key => $value) {
            self::assertNotNull($value, "setting '{$key}' resolved to null");
        }

        self::assertArrayHasKey('overridden', $data);
        self::assertSame([], $data['overridden']);

        self::assertArrayHasKey('types', $data);
        $types = $data['types'];
        self::assertIsArray($types);
        self::assertSame(HubSettingsRepository::ALLOWED_KEYS, $types);
    }

    public function testPutRequires401WhenUnauthenticated(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->put('/api/v1/admin/settings', ['settings' => ['server.enrollment_ttl' => 7200]]),
        );
        self::assertSame(401, $res->statusCode);
    }

    public function testPutRequires403WhenNotAdmin(): void
    {
        $this->asAdmin('u-plain', false);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->put('/api/v1/admin/settings', ['settings' => ['server.enrollment_ttl' => 7200]], 'u-plain'),
        );
        self::assertSame(403, $res->statusCode);
    }

    public function testPutPersistsAndReturnsEffectiveEnvelopeForAdmin(): void
    {
        $this->asAdmin('admin-1', true);

        $db = $this->createMock(Connection::class);
        // INSERT (set) returns true; the follow-up no-param SELECT
        // (getAllOverrides) returns the override row just written.
        $db->method('query')->willReturnCallback(function (string $sql): array|bool {
            if (str_contains($sql, 'INSERT INTO hub_settings')) {
                return true;
            }
            self::assertStringContainsString('SELECT setting_key', $sql);
            return [[
                'setting_key' => 'server.enrollment_ttl',
                'setting_value' => '7200',
                'value_type' => 'int',
            ]];
        });
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->put('/api/v1/admin/settings', ['settings' => ['server.enrollment_ttl' => 7200]], 'admin-1'),
        );
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(true, $payload['success'] ?? null);

        self::assertArrayHasKey('data', $payload);
        $data = $payload['data'];
        self::assertIsArray($data);

        self::assertArrayHasKey('settings', $data);
        $settings = $data['settings'];
        self::assertIsArray($settings);
        self::assertSame(7200, $settings['server.enrollment_ttl']);

        self::assertArrayHasKey('overridden', $data);
        self::assertSame(['server.enrollment_ttl'], $data['overridden']);

        // PUT must NOT echo the `types` map (only GET returns it).
        self::assertArrayNotHasKey('types', $data);
    }

    public function testPutPersistsMultipleKeysForAdmin(): void
    {
        $this->asAdmin('admin-1', true);

        $db = $this->createMock(Connection::class);
        // Two INSERTs then one SELECT echoing both overrides.
        $db->method('query')->willReturnCallback(function (string $sql): array|bool {
            if (str_contains($sql, 'INSERT INTO hub_settings')) {
                return true;
            }
            self::assertStringContainsString('SELECT setting_key', $sql);
            return [
                [
                    'setting_key' => 'auth.access_ttl',
                    'setting_value' => '1800',
                    'value_type' => 'int',
                ],
                [
                    'setting_key' => 'auth.refresh_ttl',
                    'setting_value' => '43200',
                    'value_type' => 'int',
                ],
            ];
        });
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->put(
            '/api/v1/admin/settings',
            ['settings' => ['auth.access_ttl' => 1800, 'auth.refresh_ttl' => 43200]],
            'admin-1',
        ));
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(true, $payload['success'] ?? null);

        self::assertArrayHasKey('data', $payload);
        $data = $payload['data'];
        self::assertIsArray($data);

        self::assertArrayHasKey('settings', $data);
        $settings = $data['settings'];
        self::assertIsArray($settings);
        self::assertSame(1800, $settings['auth.access_ttl']);
        self::assertSame(43200, $settings['auth.refresh_ttl']);

        self::assertArrayHasKey('overridden', $data);
        $overridden = $data['overridden'];
        self::assertIsArray($overridden);
        self::assertContains('auth.access_ttl', $overridden);
        self::assertContains('auth.refresh_ttl', $overridden);
    }

    /**
     * The hub PUT must emit the SERVER's validation-error shape:
     * `{success:false, error:'Validation failed', errors:{key: message}}`.
     *
     * `phlix-ui`'s shared admin SettingsPage reads `e.body.errors` to paint
     * inline per-field messages (`SettingsPage.vue:288-291`). The hub used to
     * return a single `error` code with no `errors` map, so hub admins got a
     * generic toast and no highlighted field.
     */
    public function testPutRejectsUnknownKeyWithAnErrorsMap(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->createMock(Connection::class);
        // Validation fails before any persist -> query is never reached.
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->put('/api/v1/admin/settings', ['settings' => ['bogus.key' => 1]], 'admin-1'),
        );
        self::assertSame(400, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(false, $payload['success'] ?? null);
        self::assertSame('Validation failed', $payload['error'] ?? null);
        self::assertIsArray($payload['errors'] ?? null);
        self::assertArrayHasKey('bogus.key', $payload['errors']);
    }

    public function testPutRejectsWrongTypeWithAnErrorsMap(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->put('/api/v1/admin/settings', ['settings' => ['server.enrollment_ttl' => 'not-an-int']], 'admin-1'),
        );
        self::assertSame(400, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(false, $payload['success'] ?? null);
        self::assertSame('Validation failed', $payload['error'] ?? null);
        self::assertIsArray($payload['errors'] ?? null);
        self::assertArrayHasKey('server.enrollment_ttl', $payload['errors']);
        self::assertStringContainsString('int', (string) $payload['errors']['server.enrollment_ttl']);
    }

    /**
     * All failures must be reported at once, not one per round-trip — matching
     * the server, which accumulates before returning.
     */
    public function testPutAccumulatesEveryValidationErrorInOneResponse(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->put('/api/v1/admin/settings', [
            'settings' => [
                'bogus.key'             => 1,
                'server.enrollment_ttl' => 'not-an-int',
                'auth.access_ttl'       => [],
            ],
        ], 'admin-1'));
        self::assertSame(400, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['errors'] ?? null);
        self::assertSame(
            ['bogus.key', 'server.enrollment_ttl', 'auth.access_ttl'],
            array_keys($payload['errors']),
            'every bad key must be reported, not just the first',
        );
    }

    public function testPutRejectsNonArrayBody(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->put('/api/v1/admin/settings', [], 'admin-1'));
        self::assertSame(400, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(false, $payload['success'] ?? null);
        self::assertSame('Invalid payload', $payload['error'] ?? null);
    }
}
