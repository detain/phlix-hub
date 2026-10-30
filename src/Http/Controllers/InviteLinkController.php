<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Hub\InviteLink;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * API controller for invite link endpoints.
 *
 * Every failure here is shown to a signed-in member (or an invitee standing
 * at the accept-invite page) in the portal, so every envelope goes through
 * {@see self::memberError()} — human sentence in `error`, registered machine
 * code in `code`, `message` mirroring `error`.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class InviteLinkController
{
    /**
     * Ship default for invites whose creator omits `expires_in` — the exact
     * literal this controller hardcoded before `invite.default_expiry_seconds`
     * existed (defaults-preservation pin).
     */
    private const BOOT_DEFAULT_EXPIRY_SECONDS = 604800;

    /**
     * Fail-safe copy for pass-through arms whose handler exception carries no
     * message at all. The pinned portal client renders the `error` field
     * verbatim even when it is the empty string, so the wire must never ship
     * one (review hub__casualgamer M5).
     */
    private const GENERIC_PROBLEM = 'Something went wrong on the hub. Please try again.';

    /**
     * @param InviteLinkHandler $handler               Invite link handler.
     * @param (callable(): int)|null $defaultExpiryResolver LIVE reader of
     *        `invite.default_expiry_seconds` (HubSettingsResolvers::int, bounds
     *        0..31536000, fail-safe to the boot default). Null (tests, unbooted
     *        pool) keeps the historical 7-day literal.
     * @param RateLimiterInterface|null $redeemLimiter INVITE_REDEEM profile
     *        limiter (minors campaign M-HUB-21); null (unit tests, unbooted
     *        pool) disables the budget — the pre-M-HUB-21 behavior, and the
     *        container can never ship the null because
     *        {@see \Phlix\Hub\Tests\Unit\Common\Container\Providers\InviteLinkRedeemWiringTest}
     *        pins the factory wiring at the definition level (same
     *        fail-open-with-guard precedent as
     *        ServerClaimController::$claimsLimiter).
     * @param AuditLogger|null  $audit                 Writes exactly ONE
     *        audit_logs row per limiter transition into "over budget"; null =
     *        file-channel-only.
     */
    public function __construct(
        private readonly InviteLinkHandler $handler,
        private readonly mixed $defaultExpiryResolver = null,
        private readonly ?RateLimiterInterface $redeemLimiter = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Canonical member-facing error envelope.
     *
     * The portal's pinned client (`extractError` in @phlix/ui
     * `src/api/client.ts`) checks `error` before `message`, so the human
     * sentence lives in `error`; `code` carries the registered
     * `@phlix/contracts` machine code (contracts law — stable codes, free
     * text). `message` mirrors `error` so the same sentence renders under
     * either client precedence. Whitespace-only pass-through messages are
     * normalized to {@see self::GENERIC_PROBLEM}.
     *
     * @param int    $status  HTTP status code.
     * @param string $code    Registered stable machine code.
     * @param string $message Human-facing sentence (pass-through allowed).
     */
    private function memberError(int $status, string $code, string $message): Response
    {
        $human = trim($message);
        if ($human === '') {
            $human = self::GENERIC_PROBLEM;
        }

        return (new Response())->error($status, $code, $human, ['message' => $human]);
    }

    /**
     * Effective default invite lifetime, consulted ONLY when the request body
     * omits `expires_in` (an explicit body value always wins, preserving
     * `0`/negative = never-expiring semantics at the call site below).
     */
    private function defaultExpirySeconds(): int
    {
        $resolver = $this->defaultExpiryResolver;
        if (!is_callable($resolver)) {
            return self::BOOT_DEFAULT_EXPIRY_SECONDS;
        }

        return $resolver();
    }

    /**
     * Consume one unit of this member's redeem budget (minors campaign M-HUB-21).
     *
     * Keyed by the AUTHENTICATED user id — not the IP: the redeem surface is
     * member-only (401 before this point), so the threat is one attributable
     * member grinding the burn path, and their identity is the stable bucket.
     *
     * @throws RateLimitException Over budget — mapped to the central 429 by
     *         {@see \Phlix\Hub\Application}; deliberately thrown OUTSIDE the
     *         handler try/catch so it can never fold into a generic frame, and
     *         BEFORE any token-hash DB work (the whole point is to refuse that
     *         cost cheaply).
     */
    private function assertRedeemBudget(string $userId): void
    {
        if ($this->redeemLimiter === null) {
            return;
        }

        $state = $this->redeemLimiter->hit('invite_redeem:' . $userId);
        if (!$state->limited) {
            return;
        }

        if ($state->count === $state->limit) {
            $this->audit?->logFailedAuth('invite_redeem_rate_limited', ['user_id' => $userId]);
        }

        throw new RateLimitException(
            resetAt: $state->resetAt,
            remaining: 0,
        );
    }

    /**
     * `POST /api/v1/me/invite-links` — create a new invite link.
     */
    public function createInviteLink(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->memberError(401, 'auth.required', 'Sign in to create invite links');
        }

        $body = $request->body;
        if ($body === []) {
            return $this->memberError(400, 'invalid_body', 'A request body is required');
        }

        /** @var mixed $serverId */
        $serverId = $body['server_id'] ?? null;
        /** @var mixed $libraryId */
        $libraryId = $body['library_id'] ?? null;
        /** @var mixed $permission */
        $permission = $body['permission'] ?? 'read';
        /** @var mixed $maxUses */
        $maxUses = $body['max_uses'] ?? 1;
        /** @var mixed $expiresIn */
        $expiresIn = $body['expires_in'] ?? $this->defaultExpirySeconds();

        if (!is_string($serverId) || $serverId === '') {
            return $this->memberError(400, 'missing_server_id', 'Select which server the invite is for');
        }

        $expiresAt = null;
        if (is_numeric($expiresIn) && (int) $expiresIn > 0) {
            $expiresAt = time() + (int) $expiresIn;
        }

        try {
            $link = $this->handler->createInviteLink(
                ownerId: $userId,
                serverId: $serverId,
                libraryId: is_string($libraryId) && $libraryId !== '' ? $libraryId : null,
                permission: is_string($permission) ? $permission : 'read',
                maxUses: is_numeric($maxUses) ? max(1, (int) $maxUses) : 1,
                expiresAt: $expiresAt,
            );

            return (new Response())->status(201)->json([
                'url' => $link->url,
                // Plaintext opaque token — returned EXACTLY ONCE, here. Listed
                // links only ever expose its one-way hash, server-side.
                'token' => $link->token,
                'expires_at' => $link->expiresAt,
                'id' => $link->id,
            ]);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 403) {
                return $this->memberError(403, 'not_server_owner', 'You do not own this server');
            }
            if ($code === 400) {
                return $this->memberError(400, 'invalid_request', $e->getMessage());
            }
            return $this->memberError(500, 'unknown_error', self::GENERIC_PROBLEM);
        }
    }

    /**
     * `GET /api/v1/me/invite-links` — list invite links for the authenticated user.
     */
    public function listInviteLinks(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->memberError(401, 'auth.required', 'Sign in to see your invite links');
        }

        $links = $this->handler->listForOwner($userId);

        return (new Response())->json([
            'invite_links' => array_map(fn (InviteLink $link) => $link->toPayload(), $links),
        ]);
    }

    /**
     * `DELETE /api/v1/me/invite-links/{id}` — revoke an invite link.
     *
     * @param array<string, string> $params Route parameters including `id`.
     */
    public function deleteInviteLink(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->memberError(401, 'auth.required', 'Sign in to revoke an invite link');
        }

        $linkId = $params['id'] ?? '';
        if ($linkId === '') {
            return $this->memberError(400, 'missing_link_id', 'Select the invite link you want to revoke');
        }

        try {
            $this->handler->revokeInviteLink($userId, $linkId);
            return (new Response())->status(204);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return $this->memberError(404, 'invite_link_not_found', 'That invite link no longer exists');
            }
            if ($code === 403) {
                return $this->memberError(403, 'not_link_owner', 'You do not own this invite link');
            }
            return $this->memberError(500, 'unknown_error', self::GENERIC_PROBLEM);
        }
    }

    /**
     * `POST /api/v1/me/invite-links/{token}/redeem` — redeem an invite link and create a library share.
     *
     * @param array<string, string> $params Route parameters including `token`.
     * @return Response 201 with the created LibraryShare payload, or 401/400/404/410 on failure.
     */
    public function redeem(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->memberError(401, 'auth.required', 'Sign in to accept this invite');
        }

        $token = $params['token'] ?? '';
        if ($token === '') {
            return $this->memberError(400, 'missing_token', 'This invite link is missing its token');
        }

        // M-HUB-21: budget AFTER the cheap shape gates, BEFORE any DB work.
        $this->assertRedeemBudget($userId);

        try {
            /** @var LibraryShare $share */
            $share = $this->handler->redeemInviteLink($token, $userId);

            return (new Response())->status(201)->json($share->toPayload());
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            // W5 share quota surfaced through redeem: discriminated by
            // message before the generic 409 (share_exists) arm.
            if ($e->getMessage() === 'SERVER_SHARE_CAP_REACHED') {
                return $this->memberError(
                    409,
                    'quota.exceeded',
                    'This server has reached its collaborator limit',
                );
            }
            if ($code === 400) {
                return $this->memberError(400, 'invalid_invite', $e->getMessage());
            }
            if ($code === 404) {
                return $this->memberError(404, 'invite_link_not_found', 'That invite link no longer exists');
            }
            if ($code === 410) {
                return $this->memberError(410, 'invite_expired_or_exhausted', $e->getMessage());
            }
            if ($code === 409) {
                // Redeemer already holds an active share for this exact tuple
                // (multi-use invite redeemed twice) — a conflict, not a 500.
                return $this->memberError(409, 'share_exists', 'This library is already shared with you');
            }

            return $this->memberError(500, 'unknown_error', self::GENERIC_PROBLEM);
        }
    }
}
