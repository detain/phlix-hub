<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use Phlix\Hub\Tests\Support\Alexa\RecordingAlexaRejectionAuditor;
use Phlix\Hub\Tests\Support\Alexa\RecordingCertChainFetcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Shared machinery for the S174 route-registration suites.
 *
 * WHY THIS EXISTS — the hub's pre-existing route suites (`AdminUserRoutesTest`,
 * `AdminLogRoutesTest`, `AdminDashboardRoutesTest`, `AdminSettingsRoutesTest`)
 * each REBUILD the route table inside their own `setUp()`, and the controller
 * suites call controller methods directly. Both shapes are blind to the
 * production registrar: deleting `$r->put('/{id}/throttle', …)` from
 * `Application::registerUserQuotaRoutes()` left the entire suite green while the
 * endpoint 404'd in production (S41/S174).
 *
 * Everything built here therefore comes from the REAL
 * `Application::register*Routes()` methods. Nothing about the route table is
 * restated in the builder — only in the hand-written {@see RouteManifest}, which
 * is compared AGAINST the production output.
 *
 * How the shell is assembled: `Application::__construct()` immediately calls
 * `registerRoutes()` and would demand every controller from a real container, so
 * the object is created with {@see ReflectionClass::newInstanceWithoutConstructor()}
 * and only `router`, `container` and `config` are injected. The private registrar
 * is then reflection-invoked.
 *
 * Controller instances handed back by the stub container are themselves created
 * without their constructors: every hub controller is `final` (so PHPUnit cannot
 * mock it) and most take three-to-six collaborators. That is sufficient here
 * because these suites assert the ROUTE TABLE and the MIDDLEWARE GATE — an
 * unauthenticated or non-admin request short-circuits inside
 * {@see AuthMiddleware}/{@see AdminMiddleware} and never reaches a handler. The
 * middleware themselves are REAL: a real {@see JwtHandler} minting real HS256
 * tokens, the real `AuthMiddleware`, the real `AdminMiddleware`, and the real
 * {@see HubProtocolMiddleware}, so the 401/403/400 outcomes come from production
 * auth code. Positive (200) round-trips through a registered route are covered
 * separately by {@see \Phlix\Hub\Tests\Unit\Http\UserThrottleRouteRegistrationTest}
 * and by the per-controller suites.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
abstract class RouteRegistrationTestCase extends TestCase
{
    /** A >=32-byte HS256 secret so the real JwtHandler accepts it. */
    protected const JWT_SECRET = 'S174-route-registration-secret-key-0123456789';

    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    protected UserRepository $users;

    /** @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject */
    protected AuditLogger $audit;

    protected JwtHandler $jwt;

    protected AuthMiddleware $authMiddleware;

    protected AdminMiddleware $adminMiddleware;

    /**
     * The REAL S90 signature gate (S91 wires it onto `POST /alexa/skill`).
     *
     * ⚠ It must be PRESET into the stub container rather than left to
     * {@see RouteRegistrationContainer}'s `newInstanceWithoutConstructor()`
     * fallback. A reflection-built instance leaves the readonly `$logger`
     * UNINITIALISED, so the first `reject()` throws `Error`, the middleware's own
     * fail-closed `catch (\Throwable)` re-enters `reject()`, and that throws
     * again — the suite would see an uncatchable error rather than the 400 the
     * gate is supposed to answer with.
     */
    protected AlexaSignatureMiddleware $alexaSignatureMiddleware;

    /** Records what the signature gate audited, so nothing needs a database. */
    protected RecordingAlexaRejectionAuditor $alexaAuditor;

    protected RouteRegistrationContainer $container;

    protected function setUp(): void
    {
        parent::setUp();

        // AuthMiddleware memoises user existence in a per-process static cache;
        // clear it so a previous test's mock cannot answer this one's probe.
        AuthMiddleware::resetCache();

        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);
        $this->jwt   = new JwtHandler(self::JWT_SECRET);

        // Every subject of these suites is authenticated-but-NOT-admin or
        // entirely unauthenticated, so a single stubbing of each is enough.
        $this->users->method('userExists')->willReturn(true);
        $this->users->method('findAdminById')->willReturn(null);

        $this->authMiddleware  = new AuthMiddleware($this->jwt, $this->users);
        $this->adminMiddleware = new AdminMiddleware($this->users, $this->audit);

        // A real gate with real collaborators and no I/O: a StructuredLogger with
        // no handlers, a fetcher that opens no socket, and a limiter whose budget
        // is far above anything a route suite dispatches — these suites assert
        // the GATE, not the limit, and a stingy budget here would turn an
        // unrelated test's route count into a flaky 429.
        $this->alexaAuditor = new RecordingAlexaRejectionAuditor();
        $this->alexaSignatureMiddleware = new AlexaSignatureMiddleware(
            new RecordingCertChainFetcher(null),
            new StructuredLogger('alexa-route-test', []),
            new RateLimiter(60, 100000, 1000),
            $this->alexaAuditor,
        );

        $this->container = new RouteRegistrationContainer([
            AuthMiddleware::class            => $this->authMiddleware,
            AdminMiddleware::class           => $this->adminMiddleware,
            AlexaSignatureMiddleware::class  => $this->alexaSignatureMiddleware,
        ]);
    }

    protected function tearDown(): void
    {
        AuthMiddleware::resetCache();
        parent::tearDown();
    }

    /**
     * Run ONE real private `Application::register*Routes()` method against a
     * fresh {@see Router} and return that router.
     *
     * @param string $registrar Name of the private registrar method.
     */
    protected function runRegistrar(string $registrar): Router
    {
        $router = new Router();
        $app    = $this->applicationShell($router);

        $args = in_array($registrar, RouteManifest::REGISTRARS_TAKING_AUTH_MIDDLEWARE, true)
            ? [$this->authMiddleware]
            : [];

        $method = new ReflectionMethod(Application::class, $registrar);
        $method->setAccessible(true);
        $method->invokeArgs($app, $args);

        return $router;
    }

    /**
     * Build the Application shell: skip the constructor (it would register the
     * whole route table and demand every controller from a real container) and
     * inject only the three properties a registrar touches.
     */
    private function applicationShell(Router $router): Application
    {
        $reflection = new ReflectionClass(Application::class);
        $app        = $reflection->newInstanceWithoutConstructor();

        $routerProp = $reflection->getProperty('router');
        $routerProp->setAccessible(true);
        $routerProp->setValue($app, $router);

        $containerProp = $reflection->getProperty('container');
        $containerProp->setAccessible(true);
        $containerProp->setValue($app, $this->container);

        // registerRoutes() reads config['public_root'] to build SharedUiController.
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($app, ['public_root' => dirname(__DIR__, 4) . '/public']);

        return $app;
    }

    /**
     * The `"METHOD path"` set a router actually holds, as produced by the
     * production registrar.
     *
     * @return list<string>
     */
    protected function registeredKeys(Router $router): array
    {
        $keys = [];
        foreach ($router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $keys[] = $method . ' ' . $route['path'];
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * Build a request. Pass `$userId` to mint a real HS256 access token for it
     * (the mocked repository reports every id as existing, and none as admin).
     */
    protected function request(string $method, string $url, ?string $userId = null): Request
    {
        $request         = new Request();
        $request->method = $method;
        $request->path   = $url;
        if ($userId !== null) {
            $token = $this->jwt->createAccessToken($userId);
            // AuthMiddleware::extractToken() reads the pre-parsed $bearerToken
            // (populated by Request::fromWorkerman()/fromGlobals() from the
            // Authorization header); set both to mirror a real request.
            $request->headers['authorization'] = 'Bearer ' . $token;
            $request->bearerToken              = $token;
        }

        return $request;
    }

    /**
     * A request carrying the `Accept-Phlix-Protocol: v1` header the
     * {@see HubProtocolMiddleware} demands.
     */
    protected function hubProtocolRequest(string $method, string $url, ?string $userId = null): Request
    {
        $request = $this->request($method, $url, $userId);
        $request->headers[HubProtocolMiddleware::HEADER_NAME] = HubProtocolMiddleware::REQUIRED_VERSION;

        return $request;
    }

    /**
     * Dispatch and return the response, failing loudly on a 404 so a deleted
     * route reports as "the registrar no longer registers this" rather than as
     * an opaque status mismatch.
     */
    protected function dispatchExpectingRegistered(Router $router, Request $request, string $why): Response
    {
        $response = $router->dispatch($request);

        self::assertNotSame(
            404,
            $response->statusCode,
            $why . ' — a 404 means the production registrar no longer registers '
            . $request->method . ' ' . $request->path,
        );

        return $response;
    }
}
