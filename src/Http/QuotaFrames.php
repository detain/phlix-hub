<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

/**
 * The wire vocabulary of the `quota.exceeded` 409/429 family (M-HUB-35).
 *
 * One registered contract code (frozen by the contracts SSOT — this class
 * adds ZERO codes) covers structurally different caps. Without a
 * discriminator a client cannot tell "your account is full" (needs
 * un-enrolling or an admin bump) from "this server is full of collaborators"
 * (needs removing a member) from "you used this month's bandwidth" (needs
 * waiting) — and, critically, none of them knows WHEN the answer changes.
 *
 * The cure is additive body keys on every quota frame:
 *
 *  - `quota_kind`:  which cap answered — `server_count`, `collaborators`,
 *    `bandwidth`, `requests`.
 *  - `quota_reset`: the cap's reset semantics — `on_release` (nothing decays
 *    on a timer; the refusal flips when a slot is freed) or `period` (the
 *    calendar window rolls; the frame already carries the instant in
 *    `Retry-After`/`retry_at` where derivable).
 *
 * @package Phlix\Hub\Http
 */
final class QuotaFrames
{
    /** Enrolled-servers-per-account cap (POST /server-claims/claim). */
    public const KIND_SERVER_COUNT = 'server_count';

    /** Collaborators-per-server cap (share create + invite redeem). */
    public const KIND_COLLABORATORS = 'collaborators';

    /** Per-user bandwidth/streams cap (relay proxy refusals). */
    public const KIND_BANDWIDTH = 'bandwidth';

    /**
     * Per-user pending media-request cap (POST /api/v1/me/requests, M-HUB-23).
     * Additive discriminator value on the ALREADY-registered `quota.exceeded`
     * code — the frozen error-code registry is untouched, same law as above.
     */
    public const KIND_REQUESTS = 'requests';

    /** The refusal clears when a slot is released — there is no epoch. */
    public const RESET_ON_RELEASE = 'on_release';

    /** The refusal clears when the measurement window rolls. */
    public const RESET_PERIOD = 'period';

    /**
     * The additive discriminator pair for a quota.exceeded frame.
     *
     * Rides `Response::error()`'s `$extra` (merged after `error`/`code`),
     * so existing consumers that read only `{error, code, message}` are
     * untouched — additive wire, no contract change.
     *
     * @return array{quota_kind: string, quota_reset: string}
     */
    public static function discriminator(string $kind, string $reset): array
    {
        return ['quota_kind' => $kind, 'quota_reset' => $reset];
    }

    /** Human copy of the account-server-cap refusal (claim ceremony). */
    public const SERVER_COUNT_TEXT = 'Server quota reached for this account';

    /** Human copy of the per-server collaborator cap refusal. */
    public const COLLABORATORS_TEXT = 'This server has reached its collaborator limit';

    /** Human copy of the per-user pending-request cap refusal (M-HUB-23). */
    public const REQUESTS_TEXT = 'You have too many pending requests';

    private function __construct()
    {
    }
}
