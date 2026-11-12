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
 * boot, keys on the uniform 503 frame, and shows/hides a viewport-pinned
 * warning notice whose copy is branded per audience (admin: where to switch it
 * off; member: wait and retry). The banner is deliberately dumb:
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

/**
 * Who is reading the banner. The toggle lives behind the admin gate
 * (`/app/admin/settings`), so pointing a member at it is a dead end: only an
 * admin is told where maintenance is switched off; a member is told what to
 * do instead (wait and retry — the same advice the server's 503 frame gives).
 */
export type MaintenanceAudience = 'admin' | 'member';

/** Where an admin turns maintenance mode off (hub admin settings route). */
export const MAINTENANCE_SETTINGS_PATH = '/app/admin/settings';

/** One rendered banner: a bold lead, the sentence, and an optional link. */
export interface MaintenanceNoticeCopy {
    lead: string;
    text: string;
    link: { href: string; label: string } | null;
}

/**
 * Pure copy table (English, law above). The lead + text are shared; only an
 * admin gets the settings link — a member never sees an admin-only route.
 */
export function maintenanceNoticeCopy(audience: MaintenanceAudience): MaintenanceNoticeCopy {
    const lead = 'Maintenance in progress.';
    if (audience === 'admin') {
        return {
            lead,
            text: 'Maintenance mode is on, so some hub actions will fail until it is turned off in ',
            link: { href: MAINTENANCE_SETTINGS_PATH, label: 'Admin settings' },
        };
    }
    return {
        lead,
        text: 'This hub is under maintenance, so some actions will fail. Try again shortly.',
        link: null,
    };
}

/**
 * Presentation law: the banner is pinned to the BOTTOM of the viewport
 * (`position: fixed`) so it stays in view at any scroll depth without fighting
 * the shell's sticky top bar (`@phlix/ui` `.shell__bar`, sticky top:0). It sits
 * above modals (1000) — failures inside a dialog are exactly when the reader
 * needs the reason — and below the command palette / tooltips (1100) and
 * toasts (1200). Tone is the theme's warning pair (`--color-warning` accent,
 * `--color-warning-bg` wash over an opaque surface so page content never
 * bleeds through). `[hidden]` must win over the flex display, hence the
 * `:not([hidden])` guard. While shown, the body reserves the banner's height
 * at the bottom so it never permanently covers the end of the page.
 */
export const MAINTENANCE_NOTICE_CSS = `
#${MAINTENANCE_NOTICE_ID}:not([hidden]) {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1050;
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.25rem 0.5rem;
    padding: 0.75rem 1rem;
    background-color: var(--surface-1, #18181b);
    background-image: linear-gradient(var(--color-warning-bg, #e8a33d29), var(--color-warning-bg, #e8a33d29));
    border-top: 3px solid var(--color-warning, #e8a33d);
    color: var(--color-text, #e4e4e7);
    font-size: var(--text-sm, 0.875rem);
    line-height: 1.4;
    box-shadow: var(--shadow-3, 0 8px 24px #0006);
}
#${MAINTENANCE_NOTICE_ID} strong {
    color: var(--color-warning, #e8a33d);
}
#${MAINTENANCE_NOTICE_ID} a {
    color: inherit;
    font-weight: 600;
    text-decoration: underline;
}
`;

/** Optional boot wiring: how the banner learns who is reading it. */
export interface MaintenanceNoticeOptions {
    /** Read at every activation (auth may settle after boot). Throws → member. */
    isAdmin?: () => boolean;
}

/**
 * Install once per document: inject the style, create the hidden banner, then
 * wrap `globalThis.fetch`. Idempotent by element id; inert outside a browser.
 */
export function installMaintenanceNotice(options: MaintenanceNoticeOptions = {}): void {
    if (typeof document === 'undefined' || typeof globalThis.fetch !== 'function') {
        return;
    }
    if (document.getElementById(MAINTENANCE_NOTICE_ID) !== null) {
        return;
    }

    const style = document.createElement('style');
    style.textContent = MAINTENANCE_NOTICE_CSS;
    document.head.appendChild(style);

    const banner = document.createElement('div');
    banner.id = MAINTENANCE_NOTICE_ID;
    banner.setAttribute('role', 'status');
    banner.hidden = true;
    document.body.appendChild(banner);

    let rendered: MaintenanceAudience | null = null;
    const audience = (): MaintenanceAudience => {
        try {
            return options.isAdmin?.() === true ? 'admin' : 'member';
        } catch {
            return 'member';
        }
    };
    const render = (who: MaintenanceAudience): void => {
        if (rendered === who) {
            return;
        }
        const copy = maintenanceNoticeCopy(who);
        const lead = document.createElement('strong');
        lead.textContent = copy.lead;
        const body = document.createElement('span');
        body.append(copy.text);
        if (copy.link !== null) {
            const link = document.createElement('a');
            link.href = copy.link.href;
            link.textContent = copy.link.label;
            body.append(link, '.');
        }
        banner.replaceChildren(lead, body);
        rendered = who;
    };

    globalThis.fetch = withMaintenanceSignal(
        globalThis.fetch as unknown as FetchLike,
        (signal) => {
            if (signal === 'active') {
                render(audience());
                banner.hidden = false;
                document.body.style.paddingBottom = `${banner.offsetHeight}px`;
            } else {
                banner.hidden = true;
                document.body.style.paddingBottom = '';
            }
        },
    ) as unknown as typeof globalThis.fetch;
}
