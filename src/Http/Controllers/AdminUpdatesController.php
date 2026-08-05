<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckService;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Core update-check admin API (S75 / updates.md #48).
 *
 * `GET /api/v1/admin/updates/status`    — current version, latest known
 * version, whether an update is available, when the last check ran, and the
 * copy-to-clipboard update command.
 *
 * `PUT /api/v1/admin/updates/settings`  — persist the `updates.check_enabled`
 * toggle. Body: `{"checkEnabled": bool}`.
 *
 * ## Two properties this controller must keep
 *
 * 1. **No outbound I/O.** `status()` reads persisted state only
 *    ({@see CoreUpdateCheckService::status()}); the network fetch belongs to
 *    the maintenance worker. An HTTP handler in a resident-memory Workerman
 *    process must not wait on a third-party host.
 * 2. **No apply action.** There is deliberately no `POST .../apply`: the hub
 *    never runs git/composer/systemctl. `updateCommand` in the status payload
 *    is a string for the operator to paste into a root shell.
 *
 * Gated `[AuthMiddleware, AdminMiddleware]` at the route group in
 * {@see \Phlix\Hub\Application::registerAdminUpdatesRoutes()}, plus this
 * controller's own {@see requireAdmin()} defence-in-depth (mirrors
 * {@see UserQuotaController}).
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   S75 (core update check)
 */
final class AdminUpdatesController
{
    /**
     * @param CoreUpdateCheckService $updates Update-check service.
     * @param UserRepository         $users   User lookup for the admin gate.
     * @param AuditLogger            $audit   Audit sink for denials + setting changes.
     */
    public function __construct(
        private readonly CoreUpdateCheckService $updates,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * `GET /api/v1/admin/updates/status` — report the last known update state.
     *
     * Status codes: 200 ok · 401 unauthenticated · 403 not admin.
     *
     * @param Request $request Inbound request.
     *
     * @return Response
     */
    public function status(Request $request): Response
    {
        $forbid = $this->requireAdmin($request);
        if ($forbid !== null) {
            return $forbid;
        }

        return (new Response())->json([
            'success' => true,
            'data'    => $this->updates->status()->toArray(),
        ]);
    }

    /**
     * `PUT /api/v1/admin/updates/settings` — persist the check toggle.
     *
     * Body: `{"checkEnabled": bool}`. The response echoes the re-resolved
     * status so the admin UI can repaint without a follow-up GET.
     *
     * Status codes: 200 ok · 400 invalid payload · 401 unauthenticated ·
     * 403 not admin.
     *
     * @param Request $request Inbound request.
     *
     * @return Response
     */
    public function updateSettings(Request $request): Response
    {
        $forbid = $this->requireAdmin($request);
        if ($forbid !== null) {
            return $forbid;
        }

        $body = $request->body;
        if (!array_key_exists('checkEnabled', $body)) {
            return (new Response())->status(400)->json([
                'success' => false,
                'error'   => 'Invalid payload',
                'code'    => 'invalid_payload',
                'message' => 'Body must contain a boolean "checkEnabled".',
            ]);
        }

        /** @var mixed $value */
        $value = $body['checkEnabled'];
        if (!is_bool($value)) {
            return (new Response())->status(400)->json([
                'success' => false,
                'error'   => 'Validation failed',
                'errors'  => [
                    'checkEnabled' => sprintf('Expected type bool, got %s.', gettype($value)),
                ],
            ]);
        }

        $this->updates->setCheckEnabled($value);
        $this->audit->logAdminAction(
            (string) $request->userId,
            'updates.settings.update',
            CoreUpdateCheckService::SETTING_CHECK_ENABLED,
            ['check_enabled' => $value],
        );

        return (new Response())->json([
            'success' => true,
            'message' => 'Settings updated.',
            'data'    => $this->updates->status()->toArray(),
        ]);
    }

    /**
     * Defence-in-depth admin gate, mirroring {@see UserQuotaController}: the
     * route group is already `[auth, admin]`, but a controller that mutates
     * configuration must not depend on a route table staying correct.
     *
     * @param Request $request Inbound request.
     *
     * @return Response|null Denial response, or null when the caller is an admin.
     */
    private function requireAdmin(Request $request): ?Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        if ($this->users->findAdminById($userId) === null) {
            $this->audit->logPermissionDenied($userId, 'admin.updates', $request->method);

            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code'  => 'admin_required',
            ]);
        }

        return null;
    }
}
