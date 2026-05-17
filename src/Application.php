<?php

declare(strict_types=1);

namespace Phlex\Hub;

use Phlex\Hub\Common\Logger\LogChannels;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Phlex\Hub\Health\HealthController;
use Phlex\Hub\Http\Controllers\AuthController;
use Phlex\Hub\Http\Controllers\HubJwksController;
use Phlex\Hub\Http\Controllers\MeController;
use Phlex\Hub\Http\Controllers\PageController;
use Phlex\Hub\Http\Controllers\ServerClaimController;
use Phlex\Hub\Http\Controllers\ServerController;
use Phlex\Hub\Http\Controllers\ServerListController;
use Phlex\Hub\Http\Controllers\ServerManageController;
use Phlex\Hub\Http\Controllers\RelayController;
use Phlex\Hub\Http\Controllers\SubdomainController;
use Phlex\Hub\Http\Middleware\AuthMiddleware;
use Phlex\Hub\Http\Middleware\EnrollmentJwtMiddleware;
use Phlex\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlex\Hub\Http\Request;
use Phlex\Hub\Http\Response;
use Phlex\Hub\Http\Router;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

/**
 * Phlex Hub main application bootstrap.
 *
 * Wires the HTTP router with the public surface as of B.7:
 *
 *  - `GET /health`                — service health JSON.
 *  - `GET /`                       — landing page (SSR).
 *  - `GET /signup` / `POST /signup` — signup form + submission.
 *  - `GET /login`  / `POST /login`  — login form + submission.
 *  - `POST /logout`                — clear cookies + redirect.
 *  - `GET /my-servers`             — protected dashboard (empty in B.7).
 *  - `POST /api/v1/auth/signup`    — JSON signup.
 *  - `POST /api/v1/auth/login`     — JSON login.
 *  - `POST /api/v1/auth/logout`    — JSON logout.
 *  - `POST /api/v1/auth/refresh`   — refresh access token.
 *  - `GET  /api/v1/me`             — current user JSON (protected).
 *
 * @package Phlex\Hub
 * @since 0.1.0
 */
final class Application
{
    private Router $router;

    /**
     * @param ContainerInterface   $container PSR-11 container.
     * @param array<string, mixed> $config    Server config slice (host, port, workers, …).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $config,
    ) {
        $this->router = new Router();
        $this->registerRoutes();
    }

    /**
     * Register every route the hub exposes today.
     */
    private function registerRoutes(): void
    {
        $health = $this->container->get(HealthController::class);
        if (!$health instanceof HealthController) {
            throw new \RuntimeException('Container returned an unexpected HealthController instance');
        }

        $this->router->get('/health', static function () use ($health): Response {
            return (new Response())->json($health());
        });

        // SSR pages.
        $pages = $this->resolvePageController();
        $this->router->get('/', static fn (Request $r): Response => $pages($r));
        $this->router->get('/signup', static fn (Request $r): Response => $pages($r));
        $this->router->get('/login', static fn (Request $r): Response => $pages($r));

        // Auth form handlers (SSR).
        $auth = $this->resolveAuthController();
        $this->router->post('/signup', static fn (Request $r): Response => $auth($r));
        $this->router->post('/login', static fn (Request $r): Response => $auth($r));
        $this->router->post('/logout', static fn (Request $r): Response => $auth($r));

        // JSON API.
        $this->router->post('/api/v1/auth/signup', static fn (Request $r): Response => $auth($r));
        $this->router->post('/api/v1/auth/login', static fn (Request $r): Response => $auth($r));
        $this->router->post('/api/v1/auth/logout', static fn (Request $r): Response => $auth($r));
        $this->router->post('/api/v1/auth/refresh', static fn (Request $r): Response => $auth($r));

        // Protected pages + API.
        $authMiddleware = $this->resolveAuthMiddleware();
        $this->router->group('/my-servers', function (Router $r) use ($pages): void {
            $r->get('', static fn (Request $req): Response => $pages($req));
        }, [$authMiddleware]);

        $this->router->group('/claim-server', static function (Router $r) use ($pages): void {
            $r->get('', static fn (Request $req): Response => $pages($req));
        }, [$authMiddleware]);

        $me = $this->resolveMeController();
        $serverList = $this->resolveServerListController();
        $serverManage = $this->resolveServerManageController();
        $this->router->group('/api/v1', function (Router $r) use ($me, $serverList, $serverManage): void {
            $r->get('/me', static fn (Request $req): Response => $me($req));
            $r->get('/me/servers', static fn (Request $req): Response => $serverList($req));
            $r->delete(
                '/me/servers/{id}',
                static fn (Request $req, array $params): Response =>
                    $serverManage->deleteServer($req, $params),
            );
            $r->get(
                '/me/servers/{id}/access-info',
                static fn (Request $req, array $params): Response =>
                    $serverManage->accessInfo($req, $params),
            );
        }, [$authMiddleware]);

        // Server-claim and server routes (Phase C.3).
        $this->registerServerRoutes($authMiddleware);
    }

    private function resolvePageController(): PageController
    {
        $controller = $this->container->get(PageController::class);
        if (!$controller instanceof PageController) {
            throw new \RuntimeException('Container returned an unexpected PageController instance');
        }
        return $controller;
    }

    private function resolveAuthController(): AuthController
    {
        $controller = $this->container->get(AuthController::class);
        if (!$controller instanceof AuthController) {
            throw new \RuntimeException('Container returned an unexpected AuthController instance');
        }
        return $controller;
    }

    private function resolveMeController(): MeController
    {
        $controller = $this->container->get(MeController::class);
        if (!$controller instanceof MeController) {
            throw new \RuntimeException('Container returned an unexpected MeController instance');
        }
        return $controller;
    }

    private function resolveServerListController(): ServerListController
    {
        $controller = $this->container->get(ServerListController::class);
        if (!$controller instanceof ServerListController) {
            throw new \RuntimeException('Container returned an unexpected ServerListController instance');
        }
        return $controller;
    }

    private function resolveServerManageController(): ServerManageController
    {
        $controller = $this->container->get(ServerManageController::class);
        if (!$controller instanceof ServerManageController) {
            throw new \RuntimeException('Container returned an unexpected ServerManageController instance');
        }
        return $controller;
    }

    private function resolveAuthMiddleware(): AuthMiddleware
    {
        $middleware = $this->container->get(AuthMiddleware::class);
        if (!$middleware instanceof AuthMiddleware) {
            throw new \RuntimeException('Container returned an unexpected AuthMiddleware instance');
        }
        return $middleware;
    }

    /**
     * Register server-claim and server lifecycle routes.
     */
    private function registerServerRoutes(AuthMiddleware $authMiddleware): void
    {
        $hubProtocol = new HubProtocolMiddleware();
        $enrollmentJwt = $this->resolveEnrollmentJwtMiddleware();
        $serverClaimController = $this->resolveServerClaimController();
        $serverController = $this->resolveServerController();
        $hubJwksController = $this->resolveHubJwksController();
        $relayController = $this->resolveRelayController();
        $subdomainController = $this->resolveSubdomainController();

        // JWKS — public.
        $this->router->get('/.well-known/jwks.json', static fn (Request $req) => $hubJwksController($req));

        // Public server-claim initiation (server has no JWT yet).
        $this->router->group('/api/v1', static function (Router $r) use ($serverClaimController): void {
            $r->post(
                '/server-claims/new',
                static fn (Request $req) => $serverClaimController->newClaim($req),
            );
        }, [$hubProtocol]);

        // User-authenticated claim (user pastes claim code).
        $this->router->group('/api/v1', static function (Router $r) use ($serverClaimController): void {
            $r->post(
                '/server-claims/claim',
                static fn (Request $req) => $serverClaimController->claim($req),
            );
        }, [$authMiddleware, $hubProtocol]);

        // Server-authenticated routes.
        $serverGroup = static function (Router $r) use ($serverController): void {
            $r->post(
                '/servers/{id}/heartbeat',
                static function (Request $req, array $params) use ($serverController): Response {
                    /** @var array<string, string> $typedParams */
                    $typedParams = $params;
                    return $serverController->heartbeat($req, $typedParams);
                },
            );
            $r->get(
                '/servers/{id}/info',
                static function (Request $req, array $params) use ($serverController): Response {
                    /** @var array<string, string> $typedParams */
                    $typedParams = $params;
                    return $serverController->info($req, $typedParams);
                },
            );
            $r->delete(
                '/servers/{id}',
                static function (Request $req, array $params) use ($serverController): Response {
                    /** @var array<string, string> $typedParams */
                    $typedParams = $params;
                    return $serverController->disconnect($req, $typedParams);
                },
            );
        };

        $this->router->group('/api/v1', $serverGroup, [$enrollmentJwt, $hubProtocol]);

        // Relay tunnel endpoint — server-initiated WSS (Phase C.6).
        $this->router->post('/servers/{id}/relay', static function (
            Request $req,
            array $params,
        ) use ($relayController): Response {
            /** @var array<string, string> $typedParams */
            $typedParams = $params;
            return $relayController->handle($req, $typedParams);
        });

        // Subdomain allocation and revocation (Phase C.8).
        $this->router->post('/servers/{id}/subdomain', static function (
            Request $req,
            array $params,
        ) use ($subdomainController): Response {
            /** @var array<string, string> $typedParams */
            $typedParams = $params;
            return $subdomainController->allocate($req, $typedParams);
        });

        $this->router->delete('/servers/{id}/subdomain', static function (
            Request $req,
            array $params,
        ) use ($subdomainController): Response {
            /** @var array<string, string> $typedParams */
            $typedParams = $params;
            return $subdomainController->revoke($req, $typedParams);
        });
    }

    private function resolveEnrollmentJwtMiddleware(): EnrollmentJwtMiddleware
    {
        $middleware = $this->container->get(EnrollmentJwtMiddleware::class);
        if (!$middleware instanceof EnrollmentJwtMiddleware) {
            throw new \RuntimeException('Container returned an unexpected EnrollmentJwtMiddleware instance');
        }
        return $middleware;
    }

    private function resolveServerClaimController(): ServerClaimController
    {
        $controller = $this->container->get(ServerClaimController::class);
        if (!$controller instanceof ServerClaimController) {
            throw new \RuntimeException('Container returned an unexpected ServerClaimController instance');
        }
        return $controller;
    }

    private function resolveServerController(): ServerController
    {
        $controller = $this->container->get(ServerController::class);
        if (!$controller instanceof ServerController) {
            throw new \RuntimeException('Container returned an unexpected ServerController instance');
        }
        return $controller;
    }

    private function resolveHubJwksController(): HubJwksController
    {
        $controller = $this->container->get(HubJwksController::class);
        if (!$controller instanceof HubJwksController) {
            throw new \RuntimeException('Container returned an unexpected HubJwksController instance');
        }
        return $controller;
    }

    private function resolveRelayController(): RelayController
    {
        $controller = $this->container->get(RelayController::class);
        if (!$controller instanceof RelayController) {
            throw new \RuntimeException('Container returned an unexpected RelayController instance');
        }
        return $controller;
    }

    private function resolveSubdomainController(): SubdomainController
    {
        $controller = $this->container->get(SubdomainController::class);
        if (!$controller instanceof SubdomainController) {
            throw new \RuntimeException('Container returned an unexpected SubdomainController instance');
        }
        return $controller;
    }

    /**
     * Get the underlying router (used by tests and B.7 route registration).
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Boot a Workerman HTTP worker that dispatches each request through
     * the router.
     */
    public function boot(): void
    {
        /** @var mixed $hostRaw */
        $hostRaw = $this->config['host'] ?? '0.0.0.0';
        $host = is_string($hostRaw) ? $hostRaw : '0.0.0.0';
        /** @var mixed $portRaw */
        $portRaw = $this->config['port'] ?? 8800;
        $port = is_int($portRaw) ? $portRaw : (int) (is_numeric($portRaw) ? $portRaw : 8800);
        /** @var mixed $workersRaw */
        $workersRaw = $this->config['workers'] ?? 2;
        $workers = is_int($workersRaw) ? $workersRaw : (int) (is_numeric($workersRaw) ? $workersRaw : 2);

        $worker = new Worker(sprintf('http://%s:%d', $host, $port));
        $worker->count = $workers;
        $worker->name = 'phlex-hub-http';

        $router = $this->router;
        $logger = $this->resolveHttpLogger();

        $worker->onMessage = static function (
            TcpConnection $connection,
            WorkermanRequest $request,
        ) use (
            $router,
            $logger,
        ): void {
            try {
                $hubRequest = Request::fromWorkerman($request);
                $response = $router->dispatch($hubRequest);
                $connection->send($response->toWorkermanResponse());
            } catch (Throwable $e) {
                $logger?->error('Unhandled exception in hub request', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                $error = (new Response())
                    ->status(500)
                    ->json(['error' => 'Internal Server Error', 'message' => $e->getMessage()]);
                $connection->send($error->toWorkermanResponse());
            }
        };

        Worker::runAll();
    }

    /**
     * Resolve the HTTP-channel logger if the container can provide it,
     * returning null when it cannot (e.g. in unit tests with a stub
     * container).
     */
    private function resolveHttpLogger(): ?StructuredLogger
    {
        try {
            /** @var mixed $logger */
            $logger = $this->container->get('logger.' . LogChannels::HTTP);
            return $logger instanceof StructuredLogger ? $logger : null;
        } catch (Throwable) {
            return null;
        }
    }
}
