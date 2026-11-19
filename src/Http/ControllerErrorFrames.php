<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

/**
 * The hub's shared controller error frames (refactor F-02/F-19).
 *
 * Controllers had hand-built the same refusal envelopes again and again —
 * two byte-identical private `unauthorized()` methods plus ten inline
 * copies of the same plain auth frame, two byte-identical `memberError()`
 * builders, an MCP `unauthorized($code, $message)` twin of the plain frame,
 * the quota/admin-user 404/400/409 one-offs, and the two identical
 * `mapError()` default arms. Each copy was free to drift; some already had
 * (AdminUserController::conflict() declared its parameters in the OPPOSITE
 * order of the house convention — see {@see self::conflict()}).
 *
 * The family law is deliberate, not accidental, and every method below
 * keeps its family's exact wire bytes (status, header order, body key
 * order):
 *
 *  - `unauthorized()` / `internalError()` / `userNotFound()` /
 *    `missingUserId()` / `conflict()` are the machine voice: the registered
 *    literal lives in `error`, the dotted code in `code`.
 *  - `memberError()` is the member voice: a human sentence in `error`,
 *    mirrored into `message` so either client precedence renders prose
 *    (hub__casualgamer M5 law), and `code` keeps the registered machine code.
 *  - `mcpUnauthorized()` is the plain machine frame plus the
 *    `WWW-Authenticate` challenge leg the MCP transport contract requires.
 *
 * Claim envelopes with machine `error` text + SCREAMING `message` extras
 * (ServerClaim/Relay/Subdomain families) are deliberately absent here —
 * dual-placement law (HUB-04), and their per-site extra payloads make them a
 * fold of a different kind. The 401 `code` vocabulary is the one hub-wide
 * law: `auth.required` (this file) is the registered code; the legacy
 * `auth.unauthenticated` survives only in the ServerClaim envelope.
 *
 * @package Phlix\Hub\Http
 */
final class ControllerErrorFrames
{
    /**
     * The member channel never renders an empty sentence: a blank message
     * collapses to this one (the exact string both member builders used).
     */
    public const GENERIC_PROBLEM = 'Something went wrong on the hub. Please try again.';

    /**
     * Vocabulary class — no instances (mirrors {@see QuotaFrames}).
     */
    private function __construct()
    {
    }

    /**
     * Plain machine-voice auth refusal (HTTP 401, `auth.required`).
     *
     * Folds the byte-identical RequestController/UserQuotaController/
     * McpTokenController `unauthorized()` trio and the ten inline copies of
     * the same frame converted to the {@see ControllerRequests::authenticatedUserId()}
     * guard seam under F-03 (F-02). Byte-for-byte: status 401, JSON headers,
     * body `{"error":"Unauthorized","code":"auth.required"}`.
     */
    public static function unauthorized(): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'Unauthorized',
            'code'  => 'auth.required',
        ]);
    }

    /**
     * MCP auth refusal: the plain frame's shape plus the Bearer challenge
     * header the MCP HTTP transport requires.
     *
     * Absorbs McpController::unauthorized() (F-02). The challenge leg is
     * appended BEFORE json() so the header order on the wire stays exactly
     * what the two callers emit today: WWW-Authenticate, Content-Type,
     * Cache-Control.
     */
    public static function mcpUnauthorized(string $code, string $message): Response
    {
        return (new Response())
            ->status(401)
            ->header('WWW-Authenticate', 'Bearer realm="phlix-hub-mcp"')
            ->json([
                'error'   => 'Unauthorized',
                'code'    => $code,
                'message' => $message,
            ]);
    }

    /**
     * The member-facing error channel speaks human.
     *
     * The pinned portal client renders the `error` field verbatim
     * (`extractError` checks `error` before `message`), so every failure arm
     * of the member-facing share/invite controllers must carry a human
     * sentence there — never an HTTP phrase, never an empty string: blank
     * collapses to {@see self::GENERIC_PROBLEM} and the TRIMMED sentence is
     * mirrored into `message` so it renders under either client precedence.
     * `code` keeps the registered machine code; `$extra` merges after the
     * mirrored pair (quota.exceeded arms ride `limit`/`used` etc.).
     *
     * Folds the two byte-identical private `memberError()` builders of
     * LibraryShareController and InviteLinkController (F-02), preserving the
     * exact envelope both emit today.
     *
     * @param array<string, mixed> $extra
     */
    public static function memberError(int $status, string $code, string $message, array $extra = []): Response
    {
        $human = trim($message);
        if ($human === '') {
            $human = self::GENERIC_PROBLEM;
        }

        return (new Response())->error($status, $code, $human, ['message' => $human] + $extra);
    }

    /**
     * Conflict envelope: machine phrase in `error`, registered code in
     * `code`, human sentence in `message` — parameters in the HOUSE order
     * `error(status, code, message…)` (see Response::error()).
     *
     * The absorbed AdminUserController::conflict() declared
     * `(string $message, string $code)` — the inverse — while still emitting
     * the correct wire frame; its four call sites had to remember the
     * inversion, which is exactly the foot-gun this kit exists to kill. The
     * signature here is fixed to the house order and the call sites carry
     * their arguments swapped in the same commit: the emitted bytes for all
     * four conflicts (`cannot_delete_self`, `last_admin` ×2,
     * `cannot_demote_self`) are unchanged, now pinned by
     * {@see \Phlix\Hub\Tests\Unit\Http\Controllers\ControllerErrorFramesTest::conflictProvider()}.
     */
    public static function conflict(string $code, string $message): Response
    {
        return (new Response())->status(409)->json([
            'error'   => 'Conflict',
            'code'    => $code,
            'message' => $message,
        ]);
    }

    /**
     * `user_not_found` 404 (absorbs AdminUserController::notFound(), F-02).
     */
    public static function userNotFound(): Response
    {
        return (new Response())->status(404)->json([
            'error' => 'User not found',
            'code'  => 'user_not_found',
        ]);
    }

    /**
     * The quota endpoints' missing-subject refusal (absorbs
     * UserQuotaController::missingUserId(), F-02).
     */
    public static function missingUserId(): Response
    {
        return (new Response())->status(400)->json([
            'error' => 'Bad Request',
            'code'  => 'missing_user_id',
        ]);
    }

    /**
     * The mapError() default arm both ServerController and
     * ServerClaimController hand-built identically (F-02): machine voice
     * `error`, registered dotted `code`, the legacy SCREAMING literal parked
     * in `message` per dual-placement law.
     */
    public static function internalError(): Response
    {
        return (new Response())->error(500, 'hub.internal_error', 'HUB_INTERNAL_ERROR', [
            'message' => 'An unexpected error occurred.',
        ]);
    }
}
