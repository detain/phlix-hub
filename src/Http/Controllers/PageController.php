<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Common\WebPortal\PageRenderer;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * SSR page controller — serves the Smarty templates that back
 * `GET /signup`, `GET /login`, `GET /my-servers`, and `GET /claim-server`.
 *
 * Each invocation reads `$request->path` and dispatches to the matching
 * template render. Keeping this single-class-many-actions shape lets
 * us register one controller with the {@see \Phlix\Hub\Http\Router} per
 * path while keeping per-template assigns close together.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class PageController
{
    /**
     * @param PageRenderer     $renderer    Smarty wrapper.
     * @param AuthManager      $auth        Used to load the user record on protected pages.
     * @param ServerInfoHandler $serverInfo Used to fetch the user's servers for the dashboard.
     * @param AdminMiddleware  $admin       Reused to gate the SSR admin pages (its
     *                                      `checkAccess()` helper performs the same
     *                                      check + audit log as the API gate).
     */
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly AuthManager $auth,
        private readonly ServerInfoHandler $serverInfo,
        private readonly AdminMiddleware $admin,
    ) {
    }

    /**
     * Common variables every layout-aware template needs.
     *
     * Reads `$request->user` (populated by {@see \Phlix\Hub\Http\Middleware\AuthMiddleware}),
     * so this is silently `is_authenticated = false, is_admin = false` on
     * pages that aren't gated by AuthMiddleware (`/`, `/signup`, `/login`).
     *
     * @return array{is_authenticated: bool, is_admin: bool}
     */
    private function layoutContext(Request $request): array
    {
        $user = $request->user ?? [];
        $isAuthenticated = ($request->userId ?? '') !== '';
        /** @var mixed $flag */
        $flag = $user['is_admin'] ?? null;
        // The MySQL driver may return TINYINT(1) as either 1 or "1" depending
        // on the connection mode, so accept both (and a real boolean).
        $isAdmin = $isAuthenticated
            && ($flag === 1 || $flag === '1' || $flag === true);
        return [
            'is_authenticated' => $isAuthenticated,
            'is_admin'         => $isAdmin,
        ];
    }

    /**
     * Dispatch based on `$request->path`.
     */
    public function __invoke(Request $request): Response
    {
        return match (true) {
            $request->path === '/signup'           => $this->signup($request),
            $request->path === '/login'            => $this->login($request),
            $request->path === '/my-servers'       => $this->myServers($request),
            $request->path === '/claim-server'     => $this->claimServer($request),
            $request->path === '/requests'         => $this->requests($request),
            $request->path === '/admin/requests'   => $this->adminRequests($request),
            $request->path === '/invite-links'     => $this->inviteLinks($request),
            $request->path === '/manage-shares'    => $this->manageShares($request),
            $request->path === '/shared-with-me'   => $this->sharedWithMe($request),
            $request->path === '/hub-settings'      => $this->hubSettings($request),
            $request->path === '/'                  => $this->home($request),
            str_starts_with($request->path, '/servers/') => $this->serverDetail($request),
            default => (new Response())->status(404)->html('<h1>Not Found</h1>'),
        };
    }

    /**
     * `GET /` — landing page.
     */
    public function home(Request $request): Response
    {
        $html = $this->renderer->render('home/index.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }

    /**
     * `GET /signup` — render the signup form.
     */
    public function signup(Request $request): Response
    {
        $html = $this->renderer->render('auth/signup.tpl', [
            'username' => '',
            'email'    => '',
            ...$this->layoutContext($request),
        ]);
        return (new Response())->html($html);
    }

    /**
     * `GET /login` — render the login form.
     */
    public function login(Request $request): Response
    {
        $html = $this->renderer->render('auth/login.tpl', [
            'username' => '',
            ...$this->layoutContext($request),
        ]);
        return (new Response())->html($html);
    }

    /**
     * `GET /my-servers` — dashboard showing the user's claimed servers.
     *
     * The `servers` key is populated by fetching from ServerInfoHandler.
     * The template loops over `$servers` to render individual server cards.
     *
     * @param array<string, mixed> $servers Pre-fetched server list (optional, injected by router).
     */
    public function myServers(Request $request, array $servers = []): Response
    {
        $userId = $request->userId ?? '';

        if ($servers === [] && $userId !== '') {
            $dtos = $this->serverInfo->getServersForUser($userId);
            $servers = array_map(fn ($dto) => $dto->toPayload(), $dtos);
        }

        $user = $this->auth->getCurrentUser($userId);
        $html = $this->renderer->render('home/my-servers.tpl', [
            'user'    => $user ?? [],
            'servers' => $servers,
            ...$this->layoutContext($request),
        ]);
        return (new Response())->html($html);
    }

    /**
     * `GET /claim-server` — page with a form to claim a new server
     * using a claim code generated by the server's pairing flow.
     */
    public function claimServer(Request $request): Response
    {
        $html = $this->renderer->render('home/claim-server.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }

    /**
     * `GET /requests` — user "Request media" SSR page.
     */
    public function requests(Request $request): Response
    {
        $html = $this->renderer->render('home/requests.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }

    /**
     * `GET /admin/requests` — admin request queue SSR page.
     *
     * Reuses {@see AdminMiddleware::checkAccess()} so the SSR gate behaves
     * identically to the API gate (same DB lookup, same audit-log entry on
     * denial). Renders an HTML 403 with a link back to the dashboard rather
     * than the middleware's JSON 403 response, since the visitor here is a
     * browser, not an API client.
     */
    public function adminRequests(Request $request): Response
    {
        $status = $this->admin->checkAccess($request);
        if ($status === 403) {
            return (new Response())
                ->html(
                    '<h1>Forbidden</h1>'
                    . '<p>You need admin access to view the request queue.</p>'
                    . '<p><a href="/my-servers">Back to my servers</a></p>',
                    403,
                );
        }
        // $status === 401 cannot happen here in practice: the route is mounted
        // behind AuthMiddleware, which redirects unauthenticated visitors to
        // /login before this handler runs.

        $html = $this->renderer->render('home/admin-requests.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }

    /**
     * `GET /invite-links` — render the invite links management page.
     *
     * Data is fetched client-side from the API; the SSR page is a shell.
     */
    public function inviteLinks(Request $request): Response
    {
        $html = $this->renderer->render('home/invite-links.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }

    /**
     * `GET /manage-shares` — render the library shares management page.
     *
     * Data is fetched client-side from the API; the SSR page is a shell.
     */
    public function manageShares(Request $request): Response
    {
        $html = $this->renderer->render('home/manage-shares.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }

    /**
     * `GET /shared-with-me` — render the libraries shared with current user page.
     *
     * Data is fetched client-side from the API; the SSR page is a shell.
     */
    public function sharedWithMe(Request $request): Response
    {
        $html = $this->renderer->render('home/shared-with-me.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }

    /**
     * `GET /hub-settings` — render the hub admin settings page.
     *
     * Data is fetched client-side from `GET /api/v1/me/hub-settings`;
     * the SSR page is a shell rendered by JS.
     *
     * Access is gated by the admin middleware; unauthenticated or
     * non-admin visitors see a 403 HTML error.
     */
    public function hubSettings(Request $request): Response
    {
        $status = $this->admin->checkAccess($request);
        if ($status === 403) {
            return (new Response())
                ->html(
                    '<h1>Forbidden</h1>'
                    . '<p>You need admin access to view hub settings.</p>'
                    . '<p><a href="/my-servers">Back to my servers</a></p>',
                    403,
                );
        }

        $html = $this->renderer->render('home/hub-settings.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }

    /**
     * `GET /servers/{id}` — render the server detail page.
     *
     * The page is a shell; data is fetched client-side from
     * `GET /api/v1/me/servers/{id}`.
     */
    public function serverDetail(Request $request): Response
    {
        $html = $this->renderer->render('home/servers.tpl', $this->layoutContext($request));
        return (new Response())->html($html);
    }
}
