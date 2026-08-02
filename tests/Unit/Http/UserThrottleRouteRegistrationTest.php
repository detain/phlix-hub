<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Controllers\UserQuotaController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Router;
use Phlix\Hub\Hub\RelaySessionManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reachability + auth-gating contract for the S41 admin throttle endpoint
 * `PUT /api/v1/admin/users/{id}/throttle` (updates.md #50).
 *
 * WHY THIS EXISTS — a green test can pin dead code. Every other throttle test
 * ({@see \Phlix\Hub\Tests\Unit\Http\Controllers\UserQuotaControllerTest}) calls
 * `UserQuotaController::setUserThrottle()` DIRECTLY; the `/api/v1/...` strings
 * those tests pass are inert labels that never touch the {@see Router}. The
 * neighbouring route suites (e.g. `AdminUserRoutesTest`) re-declare their route
 * table inside the test, so they too are blind to the production registration.
 * Deleting the `$r->put('/{id}/throttle', ...)` line from
 * {@see Application::registerUserQuotaRoutes()} therefore left the whole 2492-test
 * suite GREEN while the admin endpoint 404'd in production — S41's "an admin can
 * set/view a throttle level per user" acceptance criterion was pinned by nothing.
 *
 * This suite closes that hole by driving the REAL production registrar: it builds
 * an {@see Application} without its constructor, injects a real {@see Router} plus
 * a container serving the real collaborators, and reflection-invokes the private
 * `registerUserQuotaRoutes()`. Requests are then dispatched through that router,
 * so the assertions below fail if the route line, its `{id}` placeholder, its HTTP
 * verb, or either middleware in its chain is removed.
 *
 * The middleware are REAL ({@see AuthMiddleware} over a real {@see JwtHandler}
 * minting real HS256 tokens, and {@see AdminMiddleware}), so the 401/403/200
 * outcomes come from production auth code rather than a test stand-in.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 *
 * @covers \Phlix\Hub\Application
 * @covers \Phlix\Hub\Http\Controllers\UserQuotaController
 * @covers \Phlix\Hub\Http\Middleware\AdminMiddleware
 */
final class UserThrottleRouteRegistrationTest extends TestCase
{
    /** A >=32-byte HS256 secret so the real JwtHandler accepts it. */
    private const JWT_SECRET = 'S41-throttle-route-test-secret-key-0123456789';

    private const THROTTLE_PATH = '/api/v1/admin/users/u-target/throttle';

    /** @var RelaySessionManager&\PHPUnit\Framework\MockObject\MockObject */
    private RelaySessionManager $sessions;

    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $users;

    /** @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject */
    private AuditLogger $audit;

    private JwtHandler $jwt;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = $this->createMock(RelaySessionManager::class);
        $this->users    = $this->createMock(UserRepository::class);
        $this->audit    = $this->createMock(AuditLogger::class);
        $this->jwt      = new JwtHandler(self::JWT_SECRET);

        $this->router = $this->buildProductionRouter();
    }

    /**
     * Run the REAL {@see Application::registerUserQuotaRoutes()} against a fresh
     * Router and return that router. Nothing about the route table is restated
     * here — the routes under test come from production code.
     */
    private function buildProductionRouter(): Router
    {
        $controller = new UserQuotaController($this->sessions, $this->users, $this->audit);
        $auth       = new AuthMiddleware($this->jwt, $this->users);
        $admin      = new AdminMiddleware($this->users, $this->audit);

        $container = new class ($controller, $auth, $admin) implements ContainerInterface {
            public function __construct(
                private readonly UserQuotaController $controller,
                private readonly AuthMiddleware $auth,
                private readonly AdminMiddleware $admin,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    UserQuotaController::class => $this->controller,
                    AuthMiddleware::class      => $this->auth,
                    AdminMiddleware::class     => $this->admin,
                    default                    => throw new \RuntimeException("unexpected service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array(
                    $id,
                    [UserQuotaController::class, AuthMiddleware::class, AdminMiddleware::class],
                    true,
                );
            }
        };

        $router = new Router();

        // Build an Application shell: skip the constructor (it would register the
        // hub's entire route table and demand every controller from the container)
        // and inject only what registerUserQuotaRoutes() touches.
        $reflection = new ReflectionClass(Application::class);
        $app        = $reflection->newInstanceWithoutConstructor();

        $routerProp = $reflection->getProperty('router');
        $routerProp->setAccessible(true);
        $routerProp->setValue($app, $router);

        $containerProp = $reflection->getProperty('container');
        $containerProp->setAccessible(true);
        $containerProp->setValue($app, $container);

        $register = new ReflectionMethod(Application::class, 'registerUserQuotaRoutes');
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
            // AuthMiddleware::extractToken() reads the pre-parsed $bearerToken
            // (Request::fromWorkerman()/fromGlobals() populate it from the
            // Authorization header), so set both to mirror a real request.
            $request->headers['authorization'] = 'Bearer ' . $token;
            $request->bearerToken              = $token;
        }
        return $request;
    }

    /**
     * A token whose subject exists, so AuthMiddleware populates Request::$userId.
     * Each caller uses a DISTINCT id because AuthMiddleware memoises existence in
     * a per-process static cache.
     */
    private function tokenFor(string $userId): string
    {
        $this->users->method('userExists')->willReturn(true);
        return $this->jwt->createAccessToken($userId);
    }

    /**
     * The production registrar must actually register `PUT …/{id}/throttle`.
     * Deleting that line makes this fail with a 404 — the whole point of the suite.
     */
    public function testThrottleRouteIsRegisteredAndReachable(): void
    {
        $token = $this->tokenFor('admin-reach');
        $this->users->method('findAdminById')->willReturn(['id' => 'admin-reach']);

        $response = $this->router->dispatch(
            $this->request('PUT', self::THROTTLE_PATH, $token, ['throttle_bps' => 5000000]),
        );

        self::assertNotSame(
            404,
            $response->statusCode,
            'PUT /api/v1/admin/users/{id}/throttle must be registered by '
            . 'Application::registerUserQuotaRoutes() — a 404 means the route line is gone.',
        );
        self::assertSame(200, $response->statusCode);
    }

    /**
     * The route must be present in the production route table under the PUT verb
     * with an `{id}` placeholder, independent of dispatch behaviour.
     */
    public function testThrottleRouteIsRegisteredUnderPutWithIdPlaceholder(): void
    {
        $routes = $this->router->getRoutes();

        self::assertArrayHasKey('PUT', $routes, 'no PUT routes were registered at all');

        $paths = array_map(
            static fn (array $route): string => $route['path'],
            array_values($routes['PUT']),
        );

        self::assertContains(
            '/api/v1/admin/users/{id}/throttle',
            $paths,
            'the S41 throttle route is missing from the production PUT route table',
        );
    }

    /**
     * Auth gate: no credentials must never reach the controller.
     */
    public function testThrottleRouteIsAuthGated(): void
    {
        $this->sessions->expects(self::never())->method('setUserThrottle');

        $response = $this->router->dispatch(
            $this->request('PUT', self::THROTTLE_PATH, null, ['throttle_bps' => 5000000]),
        );

        self::assertSame(401, $response->statusCode, 'the throttle route must be auth-gated');
    }

    /**
     * Admin gate: an authenticated NON-admin must be refused by the route chain.
     */
    public function testThrottleRouteIsAdminGated(): void
    {
        $token = $this->tokenFor('plain-user');
        $this->users->method('findAdminById')->willReturn(null);
        $this->sessions->expects(self::never())->method('setUserThrottle');

        $response = $this->router->dispatch(
            $this->request('PUT', self::THROTTLE_PATH, $token, ['throttle_bps' => 5000000]),
        );

        self::assertSame(403, $response->statusCode, 'a non-admin must not set another user\'s throttle');
    }

    /**
     * `0` = Unlimited must survive the FULL route path — router, both middleware,
     * the controller allow-list — and reach the store as an int 0, never be
     * dropped as "empty" nor rewritten to the 3 Mbps default.
     */
    public function testUnlimitedZeroRoundTripsThroughTheRegisteredRoute(): void
    {
        $token = $this->tokenFor('admin-zero');
        $this->users->method('findAdminById')->willReturn(['id' => 'admin-zero']);

        $this->sessions->expects(self::once())
            ->method('setUserThrottle')
            ->with('u-target', 0);
        $this->sessions->method('getUserThrottleBps')->willReturn(0);
        $this->sessions->method('getUserBandwidth')->willReturn([]);
        $this->sessions->method('getUserMaxConcurrentStreams')->willReturn(0);

        $response = $this->router->dispatch(
            $this->request('PUT', self::THROTTLE_PATH, $token, ['throttle_bps' => 0]),
        );

        self::assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->body, true);
        self::assertSame(0, $payload['throttle_bps'], 'Unlimited (0) must round-trip through the route as 0');
    }

    /**
     * A configured level round-trips through the registered route unchanged.
     */
    public function testConfiguredLevelRoundTripsThroughTheRegisteredRoute(): void
    {
        $token = $this->tokenFor('admin-level');
        $this->users->method('findAdminById')->willReturn(['id' => 'admin-level']);

        $this->sessions->expects(self::once())
            ->method('setUserThrottle')
            ->with('u-target', 50000000);
        $this->sessions->method('getUserThrottleBps')->willReturn(50000000);
        $this->sessions->method('getUserBandwidth')->willReturn([]);
        $this->sessions->method('getUserMaxConcurrentStreams')->willReturn(0);

        $response = $this->router->dispatch(
            $this->request('PUT', self::THROTTLE_PATH, $token, ['throttle_bps' => 50000000]),
        );

        self::assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->body, true);
        self::assertSame(50000000, $payload['throttle_bps']);
    }
}
