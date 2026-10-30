<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Http\QuotaFrames;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Requests\RequestManager;
use Phlix\Hub\Requests\RequestNotification;

/**
 * REST API for the Jellyseerr-class request UI on the hub.
 *
 * User endpoints (mounted behind {@see \Phlix\Hub\Http\Middleware\AuthMiddleware}):
 *  - `POST   /api/v1/me/requests`     {@see self::createRequest()}
 *  - `GET    /api/v1/me/requests`     {@see self::listMyRequests()}
 *  - `GET    /api/v1/me/requests/{id}` {@see self::getMyRequest()}
 *  - `DELETE /api/v1/me/requests/{id}` {@see self::deleteMyRequest()}
 *
 * Admin endpoints (mounted behind `AuthMiddleware` + admin gate):
 *  - `GET    /api/v1/admin/requests`              {@see self::listAdminRequests()}
 *  - `POST   /api/v1/admin/requests/{id}/approve` {@see self::approveRequest()}
 *  - `POST   /api/v1/admin/requests/{id}/deny`    {@see self::denyRequest()}
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class RequestController
{
    /**
     * @param RequestManager          $manager       Request lifecycle manager.
     * @param RequestNotification     $notification  Notification side-channel.
     * @param UserRepository          $users         Used for admin gating.
     * @param AuditLogger             $audit         Audit logger for admin actions.
     * @param RateLimiterInterface|null $createLimiter CLIENT_REQUEST_CREATE profile
         *                                             (M-HUB-23 per-member create budget);
     *                                             null venue behaves pre-cure.
     */
    public function __construct(
        private readonly RequestManager $manager,
        private readonly RequestNotification $notification,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly ?RateLimiterInterface $createLimiter = null,
    ) {
    }

    /**
     * `POST /api/v1/me/requests` — create a new media request for the current user.
     *
     * @param array<string, string> $params Path params (unused).
     */
    public function createRequest(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->unauthorized();
        }

        $body = $request->body;

        /** @var mixed $typeRaw */
        $typeRaw = $body['type'] ?? null;
        /** @var mixed $tmdbIdRaw */
        $tmdbIdRaw = $body['tmdb_id'] ?? null;
        /** @var mixed $titleRaw */
        $titleRaw = $body['title'] ?? null;
        /** @var mixed $posterRaw */
        $posterRaw = $body['poster_url'] ?? null;
        /** @var mixed $seasonRaw */
        $seasonRaw = $body['season'] ?? null;
        /** @var mixed $episodeRaw */
        $episodeRaw = $body['episode'] ?? null;

        if (!is_string($typeRaw) || !in_array($typeRaw, ['movie', 'series'], true)) {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code'  => 'invalid_type',
                'message' => 'type must be "movie" or "series"',
            ]);
        }

        $tmdbId = is_numeric($tmdbIdRaw) ? (int) $tmdbIdRaw : 0;
        if ($tmdbId <= 0) {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code'  => 'invalid_tmdb_id',
                'message' => 'tmdb_id must be a positive integer',
            ]);
        }

        if (!is_string($titleRaw) || $titleRaw === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code'  => 'missing_title',
                'message' => 'title is required',
            ]);
        }

        $posterUrl = is_string($posterRaw) ? $posterRaw : null;
        $season = is_numeric($seasonRaw) ? (int) $seasonRaw : null;
        $episode = is_numeric($episodeRaw) ? (int) $episodeRaw : null;

        // M-HUB-23: the per-member create budget is spent BEFORE any queue
        // read — over-budget traffic must not cost the DB the dedup probe or
        // the cap COUNT. RateLimitException escapes to the central 429 +
        // Retry-After mapping (Application::rateLimitResponse() law), the
        // same posture as the relay-token mint budget (M-HUB-22).
        $this->assertCreateBudget($userId);

        // M-HUB-23: dedup — a pending request for this exact target already
        // exists; echo it back (200, the status openapi declares for this
        // operation) with no new row and no second admin notification. 201
        // would claim a creation that did not happen.
        $duplicate = $this->manager->findPendingDuplicate($userId, $typeRaw, $tmdbId, $season, $episode);
        if ($duplicate !== null) {
            return (new Response())->status(200)->json([
                'request' => $duplicate,
                'message' => 'Request already pending.',
            ]);
        }

        // M-HUB-23: standing pending cap. The refusal rides the ALREADY
        // REGISTERED `quota.exceeded` code plus its additive discriminator
        // pair (QuotaFrames law, M-HUB-35) — kind `requests`, reset
        // `on_release` (the block clears when the admin works the queue).
        if ($this->manager->countPendingForUser($userId) >= RequestManager::MAX_PENDING_REQUESTS_PER_USER) {
            return (new Response())->status(409)->json([
                'error' => QuotaFrames::REQUESTS_TEXT,
                'code' => 'quota.exceeded',
                'message' => QuotaFrames::REQUESTS_TEXT,
                ...QuotaFrames::discriminator(QuotaFrames::KIND_REQUESTS, QuotaFrames::RESET_ON_RELEASE),
            ]);
        }

        $result = $this->manager->createRequest(
            $userId,
            $typeRaw,
            $tmdbId,
            $titleRaw,
            $posterUrl,
            $season,
            $episode,
        );

        $this->notification->notifySubmitted($userId, $titleRaw);

        return (new Response())->status(201)->json([
            'request' => $result,
            'message' => 'Request created successfully.',
        ]);
    }

    /**
     * `GET /api/v1/me/requests` — list the current user's requests.
     *
     * Supports `?status=pending|available` to filter.
     *
     * @param array<string, string> $params Path params (unused).
     */
    public function listMyRequests(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->unauthorized();
        }

        /** @var mixed $statusRaw */
        $statusRaw = $request->query['status'] ?? null;
        $status = is_string($statusRaw) ? $statusRaw : null;

        $requests = match ($status) {
            'pending'   => $this->manager->listPendingRequests($userId),
            'available' => $this->manager->listAvailableRequests(),
            default     => $this->manager->listUserRequests($userId),
        };

        return (new Response())->json([
            'requests' => $requests,
            'count'    => count($requests),
        ]);
    }

    /**
     * `GET /api/v1/me/requests/{id}` — look up one of the current user's requests.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function getMyRequest(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->unauthorized();
        }

        $requestId = $params['id'] ?? '';
        if ($requestId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code'  => 'missing_request_id',
            ]);
        }

        $result = $this->manager->getRequestById($requestId);
        if ($result === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code'  => 'request_not_found',
            ]);
        }
        if ($result['user_id'] !== $userId) {
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code'  => 'not_request_owner',
            ]);
        }

        return (new Response())->json(['request' => $result]);
    }

    /**
     * `DELETE /api/v1/me/requests/{id}` — delete one of the current user's requests.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function deleteMyRequest(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->unauthorized();
        }

        $requestId = $params['id'] ?? '';
        if ($requestId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code'  => 'missing_request_id',
            ]);
        }

        $existing = $this->manager->getRequestById($requestId);
        if ($existing === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code'  => 'request_not_found',
            ]);
        }
        if ($existing['user_id'] !== $userId) {
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code'  => 'not_request_owner',
            ]);
        }

        $this->manager->deleteRequest($requestId);
        return (new Response())->status(204);
    }

    /**
     * `GET /api/v1/admin/requests` — admin queue. Defaults to pending-only.
     *
     * Supports `?status=pending|approved|available|rejected|all`.
     *
     * @param array<string, string> $params Path params (unused).
     */
    public function listAdminRequests(Request $request, array $params): Response
    {
        $forbid = $this->requireAdmin($request);
        if ($forbid !== null) {
            return $forbid;
        }

        /** @var mixed $statusRaw */
        $statusRaw = $request->query['status'] ?? 'pending';
        $status = is_string($statusRaw) ? $statusRaw : 'pending';

        // Honour the advertised filter set: unknown values fall back to the
        // documented default (pending), every other value is executed truthfully
        // by the manager's whitelisted status lister.
        $requests = match ($status) {
            'pending'   => $this->manager->listPendingRequests(),
            'available' => $this->manager->listAvailableRequests(),
            'approved', 'rejected', 'all' => $this->manager->listRequestsByStatus($status),
            default     => $this->manager->listPendingRequests(),
        };

        return (new Response())->json([
            'requests' => $requests,
            'count'    => count($requests),
        ]);
    }

    /**
     * `POST /api/v1/admin/requests/{id}/approve` — admin approves a request.
     * Triggers the Sonarr/Radarr add via the underlying manager.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function approveRequest(Request $request, array $params): Response
    {
        $forbid = $this->requireAdmin($request);
        if ($forbid !== null) {
            return $forbid;
        }

        $requestId = $params['id'] ?? '';
        if ($requestId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code'  => 'missing_request_id',
            ]);
        }

        $existing = $this->manager->getRequestById($requestId);
        if ($existing === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code'  => 'request_not_found',
            ]);
        }

        $success = $this->manager->approveRequest($requestId);
        if (!$success) {
            return (new Response())->status(500)->json([
                'error'   => 'Internal Server Error',
                'code'    => 'approve_failed',
                'message' => 'Failed to approve. Check Radarr/Sonarr configuration.',
            ]);
        }

        $this->audit->logAdminAction(
            $request->userId ?? '',
            'request.approve',
            $requestId,
            ['target_user_id' => $existing['user_id']],
        );
        $this->notification->notifyApproved($existing['user_id'], $existing['title']);

        return (new Response())->json([
            'message'    => 'Request approved successfully.',
            'request_id' => $requestId,
        ]);
    }

    /**
     * `POST /api/v1/admin/requests/{id}/deny` — admin denies a request.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function denyRequest(Request $request, array $params): Response
    {
        $forbid = $this->requireAdmin($request);
        if ($forbid !== null) {
            return $forbid;
        }

        $requestId = $params['id'] ?? '';
        if ($requestId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code'  => 'missing_request_id',
            ]);
        }

        $existing = $this->manager->getRequestById($requestId);
        if ($existing === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code'  => 'request_not_found',
            ]);
        }

        $body = $request->body;
        /** @var mixed $reasonRaw */
        $reasonRaw = $body['reason'] ?? '';
        $reason = is_string($reasonRaw) ? $reasonRaw : '';

        $success = $this->manager->rejectRequest($requestId, $reason);
        if (!$success) {
            return (new Response())->status(500)->json([
                'error' => 'Internal Server Error',
                'code'  => 'deny_failed',
            ]);
        }

        $this->audit->logAdminAction(
            $request->userId ?? '',
            'request.deny',
            $requestId,
            ['target_user_id' => $existing['user_id'], 'reason' => $reason],
        );
        $this->notification->notifyRejected($existing['user_id'], $existing['title'], $reason);

        return (new Response())->json([
            'message'    => 'Request denied successfully.',
            'request_id' => $requestId,
        ]);
    }

    /**
     * Verify the caller is an admin; return a 403 Response when not.
     */
    private function requireAdmin(Request $request): ?Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->unauthorized();
        }
        $admin = $this->users->findAdminById($userId);
        if ($admin === null) {
            $this->audit->logPermissionDenied($userId, 'admin.requests', $request->method);
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

    /**
     * The M-HUB-23 create budget: {@see \Phlix\Hub\Common\RateLimit\RateLimitProfiles::CLIENT_REQUEST_CREATE},
     * keyed on the AUTHENTICATED MEMBER (`client_request_create:<userId>`) —
     * user-keyed for the same reasons as the relay-token mint precedent
     * ({@see \Phlix\Hub\Http\Controllers\ClientRelayTokenController}): the
     * abuse vector is one attributable member pumping admin-queue rows.
     * A null limiter (hand-wired unit venue) behaves exactly pre-cure by
     * documented choice; the audit fires exactly once at the boundary
     * transition (`count === limit`), never on every over-budget request.
     */
    private function assertCreateBudget(string $userId): void
    {
        if ($this->createLimiter === null) {
            return;
        }

        $state = $this->createLimiter->hit('client_request_create:' . $userId);
        if (!$state->limited) {
            return;
        }

        if ($state->count === $state->limit) {
            $this->audit->logFailedAuth('request_create_rate_limited', ['user_id' => $userId]);
        }

        throw new RateLimitException(
            resetAt: $state->resetAt,
            remaining: 0,
        );
    }
}
