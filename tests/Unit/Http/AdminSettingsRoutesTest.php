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
 * fixture so {@see HubSettingsRepository::getDefault()} resolves the eight
 * allow-listed keys.
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

        // Temp config dir with three fixture files so getDefault() resolves
        // the eight allow-listed keys to known defaults.
        $dir = sys_get_temp_dir() . '/phlix-hub-adminsettings-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        file_put_contents(
            $dir . '/server.php',
            "<?php\n\nreturn ['enrollment_ttl' => 3600, 'relay_ping_interval' => 30,"
            . " 'max_servers_per_user' => 10, 'public_domain' => 'phlix.test'];\n",
        );
        file_put_contents(
            $dir . '/auth.php',
            "<?php\n\nreturn ['access_token_ttl' => 900, 'refresh_token_ttl' => 1209600];\n",
        );
        file_put_contents(
            $dir . '/logger.php',
            "<?php\n\nreturn ['level' => 'info', 'channels' => ['app', 'relay']];\n",
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
        self::assertArrayHasKey('server.enrollment_ttl', $settings);
        self::assertArrayHasKey('server.relay_ping_interval', $settings);
        self::assertArrayHasKey('server.max_servers_per_user', $settings);
        self::assertArrayHasKey('server.public_domain', $settings);
        self::assertArrayHasKey('auth.access_token_ttl', $settings);
        self::assertArrayHasKey('auth.refresh_token_ttl', $settings);
        self::assertArrayHasKey('logger.level', $settings);
        self::assertArrayHasKey('logger.channels', $settings);
        self::assertSame(3600, $settings['server.enrollment_ttl']);
        self::assertSame(['app', 'relay'], $settings['logger.channels']);

        self::assertArrayHasKey('overridden', $data);
        self::assertSame([], $data['overridden']);

        self::assertArrayHasKey('types', $data);
        $types = $data['types'];
        self::assertIsArray($types);
        self::assertArrayHasKey('server.enrollment_ttl', $types);
        self::assertArrayHasKey('server.relay_ping_interval', $types);
        self::assertArrayHasKey('server.max_servers_per_user', $types);
        self::assertArrayHasKey('server.public_domain', $types);
        self::assertArrayHasKey('auth.access_token_ttl', $types);
        self::assertArrayHasKey('auth.refresh_token_ttl', $types);
        self::assertArrayHasKey('logger.level', $types);
        self::assertArrayHasKey('logger.channels', $types);
        self::assertSame('json', $types['logger.channels']);
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

    public function testPutPersistsStringAndJsonTypesForAdmin(): void
    {
        $this->asAdmin('admin-1', true);

        $db = $this->createMock(Connection::class);
        // Two INSERTs (string + json) then one SELECT echoing both overrides.
        // The json override's setting_value is the JSON text decode() expects.
        $db->method('query')->willReturnCallback(function (string $sql): array|bool {
            if (str_contains($sql, 'INSERT INTO hub_settings')) {
                return true;
            }
            self::assertStringContainsString('SELECT setting_key', $sql);
            return [
                [
                    'setting_key' => 'server.public_domain',
                    'setting_value' => 'example.org',
                    'value_type' => 'string',
                ],
                [
                    'setting_key' => 'logger.channels',
                    'setting_value' => '["x","y"]',
                    'value_type' => 'json',
                ],
            ];
        });
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->put(
            '/api/v1/admin/settings',
            ['settings' => ['server.public_domain' => 'example.org', 'logger.channels' => ['x', 'y']]],
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
        self::assertSame('example.org', $settings['server.public_domain']);
        self::assertSame(['x', 'y'], $settings['logger.channels']);

        self::assertArrayHasKey('overridden', $data);
        $overridden = $data['overridden'];
        self::assertIsArray($overridden);
        self::assertContains('server.public_domain', $overridden);
        self::assertContains('logger.channels', $overridden);
    }

    public function testPutRejectsUnknownKey(): void
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
        self::assertSame('invalid_key', $payload['error'] ?? null);
    }

    public function testPutRejectsWrongTypeInt(): void
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
        self::assertSame('invalid_type', $payload['error'] ?? null);
    }

    public function testPutRejectsWrongTypeString(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->put('/api/v1/admin/settings', ['settings' => ['server.public_domain' => 123]], 'admin-1'),
        );
        self::assertSame(400, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(false, $payload['success'] ?? null);
        self::assertSame('invalid_type', $payload['error'] ?? null);
    }

    public function testPutRejectsWrongTypeJson(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->put('/api/v1/admin/settings', ['settings' => ['logger.channels' => 'not-an-array']], 'admin-1'),
        );
        self::assertSame(400, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(false, $payload['success'] ?? null);
        self::assertSame('invalid_type', $payload['error'] ?? null);
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
        self::assertSame('invalid_body', $payload['error'] ?? null);
    }
}
