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
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * HTTP surface for the per-user relay bandwidth quotas + concurrent-stream cap
 * (HB-3.4 G5). Exposes the {@see RelaySessionManager} accounting methods that
 * were previously reachable only from the internal streaming hot path.
 *
 * Self endpoint (behind {@see \Phlix\Hub\Http\Middleware\AuthMiddleware}):
 *  - `GET /api/v1/me/bandwidth` {@see self::viewOwnBandwidth()} — the caller's
 *    own current-period usage + configured caps.
 *
 * Admin endpoints (behind `AuthMiddleware` + `AdminMiddleware`, plus the inline
 * {@see self::requireAdmin()} defence-in-depth gate — mirrors
 * {@see RequestController}):
 *  - `GET /api/v1/admin/users/{id}/bandwidth` {@see self::viewUserBandwidth()} —
 *    any user's usage + caps.
 *  - `PUT /api/v1/admin/users/{id}/quota` {@see self::setUserQuota()} — set a
 *    user's monthly download/upload caps + concurrent-stream cap for the current
 *    period.
 *
 * NOTE (per the HB-3.4 caveat): the concurrent-stream cap is enforced
 * per-HTTP-worker (HUB_WORKERS), so the effective global ceiling is
 * ~N×max_concurrent_streams; a strict global cap needs a shared store and is
 * out of scope here.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class UserQuotaController
{
    /**
     * Upper bound for a monthly byte cap (1 PiB) — rejects absurd input while
     * staying well above any realistic quota. 0 always means "unlimited".
     */
    private const MAX_QUOTA_BYTES = 1125899906842624;

    /**
     * Upper bound for the concurrent-stream cap. 0 means "unlimited".
     */
    private const MAX_CONCURRENT_STREAMS = 1000;

    /**
     * @param RelaySessionManager $sessions Bandwidth accounting + quota store.
     * @param UserRepository      $users    Used for the admin gate.
     * @param AuditLogger         $audit    Audit trail for admin mutations + denials.
     */
    public function __construct(
        private readonly RelaySessionManager $sessions,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * `GET /api/v1/me/bandwidth` — the current user's own bandwidth usage and
     * configured caps for the current period. Auth-gated; no admin required.
     */
    public function viewOwnBandwidth(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->unauthorized();
        }

        return (new Response())->json($this->bandwidthPayload($userId));
    }

    /**
     * `GET /api/v1/admin/users/{id}/bandwidth` — any user's bandwidth usage and
     * caps. Admin-only.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function viewUserBandwidth(Request $request, array $params): Response
    {
        $forbid = $this->requireAdmin($request);
        if ($forbid !== null) {
            return $forbid;
        }

        $targetId = $params['id'] ?? '';
        if ($targetId === '') {
            return $this->missingUserId();
        }

        return (new Response())->json($this->bandwidthPayload($targetId));
    }

    /**
     * `PUT /api/v1/admin/users/{id}/quota` — set a user's monthly download +
     * upload caps and concurrent-stream cap for the current period. Admin-only.
     *
     * Body: `{ "quota_bytes_in": int, "quota_bytes_out": int,
     *          "max_concurrent_streams": int }`. Every value must be a
     * non-negative integer within its sane bound; 0 means "unlimited".
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function setUserQuota(Request $request, array $params): Response
    {
        $forbid = $this->requireAdmin($request);
        if ($forbid !== null) {
            return $forbid;
        }

        $targetId = $params['id'] ?? '';
        if ($targetId === '') {
            return $this->missingUserId();
        }

        $body = $request->body;

        /** @var mixed $rawIn */
        $rawIn = $body['quota_bytes_in'] ?? null;
        $quotaBytesIn = $this->parseBoundedInt($rawIn, self::MAX_QUOTA_BYTES);
        if ($quotaBytesIn === null) {
            return $this->invalidField('quota_bytes_in');
        }

        /** @var mixed $rawOut */
        $rawOut = $body['quota_bytes_out'] ?? null;
        $quotaBytesOut = $this->parseBoundedInt($rawOut, self::MAX_QUOTA_BYTES);
        if ($quotaBytesOut === null) {
            return $this->invalidField('quota_bytes_out');
        }

        /** @var mixed $rawStreams */
        $rawStreams = $body['max_concurrent_streams'] ?? null;
        $maxStreams = $this->parseBoundedInt($rawStreams, self::MAX_CONCURRENT_STREAMS);
        if ($maxStreams === null) {
            return $this->invalidField('max_concurrent_streams');
        }

        $this->sessions->setUserQuota($targetId, $quotaBytesIn, $quotaBytesOut, $maxStreams);

        $this->audit->logAdminAction(
            $request->userId ?? '',
            'user.quota.set',
            $targetId,
            [
                'quota_bytes_in' => $quotaBytesIn,
                'quota_bytes_out' => $quotaBytesOut,
                'max_concurrent_streams' => $maxStreams,
            ],
        );

        return (new Response())->json($this->bandwidthPayload($targetId));
    }

    /**
     * Build the JSON usage/quota rollup for a user for the current period.
     * Absent rows read back as zeroed usage + unlimited caps (a real,
     * meaningful response rather than a 404).
     *
     * @return array{
     *     user_id: string,
     *     bytes_in: int,
     *     bytes_out: int,
     *     quota_bytes_in: int,
     *     quota_bytes_out: int,
     *     max_concurrent_streams: int
     * }
     */
    private function bandwidthPayload(string $userId): array
    {
        $bandwidth = $this->sessions->getUserBandwidth($userId);
        $maxStreams = $this->sessions->getUserMaxConcurrentStreams($userId);

        return [
            'user_id' => $userId,
            'bytes_in' => $bandwidth['bytes_in'] ?? 0,
            'bytes_out' => $bandwidth['bytes_out'] ?? 0,
            'quota_bytes_in' => $bandwidth['quota_bytes_in'] ?? 0,
            'quota_bytes_out' => $bandwidth['quota_bytes_out'] ?? 0,
            'max_concurrent_streams' => $maxStreams,
        ];
    }

    /**
     * Narrow a body value to a non-negative integer within [0, $max]. Accepts a
     * native int or a digit-only string (JSON numbers arrive as int; some
     * clients send strings). Returns null on any invalid / negative /
     * non-integer / out-of-range value so the caller can emit a 400.
     */
    private function parseBoundedInt(mixed $value, int $max): ?int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && $value !== '' && ctype_digit($value)) {
            $int = (int) $value;
        } else {
            return null;
        }

        if ($int < 0 || $int > $max) {
            return null;
        }

        return $int;
    }

    /**
     * Verify the caller is an admin; return a 401/403 Response when not, or null
     * to continue. Mirrors {@see RequestController::requireAdmin()} — the same
     * `findAdminById` lookup + audited `admin_required` denial the rest of the
     * hub's admin API uses.
     */
    private function requireAdmin(Request $request): ?Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->unauthorized();
        }
        if ($this->users->findAdminById($userId) === null) {
            $this->audit->logPermissionDenied($userId, 'admin.user_quota', $request->method);
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code'  => 'admin_required',
            ]);
        }
        return null;
    }

    private function unauthorized(): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'Unauthorized',
            'code'  => 'auth.required',
        ]);
    }

    private function missingUserId(): Response
    {
        return (new Response())->status(400)->json([
            'error' => 'Bad Request',
            'code'  => 'missing_user_id',
        ]);
    }

    private function invalidField(string $field): Response
    {
        return (new Response())->status(400)->json([
            'error'   => 'Bad Request',
            'code'    => 'invalid_quota',
            'message' => sprintf('"%s" must be a non-negative integer within range.', $field),
        ]);
    }
}
