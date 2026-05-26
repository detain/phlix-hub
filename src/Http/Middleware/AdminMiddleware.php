<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Gates an HTTP route group behind the `users.is_admin` flag.
 *
 * Ported from `phlix-server`'s
 * `\Phlix\Server\Http\Middleware\AdminMiddleware`. Expects to run AFTER
 * {@see AuthMiddleware} so {@see Request::$userId} is populated; emits
 * 401 when the upstream gave us no user, 403 when the user exists but
 * is not flagged admin.
 *
 * Every 403 the middleware emits is also written to the audit logger so
 * privilege-escalation attempts leave a trail in `.logs/audit.log`.
 *
 * @package Phlix\Hub\Http\Middleware
 */
final class AdminMiddleware
{
    /**
     * @param UserRepository $users Repository used for the admin lookup.
     * @param AuditLogger    $audit Audit logger; receives every emitted 403.
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Run the middleware. Returns null to continue routing.
     */
    public function __invoke(Request $request): ?Response
    {
        $status = $this->checkAccess($request);
        if ($status === null) {
            return null;
        }
        if ($status === 401) {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }
        return (new Response())->status(403)->json([
            'error' => 'Forbidden',
            'code'  => 'auth.not_admin',
        ]);
    }

    /**
     * Pure auth-decision helper. Exposed so the SSR page branch can
     * share the same logic.
     */
    public function checkAccess(Request $request): ?int
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return 401;
        }
        if ($this->users->findAdminById($userId) === null) {
            $this->audit->logPermissionDenied($userId, 'admin', 'access');
            return 403;
        }
        return null;
    }
}
