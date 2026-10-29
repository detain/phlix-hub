/**
 * Hub-vantage share liveness display law — "Shared With Me" [review hub__casualgamer M3].
 *
 * The vendored `SharedWithMePage` opened owner-advertised `access_urls` with
 * exactly one gate (is the array empty?) and zero explanation: a NAT-hidden
 * friend's server rendered as a permanently dead button. The hub now derives an
 * honest three-state label per share server-side (`ShareServerLiveness::derive`,
 * surfaced as `server_liveness` / `server_last_seen_at` / `server_relay_active`
 * on `GET /api/v1/me/shares` → `incoming`). This module is THE single client
 * truth table that turns those wire fields into UI states; the page binds
 * through it so no dead button can ship silently.
 *
 * Epistemic framing (mirrors the PHP class): the label is what THE HUB can see,
 * never a promise about this member's network path. Copy says "from the hub's
 * vantage"; `unknown` stays neutral (the Open button stays usable — refusing a
 * maybe-live server would itself be a lie of omission).
 *
 * Pure + dependency-free (Law 3): tested by `npm test` (node --test) without a
 * DOM, so the truth table is pinned at the module boundary, not behind Vue.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

/** The three hub-derived states; wire vocabulary of `ShareServerLiveness`. */
export type ShareLiveness = 'reachable' | 'unreachable' | 'unknown';

/** One `incoming` row of `GET /api/v1/me/shares` (mirrors SharedLibraryDto::toPayload()). */
export interface HubIncomingShare {
    share_id: string;
    owner_user_id: string;
    owner_name: string;
    server_id: string;
    server_name: string;
    library_id: string;
    library_name: string;
    /** Hardcoded to 0 by the hub — NOT displayed, rendering it would state a falsehood. */
    library_item_count: number;
    permission_level: 'read' | 'readwrite';
    /** The server's advertised hostnames; may be empty (a NAT-hidden owner advertises nothing). */
    access_urls: string[];
    /** UNIX seconds, or null when the hub couldn't read the column. */
    created_at: number | null;
    /** UNIX seconds, or null when the share never expires. */
    expires_at: number | null;
    /**
     * Hub-vantage reachability [M3]. OPTIONAL: payloads from a hub older than
     * the liveness era simply omit it — treated as 'unknown', never false-live.
     */
    server_liveness?: ShareLiveness | string;
    /** UNIX epoch of the server's last heartbeat as stored by the hub. */
    server_last_seen_at?: number | null;
    /** True while the server holds an open relay tunnel to this hub. */
    server_relay_active?: boolean;
}

/** Coerce any wire value to the trusted three-state union (unknown is the floor). */
export function shareLiveness(share: HubIncomingShare): ShareLiveness {
    return share.server_liveness === 'reachable' ||
        share.server_liveness === 'unreachable' ||
        share.server_liveness === 'unknown'
        ? share.server_liveness
        : 'unknown';
}

/**
 * Why the Open button must be disabled for this share — or null when it is
 * allowed to work.
 *
 * THE dead-button gate: a non-null reason means the card must ALSO render this
 * sentence visibly (the review's complaint was silence, not disability).
 * `unknown` deliberately returns null: unverified is not disproven.
 */
export function shareOpenBlockReason(share: HubIncomingShare): string | null {
    if (!share.access_urls?.length) {
        return 'This server has not advertised a web address to the hub yet.';
    }
    if (shareLiveness(share) === 'unreachable') {
        return 'The hub cannot currently reach this server — it is offline or its owner is behind NAT. Try again later.';
    }
    return null;
}

/** Badge presentation for the three-state label. */
export interface LivenessBadge {
    tone: 'success' | 'error' | 'neutral';
    text: string;
}

/**
 * Per-card honesty badge: never renders a checkmark the hub did not earn,
 * never renders doom over an absent signal.
 */
export function livenessBadge(share: HubIncomingShare): LivenessBadge {
    switch (shareLiveness(share)) {
        case 'reachable':
            // Hub vantage only — a different network path may still fail.
            return share.server_relay_active
                ? { tone: 'success', text: 'Connected via relay' }
                : { tone: 'success', text: 'Reachable from hub' };
        case 'unreachable':
            return { tone: 'error', text: 'Unreachable' };
        case 'unknown':
        default:
            return { tone: 'neutral', text: 'Status not verified' };
    }
}

/**
 * Human "last seen" clause for the card hint, from the hub's stored heartbeat
 * epoch. Renders nothing suggestive when the signal is absent.
 */
export function formatLastSeen(unixSeconds: number | null | undefined, nowMs: number = Date.now()): string {
    if (typeof unixSeconds !== 'number' || !Number.isFinite(unixSeconds)) {
        return 'last seen: time unknown';
    }
    const ageSeconds = Math.max(0, Math.floor(nowMs / 1000) - unixSeconds);
    if (ageSeconds < 60) {
        return 'last seen: less than a minute ago';
    }
    const minutes = Math.floor(ageSeconds / 60);
    if (minutes < 60) {
        return `last seen: ${minutes} min ago`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 48) {
        return `last seen: ${hours} h ago`;
    }
    return `last seen: ${Math.floor(hours / 24)} days ago`;
}

/**
 * Blocklist sanitizer for wire-supplied link destinations — PORT of @phlix/ui's
 * `safeHref` (M-UI-11 law, home `src/utils/safeHref.ts` in the phlix-ui repo),
 * reproduced byte-faithfully because the hub is pinned to @phlix/ui v0.99.10,
 * whose barrel does not yet export the primitive (published from the barrel in
 * the ui r2 rework, post-10.10). Retire this copy on the next ui re-pin by
 * importing from the package instead.
 *
 * Returns `undefined` for script-y schemes (after control/space stripping) so
 * callers bind it straight into `:href`/guard `window.open` with one check.
 */
export function safeHref(href: string): string | undefined {
    // The control chars ARE the point: browsers ignore them inside URL schemes,
    // so the sanitizer must strip them before the scheme test.
    // eslint-disable-next-line no-control-regex
    const probe = href.replace(/[\x00-\x20]/g, '');
    return /^(javascript|data|vbscript):/i.test(probe) ? undefined : href;
}
