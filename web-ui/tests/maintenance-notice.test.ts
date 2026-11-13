/**
 * SH-079 maintenance-notice law pins (headless, `npm test`).
 *
 * Pins the client half of the maintenance-mode visibility contract:
 *
 *  1. the pure truth table (`maintenanceSignal`) — 503+frame code is the ONLY
 *     activation, 2xx is the only clearance, everything else must stay silent;
 *  2. the wrapper mechanics — body never consumed (clone-only), non-API URLs
 *     ignored, and a non-JSON 503 must not throw nor activate;
 *  3. the cross-boundary tooth: the frame code + the whole-frame server legs
 *     are SHARED literals between this matcher and the PHP emitter
 *     (`src/Application.php` via `MaintenanceGate`), each pinned against the
 *     other so a server-side frame change reddens here before the banner dies
 *     silently in production;
 *  4. the boot wiring: install AFTER mount in `src/main.ts`, with the admin
 *     audience resolver wired;
 *  5. presentation (HUB-13/HUB-88): viewport-pinned placement + warning tone,
 *     and audience-branded copy — only an admin is shown the settings link.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import {
    MAINTENANCE_FRAME_CODE,
    MAINTENANCE_NOTICE_CSS,
    MAINTENANCE_NOTICE_ID,
    MAINTENANCE_SETTINGS_PATH,
    installMaintenanceNotice,
    maintenanceNoticeCopy,
    maintenanceSignal,
    withMaintenanceSignal,
    type FetchLike,
    type MaintenanceSignal,
} from '../src/utils/maintenanceNotice.ts';

const REPO = (rel: string): string =>
    readFileSync(fileURLToPath(new URL(rel, import.meta.url)), 'utf8');

/** Minimal fetch-shaped fake; records whether the ORIGINAL body was touched. */
function fakeResponse(status: number, json: unknown, consumeOriginal = false) {
    return {
        status,
        originalBodyConsumed: false,
        clone() {
            return {
                json: async () => {
                    if (json instanceof Error) {
                        throw json;
                    }
                    return json;
                },
            };
        },
        json: async () => {
            this.originalBodyConsumed = true;
            return json;
        },
        _expectConsume: consumeOriginal,
    };
}

async function runWrapper(response: ReturnType<typeof fakeResponse>, url: string) {
    const signals: MaintenanceSignal[] = [];
    const inner = (async () => response) as unknown as FetchLike;
    const wrapped = withMaintenanceSignal(inner, (s) => signals.push(s));
    const returned = await wrapped({ url });
    return { signals, returned };
}

describe('maintenanceSignal truth table', () => {
    it('activates ONLY on the uniform maintenance frame', () => {
        assert.equal(maintenanceSignal(503, MAINTENANCE_FRAME_CODE), 'active');
    });

    it('clears on every 2xx leg', () => {
        assert.equal(maintenanceSignal(200, null), 'clear');
        assert.equal(maintenanceSignal(204, 'anything'), 'clear');
        assert.equal(maintenanceSignal(299, null), 'clear');
    });

    it('stays silent on everything else (no information)', () => {
        assert.equal(maintenanceSignal(503, 'other_code'), null);
        assert.equal(maintenanceSignal(503, null), null);
        assert.equal(maintenanceSignal(401, MAINTENANCE_FRAME_CODE), null);
        assert.equal(maintenanceSignal(500, MAINTENANCE_FRAME_CODE), null);
        assert.equal(maintenanceSignal(304, null), null);
    });
});

describe('withMaintenanceSignal mechanics', () => {
    it('reads the code from a CLONE — the caller keeps its body', async () => {
        const { signals, returned } = await runWrapper(
            fakeResponse(503, { code: MAINTENANCE_FRAME_CODE }),
            '/api/v1/me/servers',
        );
        assert.deepEqual(signals, ['active']);
        assert.equal(returned.originalBodyConsumed, false);
    });

    it('passes a non-JSON 503 through without signal or throw', async () => {
        const { signals, returned } = await runWrapper(
            fakeResponse(503, new Error('not json')),
            '/api/v1/me/servers',
        );
        assert.deepEqual(signals, []);
        assert.equal(returned.status, 503);
    });

    it('ignores non-API traffic', async () => {
        const { signals } = await runWrapper(
            fakeResponse(503, { code: MAINTENANCE_FRAME_CODE }),
            '/assets/app/index.js',
        );
        assert.deepEqual(signals, []);
    });

    it('clears on a 2xx API response without parsing the body', async () => {
        const response = fakeResponse(200, { ok: true });
        const { signals } = await runWrapper(response, '/api/v1/me/servers');
        assert.deepEqual(signals, ['clear']);
        assert.equal(response.originalBodyConsumed, false);
    });
});

describe('cross-boundary frame law (PHP emitter ⇄ TS matcher)', () => {
    it('the server still emits the pinned maintenance code', () => {
        const app = REPO('../../src/Application.php');
        assert.ok(app.includes(`'${MAINTENANCE_FRAME_CODE}'`), 'Application.php no longer emits the code the banner keys on');
        assert.ok(
            app.includes('This hub is under maintenance. Try again shortly.'),
            'the whole-frame prose leg drifted from tests/Unit/ApplicationMaintenanceResponseTest.php + this pin',
        );
        const gate = REPO('../../src/Hub/MaintenanceGate.php');
        assert.ok(gate.includes(MAINTENANCE_FRAME_CODE), 'gate docblock law pointer missing');
    });

    it('main.ts installs the notice after mount', () => {
        const main = REPO('../src/main.ts');
        assert.ok(
            main.includes("import { installMaintenanceNotice } from './utils/maintenanceNotice';"),
            'main.ts no longer imports the notice module',
        );
        const call = 'installMaintenanceNotice({ isAdmin: () => useAuthStore().isAdmin });';
        assert.ok(main.includes(call), 'main.ts must wire the admin audience resolver into the notice');
        assert.ok(
            main.indexOf(call) > main.indexOf("app.mount('#phlix-app');"),
            'installMaintenanceNotice must run after app.mount',
        );
    });
});

describe('maintenance notice presentation (HUB-13 / HUB-88)', () => {
    it('only an admin is pointed at the admin-only settings route', () => {
        const admin = maintenanceNoticeCopy('admin');
        assert.deepEqual(admin.link, { href: MAINTENANCE_SETTINGS_PATH, label: 'Admin settings' });
        assert.equal(MAINTENANCE_SETTINGS_PATH, '/app/admin/settings');

        const member = maintenanceNoticeCopy('member');
        assert.equal(member.link, null, 'a member must never be linked to an admin-only page');
        assert.ok(!/admin/i.test(member.text), 'member copy must not mention admin settings');
        assert.ok(/Try again shortly\./.test(member.text), 'member copy gives the retry advice the 503 frame gives');
    });

    it('both audiences share the lead and say actions will fail', () => {
        for (const who of ['admin', 'member'] as const) {
            const copy = maintenanceNoticeCopy(who);
            assert.equal(copy.lead, 'Maintenance in progress.');
            assert.ok(copy.text.includes('actions will fail'), `${who} copy lost the consequence`);
        }
    });

    it('is pinned to the viewport in the warning tone, above modals', () => {
        const css = MAINTENANCE_NOTICE_CSS;
        assert.ok(css.includes(`#${MAINTENANCE_NOTICE_ID}:not([hidden])`), '[hidden] must still win over display');
        assert.match(css, /position:\s*fixed;/);
        assert.match(css, /bottom:\s*0;/);
        assert.match(css, /z-index:\s*1050;/);
        assert.ok(css.includes('var(--color-warning-bg'), 'warning wash missing');
        assert.ok(css.includes('border-top: 3px solid var(--color-warning'), 'warning accent rule missing');
        assert.ok(css.includes('background-color: var(--surface,'), 'banner must be opaque over page content');
        // --surface-1 is defined by no @phlix/ui theme: its dark fallback would win in Daylight (dark text on a near-black bar).
        assert.ok(!css.includes('--surface-1'), 'banner must use a theme-defined surface token');
    });
    it('lifts the fixed music bar by the published banner height (HUB-S4)', () => {
        const css = MAINTENANCE_NOTICE_CSS;
        assert.ok(css.includes(':root .music-bar.music-bar'), 'music-bar offset rule missing');
        assert.match(css, /bottom:\s*var\(--maintenance-notice-h,\s*0px\);/);
    });

    it('re-measures the banner on resize via a ResizeObserver, and clears on hide (HUB-S4)', async () => {
        const g = globalThis as Record<string, unknown>;
        const saved = { document: g.document, fetch: g.fetch, RO: g.ResizeObserver };
        const bodyStyle: Record<string, string> = { paddingBottom: '' };
        const rootProps = new Map<string, string>();
        let height = 40;
        let roCb: (() => void) | null = null;
        let roDisconnected = 0;
        const banner: Record<string, unknown> = {
            hidden: true,
            setAttribute() {},
            replaceChildren() {},
            get offsetHeight() { return height; },
        };
        const fakeEl = () => ({ append() {}, appendChild() {}, set textContent(_v: string) {} });
        g.document = {
            getElementById: () => null,
            createElement: (tag: string) => (tag === 'div' ? banner : fakeEl()),
            head: { appendChild() {} },
            body: { style: bodyStyle, appendChild() {} },
            documentElement: {
                style: {
                    setProperty: (k: string, v: string) => rootProps.set(k, v),
                    removeProperty: (k: string) => rootProps.delete(k),
                },
            },
        };
        g.ResizeObserver = class {
            constructor(cb: () => void) { roCb = cb; }
            observe() {}
            disconnect() { roDisconnected++; }
        };
        let status = 503;
        g.fetch = async () => ({ status, clone: () => ({ json: async () => ({ code: MAINTENANCE_FRAME_CODE }) }) });
        try {
            installMaintenanceNotice();
            await (g.fetch as FetchLike)('/api/v1/x');
            assert.equal(bodyStyle.paddingBottom, '40px');
            assert.equal(rootProps.get('--maintenance-notice-h'), '40px');
            height = 88; // flex-wrapped banner grew
            assert.ok(roCb !== null, 'observer must be armed while shown');
            (roCb as () => void)();
            assert.equal(bodyStyle.paddingBottom, '88px', 'stale padding after resize');
            assert.equal(rootProps.get('--maintenance-notice-h'), '88px');
            status = 200;
            await (g.fetch as FetchLike)('/api/v1/x');
            assert.equal(bodyStyle.paddingBottom, '');
            assert.equal(rootProps.has('--maintenance-notice-h'), false);
            assert.equal(roDisconnected, 1);
        } finally {
            g.document = saved.document;
            g.fetch = saved.fetch;
            g.ResizeObserver = saved.RO;
        }
    });
});
