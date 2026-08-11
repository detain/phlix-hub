<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Controllers\LogController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the shared-admin-console log routes wired by
 * {@see \Phlix\Hub\Application::registerAdminLogRoutes()} (hubby.md H1.1).
 *
 * These verify the three `/api/v1/admin/logs*` paths the redesigned `@phlix/ui`
 * `AdminLogsApi` calls dispatch to the right {@see LogController} method and are
 * gated `[auth, admin]` (401 with no user, 403 for a non-admin, 200 for an
 * admin). The routes are registered onto a fresh {@see Router} with the same
 * group/middleware shape Application uses, exercising the *real* AdminMiddleware
 * and LogController against a temp log directory.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 */
final class AdminLogRoutesTest extends TestCase
{
    private string $logDir = '';

    private Router $router;

    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        // Temp log dir with a known fixture so index()/tail() have content.
        $dir = sys_get_temp_dir() . '/phlix-hub-adminlogs-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        file_put_contents(
            $dir . '/app.log',
            "[2026-06-04T10:00:00.000000+00:00] app.INFO: alpha\n"
            . "[2026-06-04T10:00:01.000000+00:00] app.INFO: beta\n",
        );
        $this->logDir = $dir;

        $this->users = $this->createMock(UserRepository::class);
        $audit = $this->createMock(AuditLogger::class);
        $admin = new AdminMiddleware($this->users, $audit);
        $controller = new LogController($dir);

        // Stand-in for AuthMiddleware: copy `X-Test-User` into Request::$userId
        // so the admin gate downstream sees the same populated field it would in
        // production. Keeps the test focused on the admin gate + path mapping.
        $auth = static function (Request $request): ?Response {
            $header = $request->getHeader('X-Test-User');
            if ($header !== null && $header !== '') {
                $request->userId = $header;
            }
            return null;
        };

        // Mirror Application::registerAdminLogRoutes() exactly.
        $router = new Router();
        $router->group('/api/v1/admin/logs', static function (Router $r) use ($controller): void {
            $r->get('', static fn (Request $req): Response => $controller->index($req));
            $r->get('/tail-all', static fn (Request $req): Response => $controller->tailAll($req));
            $r->get('/tail', static fn (Request $req): Response => $controller->tail($req));
        }, [$auth, $admin]);
        $this->router = $router;
    }

    protected function tearDown(): void
    {
        if ($this->logDir !== '' && is_dir($this->logDir)) {
            foreach (glob($this->logDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->logDir);
        }
        parent::tearDown();
    }

    /**
     * Build a GET request, optionally authenticated as a given user id.
     *
     * @param array<string, string> $query
     */
    private function get(string $path, ?string $userId = null, array $query = []): Request
    {
        $req = new Request();
        $req->method = 'GET';
        $req->path = $path;
        $req->query = $query;
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

    public function testListRequires401WhenUnauthenticated(): void
    {
        $res = $this->router->dispatch($this->get('/api/v1/admin/logs'));
        self::assertSame(401, $res->statusCode);
    }

    public function testListRequires403WhenNotAdmin(): void
    {
        $this->asAdmin('u-plain', false);
        $res = $this->router->dispatch($this->get('/api/v1/admin/logs', 'u-plain'));
        self::assertSame(403, $res->statusCode);
    }

    public function testListReturnsFilesForAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $res = $this->router->dispatch($this->get('/api/v1/admin/logs', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('files', $payload);
        self::assertIsArray($payload['files']);
        $names = array_map(static fn (array $f): string => (string) $f['name'], $payload['files']);
        self::assertContains('app.log', $names);
    }

    public function testTailReturnsLinesForAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $res = $this->router->dispatch(
            $this->get('/api/v1/admin/logs/tail', 'admin-1', ['file' => 'app.log', 'lines' => '10']),
        );
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame('app.log', $payload['file'] ?? null);
        self::assertIsArray($payload['lines'] ?? null);
        self::assertCount(2, $payload['lines']);
    }

    public function testTailAllReturnsMergedStreamForAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $res = $this->router->dispatch(
            $this->get('/api/v1/admin/logs/tail-all', 'admin-1', ['lines' => '10']),
        );
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertContains('app.log', $payload['files'] ?? []);
        self::assertIsArray($payload['lines'] ?? null);
        self::assertNotEmpty($payload['lines']);
    }

    public function testTailAllRequires403WhenNotAdmin(): void
    {
        $this->asAdmin('u-plain', false);
        $res = $this->router->dispatch($this->get('/api/v1/admin/logs/tail-all', 'u-plain'));
        self::assertSame(403, $res->statusCode);
    }
}
