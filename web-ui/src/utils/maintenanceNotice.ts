/**
 * Maintenance-mode visibility law — SH-079 [review hub__hub-health #5].
 *
 * While `hub.maintenance_mode` is on, the hub answers 503
 * `provider_unavailable` for API traffic (the gate + exemption audit live in
 * `src/Hub/MaintenanceGate.php`; the frame is emitted by
 * `Application::maintenanceResponse()` and whole-frame-pinned by
 * `tests/Unit/ApplicationMaintenanceResponseTest.php`). Before this module the
 * state was visible ONLY as scattered failed calls: an operator browsing the
 * portal saw buttons die, never WHY.
 *
 * This module is the hub-level banner: it wraps `globalThis.fetch` once at
 * boot, keys on the uniform 503 frame, and shows/hides a static notice. The
 * banner is deliberately dumb:
 *
 *  - it never blocks a request — the server is the authority, the notice only
 *    narrates what the responses already say;
 *  - the escape-hatch rule is honored automatically: the exempt surfaces
 *    (`/api/v1/auth/`, `…/me/hub-settings`, `/api/v1/admin/…`) answer non-503
 *    while maintenance is on, so the toggle page itself keeps working exactly
 *    as the gate promises, and the banner never interferes with it;
 *  - the 503-frame shape is LOAD-BEARING for the client: the server-side pin
 *    is the first tooth, this matcher is the second. A frame change without a
 *    matching change here kills the banner silently — keep them together.
 *
 * Prose is English-only on purpose: hub-local chrome copy rides the documented
 * partial-adoption law of `src/i18n/hub-messages.ts` (banner/notice text is not
 * a menu key; no locale rotation this wave).
 *
 * Pure classifier + injectable wrapper (Law 3): pinned headless by
 * `tests/maintenance-notice.test.ts` under `npm test`, no DOM required.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

/** What one observed API response tells us about maintenance state. */
export type MaintenanceSignal = 'active' | 'clear';

/** The uniform maintenance frame's code (server SSOT: MaintenanceGate.php). */
export const MAINTENANCE_FRAME_CODE = 'provider_unavailable';

/** DOM id of the banner — also the double-install guard. */
export const MAINTENANCE_NOTICE_ID = 'phlix-maintenance-notice';

/**
 * Pure truth table: 503 + `provider_unavailable` → the hub is under
 * maintenance; any 2xx API answer → it is not. Everything else (4xx, 304,
 * network-level noise) carries NO information about maintenance and must not
 * flip the banner.
 */
export function maintenanceSignal(status: number, code: unknown): MaintenanceSignal | null {
    if (status === 503 && code === MAINTENANCE_FRAME_CODE) {
        return 'active';
    }
    if (status >= 200 && status <= 299) {
        return 'clear';
    }
    return null;
}

/** Fetch-shaped function (structurally typed so the test can fake it). */
export type FetchLike = (input: { url: string } | string, init?: unknown) => Promise<{
    status: number;
    clone(): { json(): Promise<unknown> };
}>;

/**
 * Wrap a fetch with maintenance observation. Only `/api/` responses speak for
 * the hub's API surface; everything else (static assets, help-page proxies) is
 * ignored. The wrapper never throws and never consumes the original body —
 * the clone exists solely to read the frame code on the 503 leg.
 */
export function withMaintenanceSignal(
    inner: FetchLike,
    onSignal: (signal: MaintenanceSignal) => void,
): FetchLike {
    return async (input, init) => {
        const response = await inner(input, init);
        try {
            const url = typeof input === 'string' ? input : input.url;
            if (url.includes('/api/')) {
                let code: unknown = null;
                if (response.status === 503) {
                    try {
                        code = ((await response.clone().json()) as { code?: unknown }).code;
                    } catch {
                        code = null; // non-JSON 503 is not the maintenance frame
                    }
                }
                const signal = maintenanceSignal(response.status, code);
                if (signal !== null) {
                    onSignal(signal);
                }
            }
        } catch {
            // Banner plumbing must never break the caller's request.
        }
        return response;
    };
}

/** The notice sentence (English, law above). */
const NOTICE_TEXT_BEFORE =
    'This hub is under maintenance — some actions will fail until it ends. It can be toggled at the ';
const NOTICE_LINK_TEXT = 'admin settings';
const NOTICE_TEXT_AFTER = ' page.';

/**
 * Install once per document: create the hidden banner, then wrap
 * `globalThis.fetch`. Idempotent by element id; inert outside a browser.
 */
export function installMaintenanceNotice(): void {
    if (typeof document === 'undefined' || typeof globalThis.fetch !== 'function') {
        return;
    }
    if (document.getElementById(MAINTENANCE_NOTICE_ID) !== null) {
        return;
    }

    const banner = document.createElement('div');
    banner.id = MAINTENANCE_NOTICE_ID;
    banner.setAttribute('role', 'status');
    banner.hidden = true;

    const link = document.createElement('a');
    link.href = '/app/admin/settings';
    link.textContent = NOTICE_LINK_TEXT;

    banner.append(NOTICE_TEXT_BEFORE, link, NOTICE_TEXT_AFTER);
    document.body.appendChild(banner);

    globalThis.fetch = withMaintenanceSignal(
        globalThis.fetch as unknown as FetchLike,
        (signal) => {
            banner.hidden = signal !== 'active';
        },
    ) as unknown as typeof globalThis.fetch;
}
