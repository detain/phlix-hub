<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Phlix\Hub\ServerReaper;
use Phlix\Shared\Hub\ServerInfoDto;

/**
 * Derives the HUB-VANTAGE reachability state of a server hosting a shared library.
 *
 * "Shared With Me" hands members the owner's heartbeat-advertised hostnames
 * verbatim; when the owner's server is offline — or NAT-hidden so the hub never
 * saw a usable address — every button on the card was a silently dead link
 * [review hub__casualgamer M3]. This mapper turns the signals the hub ALREADY
 * maintains into one honest three-state label per share, with zero new probes
 * and zero new infrastructure:
 *
 *  - `servers.status` + `servers.last_seen_at` — written by HeartbeatHandler on
 *    every beat (`status='online'`, `last_seen_at = NOW()`; DB clock per the
 *    R19-04 law — here both sides arrive as UNIX epochs via
 *    `UNIX_TIMESTAMP()`, so the comparison is timezone-safe by construction).
 *  - open relay session — the exact `relay_sessions ... closed_at IS NULL`
 *    EXISTS idiom of ServerInfoHandler::getOwnerAndStatus() (a live tunnel
 *    means the server dialled the hub RIGHT NOW, regardless of what the last
 *    heartbeat row says).
 *  - the reaper's own freshness law — {@see ServerReaper::DEFAULT_OFFLINE_THRESHOLD_SECONDS}
 *    (180 s) is the estate's definition of "silent"; we reuse the constant so a
 *    row cannot be simultaneously "fresh enough to call online" here and
 *    "expired enough for the reaper to mark offline" there.
 *
 * The label is deliberately epistemic, not promissory: it states what the HUB
 * can see, never what the READING MEMBER's network path can do. A "reachable"
 * share may still be unreachable from the member's device (different vantage),
 * and only the hub→server vantage is measured. The SPA copy mirrors that
 * framing; the mapper never fabricates more certainty than the signals carry
 * (unknown stays unknown — a missing signal must not read as false-live).
 *
 * Pure and static (Law 3): same status/last-seen/relay inputs at the same
 * `time()` yield the same label; no I/O, no mutation.
 *
 * @package Phlix\Hub\Hub
 */
final class ShareServerLiveness
{
    /**
     * The hub saw this server recently enough (fresh heartbeat, or an open
     * relay session right now) to consider its advertised URLs live.
     */
    public const REACHABLE = 'reachable';

    /**
     * The hub's vantage says this server is down: disabled, reaped-offline, or
     * still marked online past the reaper's own freshness threshold.
     */
    public const UNREACHABLE = 'unreachable';

    /**
     * No honest conclusion available: never-heartbeated, mid-claim, or a status
     * this mapper doesn't recognise. Neutral — the SPA must NOT render this as
     * dead, and must not promise it works either.
     */
    public const UNKNOWN = 'unknown';

    /**
     * Derive the hub-vantage liveness label for one server row.
     *
     * Guard order is the trust order: a hard admin signal (disabled) beats an
     * alive tunnel (relay), which beats the heartbeat table's own claim, which
     * beats the absence of any signal.
     *
     * @param string   $status              `servers.status` as stored (one of ServerInfoDto::STATUS_*).
     * @param int|null $lastSeenAt          `UNIX_TIMESTAMP(servers.last_seen_at)`, or null when never seen.
     * @param bool     $relayActive         True when an open relay_sessions row exists for the server.
     * @param int      $now                 Reference epoch (the handler passes time(); injectable for tests).
     * @param int      $thresholdSeconds    Freshness window; defaults to the reaper's law.
     *
     * @return self::REACHABLE|self::UNREACHABLE|self::UNKNOWN
     */
    public static function derive(
        string $status,
        ?int $lastSeenAt,
        bool $relayActive,
        int $now,
        int $thresholdSeconds = ServerReaper::DEFAULT_OFFLINE_THRESHOLD_SECONDS,
    ): string {
        // Admin put the server out of service — no member path works from any vantage.
        if ($status === ServerInfoDto::STATUS_DISABLED) {
            return self::UNREACHABLE;
        }

        // An open relay tunnel is the strongest liveness signal the hub holds:
        // the server dialled in and the socket is still open (ServerInfoHandler
        // idiom). Direct URLs may still be dead (NAT), but the share IS usable.
        if ($relayActive) {
            return self::REACHABLE;
        }

        // Mid-claim: the row exists, the owner hasn't finished binding it; any
        // answer would be theatre. Same for never-heartbeated rows.
        if ($status === ServerInfoDto::STATUS_CLAIMING || $lastSeenAt === null) {
            return self::UNKNOWN;
        }

        // The table still says "online". Trust it only while the reaper's own
        // silence window has not elapsed; past it, a not-yet-reaped row is
        // stale, and honesty requires saying so.
        if ($status === ServerInfoDto::STATUS_ONLINE) {
            return $now - $lastSeenAt <= $thresholdSeconds ? self::REACHABLE : self::UNREACHABLE;
        }

        // Explicitly reaped (offline) — unreachable from the hub's vantage.
        if ($status === ServerInfoDto::STATUS_OFFLINE) {
            return self::UNREACHABLE;
        }

        // Future/foreign status vocabulary: never guess it into false-live.
        return self::UNKNOWN;
    }
}
