<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Container;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Hub\AuditLogRepository;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Router;
use Phlix\Hub\Tests\Support\Container\FixedConnectionProvider;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * S269 — proves the hub's `AuditLogger` actually persists, end to end.
 *
 * ## The defect this exists to make impossible again
 *
 * `AuditLogger::__construct()` takes `?AuditLogRepository $auditRepo = null`, and
 * `AuthServicesProvider` bound it with an explicit `factory()` closure that
 * passed only the logger. An explicit closure bypasses PHP-DI autowiring
 * entirely — the closure IS the construction — so `$auditRepo` was `null` in
 * every resolution and each `$this->auditRepo?->log(...)` short-circuited. From
 * H.5b until S269 the `audit_logs` table, `GET /api/v1/me/audit-logs` and the
 * dashboard activity feed had never seen an `AuditLogger` event.
 *
 * ⚠ **No test that constructs `AuditLogger` itself can catch this**, and the hub
 * has plenty that do (`MyServersFlowTest`, every `createMock(AuditLogger::class)`
 * in the route suites). The argument list lives in the provider and nowhere else,
 * so the subject here is the CONTAINER, resolved from the real provider stack —
 * see {@see FixedConnectionProvider} for the one entry that is substituted and
 * why it has to be.
 *
 * ## Both halves are load-bearing
 *
 *  1. {@see testContainerResolvedAuditLoggerHoldsTheContainersRepository()} pins
 *     the binding itself. It reds the moment the second constructor argument or
 *     the `->parameter('auditRepo', …)` line is removed.
 *  2. {@see testAuditLoggerEventIsReadableThroughTheAuditLogsEndpoint()} pins the
 *     whole path — production `AuditLogger` method → `AuditLogRepository` →
 *     MySQL → the REAL `Application::registerAuditLogRoutes()` registrar →
 *     `Router::dispatch()` through the real `AuthMiddleware`/`AdminMiddleware`
 *     → `AuditLogController` → JSON. A constructor assertion alone would still
 *     pass if, say, the repository wrote to a column the reader never selects.
 *
 * The second test asserts the endpoint reports **zero** rows before the event is
 * logged and exactly the logged one after, so a reader that returns everything
 * unconditionally cannot fake the pass, and the row that comes back is provably
 * the one this test caused.
 *
 * @package Phlix\Hub\Tests\Integration\Container
 *
 * @covers \Phlix\Hub\Common\Container\Providers\AuthServicesProvider
 * @covers \Phlix\Hub\Common\Logger\AuditLogger
 * @covers \Phlix\Hub\Hub\AuditLogRepository
 * @covers \Phlix\Hub\Http\Controllers\AuditLogController
 *
 * @group integration
 */
final class AuditLoggerContainerBindingTest extends RealDatabaseTestCase
{
    /** A >=32-byte HS256 secret so the real JwtHandler accepts it. */
    private const JWT_SECRET = 'S269-audit-logger-container-secret-0123456789';

    /** The endpoint the audit log is actually read through in production. */
    private const AUDIT_LOGS_PATH = '/api/v1/me/audit-logs';

    private string $tmpDir = '';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        AuthMiddleware::resetCache();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-s269-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);

        // php://memory rather than the repo's .logs/: the AuditLogger binding
        // calls LoggerFactory::get() directly, and PHPUnit runs with
        // beStrictAboutOutputDuringTests, so the channel must go nowhere visible.
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        // AuthServicesProvider::resolveSecret() THROWS in a non-dev environment
        // when no secret is configured, so give it a real one via the same
        // config path production uses rather than setting a dev-fallback env var.
        file_put_contents(
            $this->tmpDir . '/auth.php',
            "<?php\n\nreturn ['secret' => '" . self::JWT_SECRET . "'];\n",
        );

        $this->container = ContainerFactory::create(
            [
                'auth_config_path' => $this->tmpDir . '/auth.php',
                'logger_config_path' => $this->tmpDir . '/logger.php',
                'public_root' => dirname(__DIR__, 3) . '/public',
            ],
            [...ContainerFactory::defaultProviders(), new FixedConnectionProvider($this->db)],
        );
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

    /**
     * The binding pin. Container-resolved, never hand-constructed.
     */
    public function testContainerResolvedAuditLoggerHoldsTheContainersRepository(): void
    {
        $audit = $this->container->get(AuditLogger::class);
        self::assertInstanceOf(AuditLogger::class, $audit);

        $property = new ReflectionProperty(AuditLogger::class, 'auditRepo');
        $property->setAccessible(true);
        /** @var mixed $injected */
        $injected = $property->getValue($audit);

        self::assertNotNull(
            $injected,
            'AuditLogger resolved from the real container has a NULL audit repository. '
            . 'AuthServicesProvider must pass AuditLogRepository as the second constructor '
            . 'argument — PHP-DI does not autowire into an explicit factory() closure, so '
            . 'without it every audit event silently skips the audit_logs table (S269).',
        );
        self::assertInstanceOf(AuditLogRepository::class, $injected);

        // Identity, not merely type: a binding that built its own repository on
        // some other connection would satisfy an instanceof check and still write
        // to the wrong database.
        self::assertSame(
            $this->container->get(AuditLogRepository::class),
            $injected,
            'AuditLogger must receive the container-managed AuditLogRepository singleton.',
        );
    }

    /**
     * The whole path: a production AuditLogger call comes back out of
     * `GET /api/v1/me/audit-logs`.
     */
    public function testAuditLoggerEventIsReadableThroughTheAuditLogsEndpoint(): void
    {
        $adminId = $this->insertAdminUser('s269-admin');
        $router = $this->auditLogRouter();

        // CONTROL — the endpoint answers 200 with an empty set BEFORE anything is
        // logged. Without this, "the row came back" is also satisfiable by a
        // reader that ignores its filters and by a table that was never emptied.
        $before = $this->decode($this->dispatchAuditLogs($router, $adminId));
        self::assertSame(0, $before['total']);
        self::assertSame([], $before['logs']);

        $resource = 'req-' . Ids::uuidV4();
        $audit = $this->container->get(AuditLogger::class);
        self::assertInstanceOf(AuditLogger::class, $audit);
        $audit->logAdminAction($adminId, 'request.approve', $resource, ['ticket' => 'S269']);

        $after = $this->decode($this->dispatchAuditLogs($router, $adminId));

        self::assertSame(
            1,
            $after['total'],
            'An AuditLogger event written through the container-resolved logger did not reach '
            . self::AUDIT_LOGS_PATH . '. The repository injection is the whole point of S269.',
        );
        self::assertCount(1, $after['logs']);

        $entry = $after['logs'][0];
        self::assertIsArray($entry);
        self::assertSame('admin_action', $entry['event'] ?? null);
        self::assertSame($adminId, $entry['user_id'] ?? null);
        self::assertSame('request.approve', $entry['action'] ?? null);
        self::assertSame($resource, $entry['resource'] ?? null);
        self::assertSame(['ticket' => 'S269'], $entry['context'] ?? null);
    }

    /**
     * Build the route table with the REAL production registrar, against the real
     * container, so the path string and the middleware chain are the shipped
     * ones rather than a restatement of them.
     */
    private function auditLogRouter(): Router
    {
        $router = new Router();

        $reflection = new ReflectionClass(Application::class);
        $app = $reflection->newInstanceWithoutConstructor();
        foreach (['router' => $router, 'container' => $this->container, 'config' => []] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($app, $value);
        }

        $registrar = new ReflectionMethod(Application::class, 'registerAuditLogRoutes');
        $registrar->setAccessible(true);
        $registrar->invoke($app);

        // The registrar must have produced exactly the path the rest of this test
        // dispatches against; a renamed route would otherwise 404 into a
        // confusing "total is 0" rather than saying what moved.
        $paths = [];
        foreach ($router->getRoutes()['GET'] ?? [] as $route) {
            $paths[] = (string) $route['path'];
        }
        self::assertContains(self::AUDIT_LOGS_PATH, $paths);

        return $router;
    }

    /**
     * Dispatch `GET /api/v1/me/audit-logs` as $adminId through the real router,
     * with a real HS256 token minted by the container's JwtHandler, so
     * AuthMiddleware and AdminMiddleware both run for real.
     */
    private function dispatchAuditLogs(Router $router, string $adminId): string
    {
        $jwt = $this->container->get(JwtHandler::class);
        self::assertInstanceOf(JwtHandler::class, $jwt);
        $token = $jwt->createAccessToken($adminId);

        $request = new Request();
        $request->method = 'GET';
        $request->path = self::AUDIT_LOGS_PATH;
        $request->headers['authorization'] = 'Bearer ' . $token;
        $request->bearerToken = $token;

        $response = $router->dispatch($request);
        self::assertSame(
            200,
            $response->statusCode,
            'Expected 200 from ' . self::AUDIT_LOGS_PATH . ', got ' . $response->statusCode
            . ' with body: ' . $response->body,
        );

        return $response->body;
    }

    /**
     * @return array{logs: list<mixed>, total: int}
     */
    private function decode(string $body): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('logs', $decoded);
        self::assertArrayHasKey('total', $decoded);
        self::assertIsArray($decoded['logs']);
        self::assertIsInt($decoded['total']);

        return ['logs' => array_values($decoded['logs']), 'total' => $decoded['total']];
    }

    /**
     * Insert an admin user directly. `AdminMiddleware` gates the endpoint on
     * `users.is_admin = 1`, so the row has to be real.
     */
    private function insertAdminUser(string $username): string
    {
        $id = Ids::uuidV4();
        $this->db->query(
            'INSERT INTO users (id, username, email, password_hash, display_name, is_admin)'
            . ' VALUES (:id, :username, :email, :hash, :displayName, 1)',
            [
                'id' => $id,
                'username' => $username,
                'email' => $username . '@example.test',
                'hash' => password_hash('s269-password', PASSWORD_DEFAULT),
                'displayName' => 'S269 Admin',
            ],
        );

        return $id;
    }
}
