<?php

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Health\HealthController;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Http\Controllers\HubJwksController;
use Phlix\Hub\Http\Controllers\InviteLinkController;
use Phlix\Hub\Http\Controllers\LibraryController;
use Phlix\Hub\Http\Controllers\LibraryShareController;
use Phlix\Hub\Http\Controllers\MeController;
use Phlix\Hub\Http\Controllers\PageController;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Controllers\ServerController;
use Phlix\Hub\Http\Controllers\ServerDetailController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerManageController;
use Phlix\Hub\Http\Controllers\SubdomainController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Middleware\EnrollmentJwtMiddleware;
use Phlix\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

/**
 * Phlix Hub main application bootstrap.
 *
 * Wires the HTTP router with the public surface:
 *
 *  - `GET /health`                — service health JSON.
 *  - `GET /`                       — landing page (SSR).
 *  - `GET /signup` / `POST /signup` — signup form + submission.
 *  - `GET /login`  / `POST /login`  — login form + submission.
 *  - `POST /logout`                — clear cookies + redirect.
 *  - `GET /my-servers`             — protected dashboard.
 *  - `POST /api/v1/auth/signup`    — JSON signup.
 *  - `POST /api/v1/auth/login`     — JSON login.
 *  - `POST /api/v1/auth/logout`    — JSON logout.
 *  - `POST /api/v1/auth/refresh`   — refresh access token.
 *  - `GET  /api/v1/me`             — current user JSON (protected).
 *
 * @package Phlix\Hub
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

        $this->router->group('/invite-links', static function (Router $r) use ($pages): void {
            $r->get('', static fn (Request $req): Response => $pages($req));
        }, [$authMiddleware]);

        $this->router->group('/servers/{id}', static function (Router $r) use ($pages): void {
            $r->get('', static fn (Request $req): Response => $pages($req));
        }, [$authMiddleware]);

        $me = $this->resolveMeController();
        $serverList = $this->resolveServerListController();
        $serverManage = $this->resolveServerManageController();
        $serverDetail = $this->resolveServerDetailController();
        $libraryController = $this->resolveLibraryController();
        $this->router->group('/api/v1', function (Router $r) use ($me, $serverList, $serverManage, $serverDetail, $libraryController): void {
            $r->get('/me', static fn (Request $req): Response => $me($req));
            $r->get('/me/servers', static fn (Request $req): Response => $serverList($req));
            $r->delete(
                '/me/servers/{id}',
                static function (Request $req, array $params) use ($serverManage): Response {
                    /** @var array<string, string> $typedParams */
                    $typedParams = $params;
                    return $serverManage->deleteServer($req, $typedParams);
                },
            );
            $r->get(
                '/me/servers/{id}/access-info',
                static function (Request $req, array $params) use ($serverManage): Response {
                    /** @var array<string, string> $typedParams */
                    $typedParams = $params;
                    return $serverManage->accessInfo($req, $typedParams);
                },
            );
            $r->get(
                '/me/libraries',
                static function (Request $req) use ($libraryController): Response {
                    return $libraryController->listForServer($req);
                },
            );
            $r->get(
                '/me/servers/{id}',
                static function (Request $req, array $params) use ($serverDetail): Response {
                    /** @var array<string, string> $typedParams */
                    $typedParams = $params;
                    return $serverDetail->getServerDetail($req, $typedParams);
                },
            );
        }, [$authMiddleware]);

        // Server-claim and server routes.
        $this->registerServerRoutes($authMiddleware);

        // Library sharing routes.
        $this->registerSharingRoutes($authMiddleware);

        // Invite link routes.
        $this->registerInviteLinkRoutes();

        // Media request routes.
        $this->registerRequestRoutes();
    }

    /**
     * Register the media-request routes — both the user surface under
     * `/api/v1/me/requests` and the admin queue under
     * `/api/v1/admin/requests`. Also wires the SSR pages at `/requests`
     * (user) and `/admin/requests` (admin queue).
     */
    private function registerRequestRoutes(): void
    {
        $authMiddleware = $this->resolveAuthMiddleware();
        $requestController = $this->resolveRequestController();
        $pages = $this->resolvePageController();

        // SSR pages.
        $this->router->group('/requests', static function (Router $r) use ($pages): void {
            $r->get('', static fn (Request $req): Response => $pages($req));
        }, [$authMiddleware]);

        $this->router->group('/admin/requests', static function (Router $r) use ($pages): void {
            $r->get('', static fn (Request $req): Response => $pages($req));
        }, [$authMiddleware]);

        // User-scoped JSON API.
        $this->router->group('/api/v1/me/requests', static function (Router $r) use ($requestController): void {
            $r->post('', static function (Request $req, array $params) use ($requestController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $requestController->createRequest($req, $typedParams);
            });
            $r->get('', static function (Request $req, array $params) use ($requestController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $requestController->listMyRequests($req, $typedParams);
            });
            $r->get('/{id}', static function (Request $req, array $params) use ($requestController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $requestController->getMyRequest($req, $typedParams);
            });
            $r->delete('/{id}', static function (Request $req, array $params) use ($requestController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $requestController->deleteMyRequest($req, $typedParams);
            });
        }, [$authMiddleware]);

        // Admin queue + actions. Gated by AdminMiddleware in addition to the
        // controller's own requireAdmin() so the group is protected even if a
        // future handler in this group forgets the inline check.
        $adminMiddleware = $this->resolveAdminMiddleware();
        $this->router->group('/api/v1/admin/requests', static function (Router $r) use ($requestController): void {
            $r->get('', static function (Request $req, array $params) use ($requestController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $requestController->listAdminRequests($req, $typedParams);
            });
            $r->post('/{id}/approve', static function (Request $req, array $params) use ($requestController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $requestController->approveRequest($req, $typedParams);
            });
            $r->post('/{id}/deny', static function (Request $req, array $params) use ($requestController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $requestController->denyRequest($req, $typedParams);
            });
        }, [$authMiddleware, $adminMiddleware]);
    }

    private function resolveAdminMiddleware(): AdminMiddleware
    {
        $middleware = $this->container->get(AdminMiddleware::class);
        if (!$middleware instanceof AdminMiddleware) {
            throw new \RuntimeException('Container returned an unexpected AdminMiddleware instance');
        }
        return $middleware;
    }

    private function resolveRequestController(): RequestController
    {
        $controller = $this->container->get(RequestController::class);
        if (!$controller instanceof RequestController) {
            throw new \RuntimeException('Container returned an unexpected RequestController instance');
        }
        return $controller;
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

    private function resolveServerDetailController(): ServerDetailController
    {
        $controller = $this->container->get(ServerDetailController::class);
        if (!$controller instanceof ServerDetailController) {
            throw new \RuntimeException('Container returned an unexpected ServerDetailController instance');
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

        // Relay tunnel endpoint — server-initiated WSS.
        $this->router->post('/servers/{id}/relay', static function (
            Request $req,
            array $params,
        ) use ($relayController): Response {
            /** @var array<string, string> $typedParams */
            $typedParams = $params;
            return $relayController->handle($req, $typedParams);
        });

        // Client relay mount — client-initiated WSS to reach servers via hub.
        $clientMountController = $this->resolveClientMountController();
        $this->router->get('/client/{server_id}', static function (
            Request $req,
            array $params,
        ) use ($clientMountController): Response {
            /** @var array<string, string> $typedParams */
            $typedParams = $params;
            return $clientMountController->handle($req, $typedParams);
        });

        // Subdomain allocation and revocation.
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

    private function resolveClientMountController(): ClientMountController
    {
        $controller = $this->container->get(ClientMountController::class);
        if (!$controller instanceof ClientMountController) {
            throw new \RuntimeException('Container returned an unexpected ClientMountController instance');
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

    private function resolveLibraryShareController(): LibraryShareController
    {
        $controller = $this->container->get(LibraryShareController::class);
        if (!$controller instanceof LibraryShareController) {
            throw new \RuntimeException('Container returned an unexpected LibraryShareController instance');
        }
        return $controller;
    }

    private function resolveLibraryController(): LibraryController
    {
        $controller = $this->container->get(LibraryController::class);
        if (!$controller instanceof LibraryController) {
            throw new \RuntimeException('Container returned an unexpected LibraryController instance');
        }
        return $controller;
    }

    private function resolveInviteLinkController(): InviteLinkController
    {
        $controller = $this->container->get(InviteLinkController::class);
        if (!$controller instanceof InviteLinkController) {
            throw new \RuntimeException('Container returned an unexpected InviteLinkController instance');
        }
        return $controller;
    }

    /**
     * Register library sharing routes.
     */
    private function registerSharingRoutes(AuthMiddleware $authMiddleware): void
    {
        $pages = $this->resolvePageController();
        $shareController = $this->resolveLibraryShareController();

        // SSR pages.
        $this->router->group('/shared-with-me', static function (Router $r) use ($pages): void {
            $r->get('', static fn (Request $req): Response => $pages($req));
        }, [$authMiddleware]);

        $this->router->group('/manage-shares', static function (Router $r) use ($pages): void {
            $r->get('', static fn (Request $req): Response => $pages($req));
        }, [$authMiddleware]);

        // JSON API for shares.
        $this->router->group('/api/v1/me/shares', static function (Router $r) use ($shareController): void {
            $r->post('/', static fn (Request $req): Response => $shareController->createShare($req));
            $r->get('/', static fn (Request $req): Response => $shareController->listShares($req));
            $r->delete('/{id}', static function (Request $req, array $params) use ($shareController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $shareController->deleteShare($req, $typedParams);
            });
            $r->patch('/{id}', static function (Request $req, array $params) use ($shareController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $shareController->updateShare($req, $typedParams);
            });
        }, [$authMiddleware]);
    }

    /**
     * Register invite link routes.
     */
    private function registerInviteLinkRoutes(): void
    {
        $inviteController = $this->resolveInviteLinkController();

        // GET /invite/{token} — public invite acceptance page.
        $this->router->get(
            '/invite/{token}',
            static function (Request $req, array $params) use ($inviteController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $inviteController->showAcceptInvitePage($req, $typedParams);
            },
        );

        // JSON API for invite links (protected).
        $authMiddleware = $this->resolveAuthMiddleware();
        $this->router->group('/api/v1/me/invite-links', static function (Router $r) use ($inviteController): void {
            $r->post('/', static fn (Request $req): Response => $inviteController->createInviteLink($req));
            $r->get('/', static fn (Request $req): Response => $inviteController->listInviteLinks($req));
            $r->delete('/{id}', static function (Request $req, array $params) use ($inviteController): Response {
                /** @var array<string, string> $typedParams */
                $typedParams = $params;
                return $inviteController->deleteInviteLink($req, $typedParams);
            });
        }, [$authMiddleware]);
    }

    /**
     * Get the underlying router (used by tests and route registration).
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
        $worker->name = 'phlix-hub-http';

        $router = $this->router;
        $logger = $this->resolveHttpLogger();

        // Document root for the static-file fast path. Anything under
        // public/ that resolves to an existing non-PHP file is served
        // directly; anything else falls through to the router. The path
        // comes from config (set by start.php) — falls back to <repo>/public.
        /** @var mixed $publicRootRaw */
        $publicRootRaw = $this->config['public_root'] ?? null;
        $publicRoot = is_string($publicRootRaw) && is_dir($publicRootRaw)
            ? rtrim($publicRootRaw, DIRECTORY_SEPARATOR)
            : rtrim(dirname(__DIR__) . '/public', DIRECTORY_SEPARATOR);

        $worker->onMessage = static function (
            TcpConnection $connection,
            WorkermanRequest $request,
        ) use (
            $router,
            $logger,
            $publicRoot,
        ): void {
            try {
                // 1. Static-file fast path. Serve files under public/
                //    directly (CSS, JS, images, fonts). Refuses traversal
                //    via realpath() prefix check; refuses raw .php files.
                $path = $request->path();
                if ($path !== '' && $path !== '/' && !str_starts_with($path, '/api/')) {
                    $candidate = $publicRoot . $path;
                    $real = realpath($candidate);
                    if (
                        $real !== false
                        && str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR)
                        && is_file($real)
                        && strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'php'
                    ) {
                        // Extension-first MIME map — mime_content_type()
                        // sniffs content via libmagic and returns text/plain
                        // for CSS/JS/JSON/SVG, which makes the browser
                        // refuse to apply stylesheets / execute scripts.
                        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
                        $mimeMap = [
                            'css' => 'text/css; charset=utf-8',
                            'js' => 'application/javascript; charset=utf-8',
                            'mjs' => 'application/javascript; charset=utf-8',
                            'json' => 'application/json; charset=utf-8',
                            'html' => 'text/html; charset=utf-8',
                            'txt' => 'text/plain; charset=utf-8',
                            'xml' => 'application/xml; charset=utf-8',
                            'svg' => 'image/svg+xml',
                            'ico' => 'image/x-icon',
                            'png' => 'image/png',
                            'jpg' => 'image/jpeg',
                            'jpeg' => 'image/jpeg',
                            'gif' => 'image/gif',
                            'webp' => 'image/webp',
                            'woff' => 'font/woff',
                            'woff2' => 'font/woff2',
                            'ttf' => 'font/ttf',
                            'otf' => 'font/otf',
                            'pdf' => 'application/pdf',
                            'wasm' => 'application/wasm',
                        ];
                        $mime = $mimeMap[$ext] ?? null;
                        if ($mime === null && function_exists('mime_content_type')) {
                            $detected = mime_content_type($real);
                            if (is_string($detected) && $detected !== '') {
                                $mime = $detected;
                            }
                        }
                        $mime ??= 'application/octet-stream';
                        $resp = new \Workerman\Protocols\Http\Response(200, ['Content-Type' => $mime]);
                        $resp->withFile($real);
                        $connection->send($resp);
                        return;
                    }
                }

                // 2. Dynamic dispatch via the router.
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

        // Wire up runtime timers for relay services before starting workers
        HubServicesProvider::boot();

        // Start the relay WebSocket worker for server connections on port 8802.
        /** @var mixed $relayPortRaw */
        $relayPortRaw = $this->config['relay_port'] ?? 8802;
        $relayPort = is_int($relayPortRaw)
            ? $relayPortRaw
            : (int) (is_numeric($relayPortRaw) ? $relayPortRaw : 8802);
        $relayWorker = new RelayWorker($this->container, $relayPort);
        $relayWorker->start();

        // Start the client-facing relay WebSocket worker on port 8803. Remote
        // clients connect here (GET /client/{server_id}) to reach a server
        // through its outbound tunnel.
        /** @var mixed $clientRelayPortRaw */
        $clientRelayPortRaw = $this->config['client_relay_port'] ?? ClientRelayWorker::DEFAULT_PORT;
        $clientRelayPort = is_int($clientRelayPortRaw)
            ? $clientRelayPortRaw
            : (int) (is_numeric($clientRelayPortRaw) ? $clientRelayPortRaw : ClientRelayWorker::DEFAULT_PORT);
        $clientRelayWorker = new ClientRelayWorker($this->container, $clientRelayPort);
        $clientRelayWorker->start();

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
