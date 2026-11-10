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
 *  4. the boot wiring: install AFTER mount in `src/main.ts`.
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
        assert.ok(
            main.indexOf('installMaintenanceNotice();') > main.indexOf("app.mount('#phlix-app');"),
            'installMaintenanceNotice must run after app.mount',
        );
    });
});
