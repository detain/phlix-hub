<?php

declare(strict_types=1);

namespace Phlex\Hub\Http\Controllers;

use Phlex\Hub\Auth\AuthManager;
use Phlex\Hub\Common\WebPortal\PageRenderer;
use Phlex\Hub\Http\Request;
use Phlex\Hub\Http\Response;

/**
 * SSR page controller — serves the Smarty templates that back
 * `GET /signup`, `GET /login`, and `GET /my-servers`.
 *
 * Each invocation reads `$request->path` and dispatches to the matching
 * template render. Keeping this single-class-many-actions shape lets
 * us register one controller with the {@see \Phlex\Hub\Http\Router} per
 * path while keeping per-template assigns close together.
 *
 * @package Phlex\Hub\Http\Controllers
 * @since 0.2.0
 */
final class PageController
{
    /**
     * @param PageRenderer $renderer Smarty wrapper.
     * @param AuthManager  $auth     Used to load the user record on protected pages.
     */
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly AuthManager $auth,
    ) {
    }

    /**
     * Dispatch based on `$request->path`.
     */
    public function __invoke(Request $request): Response
    {
        return match (true) {
            $request->path === '/signup'      => $this->signup($request),
            $request->path === '/login'       => $this->login($request),
            $request->path === '/my-servers'  => $this->myServers($request),
            $request->path === '/'            => $this->home($request),
            default => (new Response())->status(404)->html('<h1>Not Found</h1>'),
        };
    }

    /**
     * `GET /` — landing page.
     */
    public function home(Request $request): Response
    {
        $html = $this->renderer->render('home/index.tpl', [
            'is_authenticated' => $request->userId !== null,
        ]);
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
        ]);
        return (new Response())->html($html);
    }

    /**
     * `GET /my-servers` — empty-state dashboard. The list itself is
     * populated by Phase C.4 — for now we render the empty-state copy.
     */
    public function myServers(Request $request): Response
    {
        $userId = $request->userId ?? '';
        $user = $this->auth->getCurrentUser($userId);
        $html = $this->renderer->render('home/my-servers.tpl', [
            'user'    => $user ?? [],
            'servers' => [],
        ]);
        return (new Response())->html($html);
    }
}
