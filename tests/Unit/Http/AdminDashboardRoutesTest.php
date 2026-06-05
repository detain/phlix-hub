<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Controllers\AdminDashboardController;
use Phlix\Hub\Hub\AuditLogRepository;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Contract tests for the shared-admin-console dashboard routes wired by
 * {@see \Phlix\Hub\Application::registerAdminDashboardRoutes()} (hubby.md H1.4).
 *
 * These verify the two `/api/v1/admin/dashboard/*` paths the redesigned
 * `@phlix/ui` `AdminHubDashboardApi` (the `HubDashboardPage` client) calls
 * dispatch to the right {@see AdminDashboardController} method, are gated
 * `[auth, admin]` (401 with no user, 403 for a non-admin, 200 for an admin),
 * and return the exact snake_case envelopes the shared client unwraps. The
 * routes are registered onto a fresh {@see Router} with the same group/middleware
 * shape Application uses, exercising the *real* {@see AdminMiddleware},
 * {@see AdminDashboardController} and {@see AuditLogRepository} over a mocked
 * {@see Connection} whose `query()` answers each call by SQL fragment.
 *
 * The admin gate is driven by a *separate* mocked UserRepository (its
 * `findAdminById()` is stubbed via {@see self::asAdmin()}); the controller's
 * Connection is the mock, so the only queries it ever sees are the controller's
 * summary counters and the audit-feed reads.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 *
 * @covers \Phlix\Hub\Http\Controllers\AdminDashboardController
 * @covers \Phlix\Hub\Http\Middleware\AdminMiddleware
 */
final class AdminDashboardRoutesTest extends TestCase
{
    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $gateUsers;

    private AdminMiddleware $admin;

    /** @var callable(Request): ?Response */
    private $auth;

    /** SQL of the most recent audit-feed SELECT the mocked Connection saw. */
    private string $lastActivitySelectSql = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateUsers = $this->createMock(UserRepository::class);
        $audit = $this->createMock(AuditLogger::class);
        $this->admin = new AdminMiddleware($this->gateUsers, $audit);

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

    /**
     * Build a fresh Router with the admin-dashboard group wired exactly the way
     * {@see \Phlix\Hub\Application::registerAdminDashboardRoutes()} does, over a
     * controller backed by a real AuditLogRepository on the given Connection.
     */
    private function buildRouter(Connection $db): Router
    {
        $controller = new AdminDashboardController($db, new AuditLogRepository($db));

        $auth = $this->auth;
        $admin = $this->admin;

        $router = new Router();
        $router->group('/api/v1/admin/dashboard', static function (Router $r) use ($controller): void {
            $r->get('/summary', static fn (Request $req): Response => $controller->summary($req));
            $r->get('/activity', static fn (Request $req): Response => $controller->activity($req));
        }, [$auth, $admin]);

        return $router;
    }

    /**
     * Build a GET request, optionally authenticated and with query params.
     *
     * @param array<string, mixed> $query
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

    /** Configure the mocked gate repo so `$userId` is (not) an admin. */
    private function asAdmin(string $userId, bool $isAdmin): void
    {
        $this->gateUsers->method('findAdminById')
            ->willReturn($isAdmin ? ['id' => $userId, 'is_admin' => 1] : null);
    }

    /**
     * A Connection whose `query()` answers the audit-feed COUNT with the row
     * count and the SELECT with `$rows` (capturing the SELECT SQL so tests can
     * assert the LIMIT clamp end-to-end through the real repository).
     *
     * @param list<array<string, mixed>> $rows Raw audit_logs rows (DB columns).
     */
    private function activityDb(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use ($rows): array {
            if (str_contains($sql, 'COUNT(*)')) {
                return [['cnt' => count($rows)]];
            }
            $this->lastActivitySelectSql = $sql;
            return $rows;
        });
        return $db;
    }

    // ---------------------------------------------------------------- summary

    public function testSummaryRequires401WhenUnauthenticated(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/summary'));
        self::assertSame(401, $res->statusCode);
    }

    public function testSummaryRequires403WhenNotAdmin(): void
    {
        $this->asAdmin('u-plain', false);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/summary', 'u-plain'));
        self::assertSame(403, $res->statusCode);
    }

    public function testSummaryReturnsAggregatedCountsForAdmin(): void
    {
        $this->asAdmin('admin-1', true);

        $db = $this->createMock(Connection::class);
        // Four COUNT queries, distinguished by table; the requests query must
        // bind the pending status via a named param (never positional `?`).
        // PHPUnit fills the mocked query()'s 2nd parameter with its declared
        // default (null) when the controller calls it with a single argument
        // (the no-param COUNT queries), so accept `?array` and assert the bind
        // only on the parameterised requests query.
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null): array {
                if (str_contains($sql, 'FROM servers')) {
                    return [['total' => 5, 'online' => 3, 'offline' => 1]];
                }
                if (str_contains($sql, 'FROM relay_sessions')) {
                    self::assertStringContainsString('closed_at IS NULL', $sql);
                    return [['cnt' => 4]];
                }
                if (str_contains($sql, 'FROM requests')) {
                    self::assertSame([':status' => 'pending'], $params);
                    return [['cnt' => 2]];
                }
                if (str_contains($sql, 'FROM users')) {
                    return [['cnt' => 7]];
                }
                self::fail('Unexpected summary query: ' . $sql);
            }
        );
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/summary', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(true, $payload['success'] ?? null);

        self::assertArrayHasKey('data', $payload);
        $data = $payload['data'];
        self::assertIsArray($data);
        self::assertSame(['total' => 5, 'online' => 3, 'offline' => 1], $data['servers']);
        self::assertSame(4, $data['active_relay_sessions']);
        self::assertSame(2, $data['pending_requests']);
        self::assertSame(7, $data['user_count']);
    }

    public function testSummaryReturnsZerosWhenResultsEmpty(): void
    {
        $this->asAdmin('admin-1', true);

        // Every query returns an empty result set (no `[0]` row) — each counter
        // falls back to 0 (covers the is_array/isset guard-false branches).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/summary', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        $data = $payload['data'];
        self::assertIsArray($data);
        self::assertSame(['total' => 0, 'online' => 0, 'offline' => 0], $data['servers']);
        self::assertSame(0, $data['active_relay_sessions']);
        self::assertSame(0, $data['pending_requests']);
        self::assertSame(0, $data['user_count']);
    }

    public function testSummaryCoercesNonNumericCountsToZero(): void
    {
        $this->asAdmin('admin-1', true);

        // Rows are present but the count columns are non-numeric — each value
        // coerces to 0 (covers the intOf non-numeric branch).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'FROM servers')) {
                return [['total' => null, 'online' => 'x', 'offline' => null]];
            }
            return [['cnt' => null]];
        });
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/summary', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        $data = $payload['data'];
        self::assertIsArray($data);
        self::assertSame(['total' => 0, 'online' => 0, 'offline' => 0], $data['servers']);
        self::assertSame(0, $data['active_relay_sessions']);
        self::assertSame(0, $data['pending_requests']);
        self::assertSame(0, $data['user_count']);
    }

    // --------------------------------------------------------------- activity

    public function testActivityRequires401WhenUnauthenticated(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/activity'));
        self::assertSame(401, $res->statusCode);
    }

    public function testActivityRequires403WhenNotAdmin(): void
    {
        $this->asAdmin('u-plain', false);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/activity', 'u-plain'));
        self::assertSame(403, $res->statusCode);
    }

    public function testActivityProjectsAuditEntriesForAdmin(): void
    {
        $this->asAdmin('admin-1', true);

        // Three raw audit_logs rows exercising every projection branch:
        //  - row 1: no action tag -> action falls back to the event slug;
        //           a joined display_name resolves the actor; null resource.
        //  - row 2: explicit action tag; no joined name -> actor falls back to
        //           the user id; a concrete resource becomes the target.
        //  - row 3: no action, no user -> action = event, actor = "system".
        $rows = [
            $this->auditRow([
                'id' => 'a1', 'event' => 'login', 'action' => null, 'user_id' => 'u1',
                'resource' => null, 'created_at' => '2026-06-04 10:00:00',
                'actor_name' => 'Alice Admin', 'actor_username' => 'alice',
            ]),
            $this->auditRow([
                'id' => 'a2', 'event' => 'admin_action', 'action' => 'request.approve',
                'user_id' => 'u2', 'resource' => 'req-9', 'created_at' => '2026-06-04 09:00:00',
                'actor_name' => null, 'actor_username' => null,
            ]),
            $this->auditRow([
                'id' => 'a3', 'event' => 'hub_connect', 'action' => null, 'user_id' => null,
                'resource' => null, 'created_at' => '2026-06-04 08:00:00',
                'actor_name' => null, 'actor_username' => null,
            ]),
        ];

        $router = $this->buildRouter($this->activityDb($rows));
        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/activity', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(true, $payload['success'] ?? null);

        self::assertArrayHasKey('data', $payload);
        $data = $payload['data'];
        self::assertSame([
            [
                'id' => 'a1',
                'action' => 'login',
                'actor' => 'Alice Admin',
                'target' => '',
                'created_at' => '2026-06-04 10:00:00',
            ],
            [
                'id' => 'a2',
                'action' => 'request.approve',
                'actor' => 'u2',
                'target' => 'req-9',
                'created_at' => '2026-06-04 09:00:00',
            ],
            [
                'id' => 'a3',
                'action' => 'hub_connect',
                'actor' => 'system',
                'target' => '',
                'created_at' => '2026-06-04 08:00:00',
            ],
        ], $data);
    }

    public function testActivityReturnsEmptyListWhenNoEvents(): void
    {
        $this->asAdmin('admin-1', true);

        $router = $this->buildRouter($this->activityDb([]));
        $res = $router->dispatch($this->get('/api/v1/admin/dashboard/activity', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        self::assertSame(true, $payload['success'] ?? null);
        self::assertSame([], $payload['data']);
    }

    public function testActivityDefaultsAndClampsLimit(): void
    {
        $this->asAdmin('admin-1', true);

        // [query, expected LIMIT clause in the audit SELECT]. The default is 20;
        // the value is clamped to 1..100; non-numeric input falls to the default.
        $cases = [
            [[], 'LIMIT 20 OFFSET'],
            [['limit' => '5'], 'LIMIT 5 OFFSET'],
            [['limit' => '100'], 'LIMIT 100 OFFSET'],
            [['limit' => '999'], 'LIMIT 100 OFFSET'],
            [['limit' => '0'], 'LIMIT 1 OFFSET'],
            [['limit' => '-4'], 'LIMIT 1 OFFSET'],
            [['limit' => 'abc'], 'LIMIT 20 OFFSET'],
        ];

        foreach ($cases as [$query, $expected]) {
            $this->lastActivitySelectSql = '';
            $router = $this->buildRouter($this->activityDb([]));
            $res = $router->dispatch(
                $this->get('/api/v1/admin/dashboard/activity', 'admin-1', $query),
            );
            self::assertSame(200, $res->statusCode);
            self::assertStringContainsString(
                $expected,
                $this->lastActivitySelectSql,
                'limit case: ' . json_encode($query),
            );
        }
    }

    /**
     * Build a raw `audit_logs` row (the DB-column shape `AuditLogRepository`
     * reads), filling the columns this suite does not care about with nulls.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function auditRow(array $overrides): array
    {
        return $overrides + [
            'id' => '',
            'event' => '',
            'action' => null,
            'user_id' => null,
            'session_id' => null,
            'device_id' => null,
            'resource' => null,
            'success' => 1,
            'reason' => null,
            'ip_address' => null,
            'user_agent' => null,
            'context_json' => null,
            'created_at' => null,
            'actor_name' => null,
            'actor_username' => null,
        ];
    }
}
