<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Controllers\AdminUserController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Contract tests for the shared-admin-console user routes wired by
 * {@see \Phlix\Hub\Application::registerAdminUserRoutes()} (hubby.md H1.3).
 *
 * These verify the `/api/v1/admin/users*` paths the redesigned `@phlix/ui`
 * `AdminUsersApi` calls dispatch to the right {@see AdminUserController}
 * method, are gated `[auth, admin]` (401 with no user, 403 for a non-admin,
 * 200/201 for an admin), and return the exact envelopes the shared client
 * unwraps. The routes are registered onto a fresh {@see Router} with the same
 * group/middleware shape Application uses, exercising the *real*
 * {@see AdminMiddleware}, {@see AdminUserController} and {@see UserRepository}
 * over a mocked {@see Connection} whose `query()` answers each repo call by SQL
 * fragment.
 *
 * The admin gate is driven by a *separate* mocked UserRepository (its
 * `findAdminById()` is stubbed via {@see self::asAdmin()}); the controller's
 * own repository is real, so the mocked Connection only ever sees the
 * controller's queries.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 *
 * @covers \Phlix\Hub\Http\Controllers\AdminUserController
 * @covers \Phlix\Hub\Http\Middleware\AdminMiddleware
 * @covers \Phlix\Hub\Auth\UserRepository
 */
final class AdminUserRoutesTest extends TestCase
{
    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $gateUsers;

    /** @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject */
    private AuditLogger $audit;

    private AdminMiddleware $admin;

    /** @var callable(Request): ?Response */
    private $auth;

    /** @var list<string> SQL strings the mocked Connection saw this test. */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateUsers = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);
        $this->admin = new AdminMiddleware($this->gateUsers, $this->audit);

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
     * Build a fresh Router with the admin-users group wired exactly the way
     * {@see \Phlix\Hub\Application::registerAdminUserRoutes()} does, over a
     * controller backed by a real UserRepository on the given Connection.
     */
    private function buildRouter(Connection $db): Router
    {
        $controller = new AdminUserController(new UserRepository($db), $this->audit);
        $auth = $this->auth;
        $admin = $this->admin;

        $router = new Router();
        $router->group('/api/v1/admin/users', static function (Router $r) use ($controller): void {
            $r->get('', static fn (Request $req): Response => $controller->list($req));
            $r->post('', static fn (Request $req): Response => $controller->create($req));
            $r->get('/{id}', static function (Request $req, array $params) use ($controller): Response {
                /** @var array<string, string> $p */
                $p = $params;
                return $controller->get($req, $p);
            });
            $r->put('/{id}', static function (Request $req, array $params) use ($controller): Response {
                /** @var array<string, string> $p */
                $p = $params;
                return $controller->update($req, $p);
            });
            $r->delete('/{id}', static function (Request $req, array $params) use ($controller): Response {
                /** @var array<string, string> $p */
                $p = $params;
                return $controller->delete($req, $p);
            });
            $r->post('/{id}/set-admin', static function (Request $req, array $params) use ($controller): Response {
                /** @var array<string, string> $p */
                $p = $params;
                return $controller->setAdmin($req, $p);
            });
            $r->post('/{id}/reset-password', static function (Request $req, array $params) use ($controller): Response {
                /** @var array<string, string> $p */
                $p = $params;
                return $controller->resetPassword($req, $p);
            });
            $r->get('/{id}/profiles', static function (Request $req, array $params) use ($controller): Response {
                /** @var array<string, string> $p */
                $p = $params;
                return $controller->listProfiles($req, $p);
            });
        }, [$auth, $admin]);

        return $router;
    }

    /**
     * A Connection mock whose `query()` answers the controller's SQL by
     * fragment. Finder results come from `$opts`; mutating queries
     * (INSERT/UPDATE/DELETE) return true. Every SQL string is recorded in
     * {@see self::$queries} so tests can assert (e.g.) that a set-admin
     * UPDATE was issued.
     *
     * @param array{
     *     findAll?: list<array<string, mixed>>,
     *     findById?: array<string, mixed>|null,
     *     findByUsername?: array<string, mixed>|null,
     *     findByEmail?: array<string, mixed>|null,
     *     emailExists?: bool,
     *     usernameExists?: bool,
     *     adminCount?: int
     * } $opts
     */
    private function makeDb(array $opts = []): Connection
    {
        $this->queries = [];
        $db = $this->createMock(Connection::class);
        // NB: Workerman\MySQL\Connection::query()'s second arg defaults to
        // null, and PHPUnit forwards that resolved default to the callback for
        // single-arg repo calls (findAll/countAdmins) — so accept null here.
        $db->method('query')->willReturnCallback(
            function (string $sql, mixed $params = null) use ($opts): array|bool {
                $this->queries[] = $sql;

                if (str_contains($sql, 'COUNT(*) AS c')) {
                    return [['c' => $opts['adminCount'] ?? 2]];
                }
                if (str_contains($sql, 'SELECT 1 FROM users WHERE email')) {
                    return ($opts['emailExists'] ?? false) ? [['1' => 1]] : [];
                }
                if (str_contains($sql, 'SELECT 1 FROM users WHERE username')) {
                    return ($opts['usernameExists'] ?? false) ? [['1' => 1]] : [];
                }
                if (str_contains($sql, 'SELECT id, username, email')) {
                    return $opts['findAll'] ?? [];
                }
                if (str_contains($sql, 'SELECT * FROM users WHERE id')) {
                    $row = $opts['findById'] ?? null;
                    return $row === null ? [] : [$row];
                }
                if (str_contains($sql, 'SELECT * FROM users WHERE username')) {
                    $row = $opts['findByUsername'] ?? null;
                    return $row === null ? [] : [$row];
                }
                if (str_contains($sql, 'SELECT * FROM users WHERE email')) {
                    $row = $opts['findByEmail'] ?? null;
                    return $row === null ? [] : [$row];
                }
                // INSERT / UPDATE / DELETE.
                return true;
            },
        );
        return $db;
    }

    /** A Connection mock that must never be queried (gate / pure-validation paths). */
    private function neverDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        return $db;
    }

    /** Configure the gate's mocked repo so `$userId` is (not) an admin. */
    private function asAdmin(string $userId, bool $isAdmin): void
    {
        $this->gateUsers->method('findAdminById')
            ->willReturn($isAdmin ? ['id' => $userId, 'is_admin' => 1] : null);
    }

    /**
     * Build a request, optionally authenticated as a given user id.
     *
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, ?string $userId = null, array $body = []): Request
    {
        $req = new Request();
        $req->method = $method;
        $req->path = $path;
        $req->body = $body;
        if ($userId !== null) {
            $req->headers['x-test-user'] = $userId;
        }
        return $req;
    }

    /**
     * Decode a JSON response body to an array (fails the test if it is not).
     *
     * @return array<string, mixed>
     */
    private function decode(Response $res): array
    {
        $payload = json_decode((string) $res->body, true);
        self::assertIsArray($payload);
        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** Whether any recorded query contained the given fragment. */
    private function queriedFragment(string $fragment): bool
    {
        foreach ($this->queries as $sql) {
            if (str_contains($sql, $fragment)) {
                return true;
            }
        }
        return false;
    }

    // ── Auth gate ────────────────────────────────────────────────────────────

    public function testListRequires401WhenUnauthenticated(): void
    {
        $router = $this->buildRouter($this->neverDb());
        $res = $router->dispatch($this->request('GET', '/api/v1/admin/users'));
        self::assertSame(401, $res->statusCode);
    }

    public function testListRequires403WhenNotAdmin(): void
    {
        $this->asAdmin('u-plain', false);
        $router = $this->buildRouter($this->neverDb());
        $res = $router->dispatch($this->request('GET', '/api/v1/admin/users', 'u-plain'));
        self::assertSame(403, $res->statusCode);
    }

    public function testCreateRequires401WhenUnauthenticated(): void
    {
        $router = $this->buildRouter($this->neverDb());
        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', null, [
            'username' => 'newbie',
            'email'    => 'new@example.com',
            'password' => 'password123',
        ]));
        self::assertSame(401, $res->statusCode);
    }

    public function testProfilesRequires403WhenNotAdmin(): void
    {
        $this->asAdmin('u-plain', false);
        $router = $this->buildRouter($this->neverDb());
        $res = $router->dispatch($this->request('GET', '/api/v1/admin/users/u-1/profiles', 'u-plain'));
        self::assertSame(403, $res->statusCode);
    }

    // ── list ───────────────────────────────────────────────────────────────────

    public function testListReturnsPublicUsersForAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findAll' => [
            [
                'id'            => 'u-1',
                'username'      => 'alice',
                'email'         => 'alice@example.com',
                'is_admin'      => '1',
                'password_hash' => 'secret-should-not-leak',
                'created_at'    => '2026-01-01 00:00:00',
                'updated_at'    => '2026-01-02 00:00:00',
            ],
            [
                'id'         => 'u-2',
                'username'   => 'bob',
                'email'      => 'bob@example.com',
                'is_admin'   => 0,
                'created_at' => '2026-02-01 00:00:00',
                'updated_at' => '2026-02-01 00:00:00',
            ],
        ]]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('GET', '/api/v1/admin/users', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = $this->decode($res);
        self::assertArrayHasKey('users', $payload);
        $users = $payload['users'];
        self::assertIsArray($users);
        self::assertCount(2, $users);

        $first = $users[0];
        self::assertIsArray($first);
        self::assertSame('u-1', $first['id']);
        self::assertSame('alice', $first['username']);
        self::assertSame(1, $first['is_admin']); // coerced from the '1' string
        self::assertArrayNotHasKey('password_hash', $first); // whitelist must strip it
        self::assertArrayHasKey('created_at', $first);
        self::assertArrayHasKey('updated_at', $first);

        $second = $users[1];
        self::assertIsArray($second);
        self::assertSame(0, $second['is_admin']);
    }

    // ── get ────────────────────────────────────────────────────────────────────

    public function testGetReturnsSingleUserForAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => [
            'id'            => 'u-9',
            'username'      => 'carol',
            'email'         => 'carol@example.com',
            'is_admin'      => 1,
            'password_hash' => 'nope',
            'created_at'    => '2026-03-01 00:00:00',
            'updated_at'    => '2026-03-01 00:00:00',
        ]]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('GET', '/api/v1/admin/users/u-9', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = $this->decode($res);
        self::assertArrayHasKey('user', $payload);
        $user = $payload['user'];
        self::assertIsArray($user);
        self::assertSame('u-9', $user['id']);
        self::assertSame('carol', $user['username']);
        self::assertSame(1, $user['is_admin']);
        self::assertArrayNotHasKey('password_hash', $user);
    }

    public function testGetReturns404ForMissingUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => null]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('GET', '/api/v1/admin/users/ghost', 'admin-1'));
        self::assertSame(404, $res->statusCode);

        $payload = $this->decode($res);
        self::assertSame('user_not_found', $payload['code'] ?? null);
    }

    public function testGetCoercesNonStringColumnValues(): void
    {
        // Exercises the driver-type-variance coercions in publicUser():
        // an int id -> string, a bool is_admin -> 0|1, a non-scalar -> ''.
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => [
            'id'         => 42,
            'username'   => 'dave',
            'email'      => 'dave@example.com',
            'is_admin'   => true,
            'created_at' => ['weird'],
            'updated_at' => '2026-04-01 00:00:00',
        ]]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('GET', '/api/v1/admin/users/42', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = $this->decode($res);
        $user = $payload['user'];
        self::assertIsArray($user);
        self::assertSame('42', $user['id']);       // int -> string
        self::assertSame(1, $user['is_admin']);    // bool -> 1
        self::assertSame('', $user['created_at']); // non-scalar -> ''
    }

    // ── create ───────────────────────────────────────────────────────────────────

    public function testCreateRejectsShortUsername(): void
    {
        $this->asAdmin('admin-1', true);
        $router = $this->buildRouter($this->neverDb());

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', 'admin-1', [
            'username' => 'ab',
            'email'    => 'ab@example.com',
            'password' => 'password123',
        ]));
        self::assertSame(400, $res->statusCode);

        $payload = $this->decode($res);
        self::assertArrayHasKey('field_errors', $payload);
        $fieldErrors = $payload['field_errors'];
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('username', $fieldErrors);
    }

    public function testCreateRejectsNonAlphanumericUsername(): void
    {
        $this->asAdmin('admin-1', true);
        $router = $this->buildRouter($this->neverDb());

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', 'admin-1', [
            'username' => 'bad name!',
            'email'    => 'bad@example.com',
            'password' => 'password123',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        self::assertSame('validation_failed', $payload['code'] ?? null);
    }

    public function testCreateRejectsInvalidEmail(): void
    {
        $this->asAdmin('admin-1', true);
        $router = $this->buildRouter($this->neverDb());

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', 'admin-1', [
            'username' => 'validname',
            'email'    => 'not-an-email',
            'password' => 'password123',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('email', $fieldErrors);
    }

    public function testCreateRejectsShortPassword(): void
    {
        $this->asAdmin('admin-1', true);
        $router = $this->buildRouter($this->neverDb());

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', 'admin-1', [
            'username' => 'validname',
            'email'    => 'valid@example.com',
            'password' => 'short',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('password', $fieldErrors);
    }

    public function testCreateRejectsDuplicateEmail(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['emailExists' => true]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', 'admin-1', [
            'username' => 'validname',
            'email'    => 'taken@example.com',
            'password' => 'password123',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('email', $fieldErrors);
    }

    public function testCreateRejectsDuplicateUsername(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['emailExists' => false, 'usernameExists' => true]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', 'admin-1', [
            'username' => 'taken',
            'email'    => 'fresh@example.com',
            'password' => 'password123',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('username', $fieldErrors);
    }

    public function testCreateSucceedsAsPlainUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['emailExists' => false, 'usernameExists' => false]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', 'admin-1', [
            'username' => 'freshuser',
            'email'    => 'fresh@example.com',
            'password' => 'password123',
            'is_admin' => false,
        ]));
        self::assertSame(201, $res->statusCode);

        $payload = $this->decode($res);
        self::assertArrayHasKey('user_id', $payload);
        self::assertIsString($payload['user_id']);
        self::assertNotSame('', $payload['user_id']);
        self::assertArrayHasKey('message', $payload);

        self::assertTrue($this->queriedFragment('INSERT INTO users'));
        self::assertFalse($this->queriedFragment('is_admin = :flag')); // not promoted
    }

    public function testCreateSucceedsAndPromotesWhenAdminRequested(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['emailExists' => false, 'usernameExists' => false]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users', 'admin-1', [
            'username' => 'adminuser',
            'email'    => 'adminuser@example.com',
            'password' => 'password123',
            'is_admin' => true,
        ]));
        self::assertSame(201, $res->statusCode);

        self::assertTrue($this->queriedFragment('INSERT INTO users'));
        self::assertTrue($this->queriedFragment('is_admin = :flag')); // setAdmin() fired
    }

    // ── update ───────────────────────────────────────────────────────────────────

    public function testUpdateReturns404ForMissingUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => null]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/ghost', 'admin-1', [
            'username' => 'whatever',
        ]));
        self::assertSame(404, $res->statusCode);
    }

    public function testUpdateChangesUsernameAndEmail(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb([
            'findById'       => ['id' => 'u-1', 'username' => 'old', 'email' => 'old@example.com'],
            'findByUsername' => null,
            'findByEmail'    => null,
        ]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/u-1', 'admin-1', [
            'username' => 'newname',
            'email'    => 'new@example.com',
        ]));
        self::assertSame(200, $res->statusCode);

        $payload = $this->decode($res);
        self::assertArrayHasKey('message', $payload);
        self::assertTrue($this->queriedFragment('UPDATE users SET'));
    }

    public function testUpdateRejectsDuplicateEmailFromAnotherUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb([
            'findById'    => ['id' => 'u-1', 'username' => 'me', 'email' => 'me@example.com'],
            'findByEmail' => ['id' => 'u-2', 'email' => 'taken@example.com'], // a DIFFERENT user
        ]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/u-1', 'admin-1', [
            'email' => 'taken@example.com',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('email', $fieldErrors);
    }

    public function testUpdateAllowsKeepingOwnEmail(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb([
            'findById'    => ['id' => 'u-1', 'username' => 'me', 'email' => 'me@example.com'],
            'findByEmail' => ['id' => 'u-1', 'email' => 'me@example.com'], // the SAME user
        ]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/u-1', 'admin-1', [
            'email' => 'me@example.com',
        ]));
        self::assertSame(200, $res->statusCode);
        self::assertTrue($this->queriedFragment('UPDATE users SET'));
    }

    public function testUpdateRejectsShortPassword(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-1', 'username' => 'me', 'email' => 'me@example.com']]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/u-1', 'admin-1', [
            'password' => 'short',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('password', $fieldErrors);
    }

    public function testUpdateRejectsInvalidUsername(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-1', 'username' => 'old', 'email' => 'old@example.com']]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/u-1', 'admin-1', [
            'username' => 'x', // too short
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('username', $fieldErrors);
    }

    public function testUpdateRejectsDuplicateUsernameFromAnotherUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb([
            'findById'       => ['id' => 'u-1', 'username' => 'me', 'email' => 'me@example.com'],
            'findByUsername' => ['id' => 'u-2', 'username' => 'taken'], // a DIFFERENT user
        ]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/u-1', 'admin-1', [
            'username' => 'taken',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('username', $fieldErrors);
    }

    public function testUpdateRejectsInvalidEmail(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-1', 'username' => 'me', 'email' => 'me@example.com']]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/u-1', 'admin-1', [
            'email' => 'not-an-email',
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        $fieldErrors = $payload['field_errors'] ?? null;
        self::assertIsArray($fieldErrors);
        self::assertArrayHasKey('email', $fieldErrors);
    }

    public function testUpdateChangesPasswordWhenValid(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-1', 'username' => 'me', 'email' => 'me@example.com']]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('PUT', '/api/v1/admin/users/u-1', 'admin-1', [
            'password' => 'brand-new-password',
        ]));
        self::assertSame(200, $res->statusCode);
        self::assertTrue($this->queriedFragment('password_hash = :password_hash'));
    }

    // ── delete ───────────────────────────────────────────────────────────────────

    public function testDeleteReturns404ForMissingUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => null]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('DELETE', '/api/v1/admin/users/ghost', 'admin-1'));
        self::assertSame(404, $res->statusCode);
    }

    public function testDeleteRejectsSelf(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'admin-1', 'is_admin' => 1]]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('DELETE', '/api/v1/admin/users/admin-1', 'admin-1'));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        self::assertSame('cannot_delete_self', $payload['code'] ?? null);
    }

    public function testDeleteRejectsLastAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-2', 'is_admin' => 1], 'adminCount' => 1]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('DELETE', '/api/v1/admin/users/u-2', 'admin-1'));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        self::assertSame('last_admin', $payload['code'] ?? null);
    }

    public function testDeleteSucceedsForPlainUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-2', 'is_admin' => 0]]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('DELETE', '/api/v1/admin/users/u-2', 'admin-1'));
        self::assertSame(200, $res->statusCode);
        self::assertTrue($this->queriedFragment('DELETE FROM users'));
    }

    public function testDeleteSucceedsForAdminWhenOthersExist(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-2', 'is_admin' => 1], 'adminCount' => 3]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('DELETE', '/api/v1/admin/users/u-2', 'admin-1'));
        self::assertSame(200, $res->statusCode);
        self::assertTrue($this->queriedFragment('DELETE FROM users'));
    }

    // ── set-admin ────────────────────────────────────────────────────────────────

    public function testSetAdminReturns404ForMissingUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => null]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users/ghost/set-admin', 'admin-1', [
            'is_admin' => true,
        ]));
        self::assertSame(404, $res->statusCode);
    }

    public function testSetAdminPromotesPlainUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-2', 'is_admin' => 0]]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users/u-2/set-admin', 'admin-1', [
            'is_admin' => true,
        ]));
        self::assertSame(200, $res->statusCode);
        self::assertTrue($this->queriedFragment('is_admin = :flag'));
    }

    public function testSetAdminRejectsSelfDemote(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'admin-1', 'is_admin' => 1]]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users/admin-1/set-admin', 'admin-1', [
            'is_admin' => false,
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        self::assertSame('cannot_demote_self', $payload['code'] ?? null);
    }

    public function testSetAdminRejectsDemotingLastAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-2', 'is_admin' => 1], 'adminCount' => 1]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users/u-2/set-admin', 'admin-1', [
            'is_admin' => false,
        ]));
        self::assertSame(400, $res->statusCode);
        $payload = $this->decode($res);
        self::assertSame('last_admin', $payload['code'] ?? null);
    }

    public function testSetAdminDemotesWhenOtherAdminsExist(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-2', 'is_admin' => 1], 'adminCount' => 2]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch($this->request('POST', '/api/v1/admin/users/u-2/set-admin', 'admin-1', [
            'is_admin' => false,
        ]));
        self::assertSame(200, $res->statusCode);
        self::assertTrue($this->queriedFragment('is_admin = :flag'));
    }

    // ── reset-password ─────────────────────────────────────────────────────────────

    public function testResetPasswordReturns404ForMissingUser(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => null]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->request('POST', '/api/v1/admin/users/ghost/reset-password', 'admin-1'),
        );
        self::assertSame(404, $res->statusCode);
    }

    public function testResetPasswordReturnsNewPasswordForAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $db = $this->makeDb(['findById' => ['id' => 'u-2', 'username' => 'bob']]);
        $router = $this->buildRouter($db);

        $res = $router->dispatch(
            $this->request('POST', '/api/v1/admin/users/u-2/reset-password', 'admin-1'),
        );
        self::assertSame(200, $res->statusCode);

        $payload = $this->decode($res);
        self::assertArrayHasKey('new_password', $payload);
        self::assertIsString($payload['new_password']);
        self::assertSame(12, strlen($payload['new_password']));
        self::assertTrue($this->queriedFragment('password_hash = :password_hash'));
    }

    // ── profiles (always empty on the hub) ───────────────────────────────────────

    public function testListProfilesReturnsEmptyForAdmin(): void
    {
        $this->asAdmin('admin-1', true);
        $router = $this->buildRouter($this->neverDb());

        $res = $router->dispatch($this->request('GET', '/api/v1/admin/users/u-2/profiles', 'admin-1'));
        self::assertSame(200, $res->statusCode);

        $payload = $this->decode($res);
        self::assertArrayHasKey('profiles', $payload);
        self::assertSame([], $payload['profiles']);
    }
}
