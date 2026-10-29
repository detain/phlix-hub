/**
 * Shared-With-Me hub-vantage liveness law — [review hub__casualgamer M3].
 *
 * Two families of pins:
 *
 *  1. TRUTH TABLE on `src/utils/shareLiveness.ts` (pure module, importable
 *     under node's native TS type stripping — same posture as
 *     i18n-seam.test.ts): the block-reason gate, the badge registers, the
 *     unknown floor for absent/garbage wire values, and the ported `safeHref`
 *     blocklist.
 *  2. WIRE-OF-COMPONENT law pins reading the .vue / main.ts / PHP sources as
 *     text (same posture as error-envelope.test.ts reading the pinned ui
 *     client): the Open button's `:disabled` binding MUST be gated by
 *     `openBlock`, the block reason MUST render visibly (the review's complaint
 *     was SILENCE, not disability), `window.open` must ride `safeHref`, the
 *     route must mount the hub-local page, and the TS badge vocabulary must
 *     equal the PHP `ShareServerLiveness` constants (cross-language drift
 *     tripwire).
 *
 * Mutation teeth: deleting the `:disabled` gate or the visible-reason binding
 * reddens a NAMED assertion below; flipping a PHP constant reddens the
 * vocabulary pin.
 *
 * Runtime: node's built-in test runner (`npm test`).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import {
    shareOpenBlockReason,
    livenessBadge,
    shareLiveness,
    formatLastSeen,
    safeHref,
} from '../src/utils/shareLiveness.ts';
import type { HubIncomingShare } from '../src/utils/shareLiveness.ts';

const PAGE_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/pages/HubSharedWithMePage.vue', import.meta.url)),
    'utf8',
);
const MAIN_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/main.ts', import.meta.url)),
    'utf8',
);
const PHP_MAPPER_SOURCE = readFileSync(
    fileURLToPath(new URL('../../src/Hub/ShareServerLiveness.php', import.meta.url)),
    'utf8',
);

function share(overrides: Partial<HubIncomingShare> = {}): HubIncomingShare {
    return {
        share_id: 'share-1',
        owner_user_id: 'owner-1',
        owner_name: 'Owner',
        server_id: 'server-1',
        server_name: 'My Server',
        library_id: 'lib-1',
        library_name: 'My Movies',
        library_item_count: 0,
        permission_level: 'read',
        access_urls: ['https://server.example.com'],
        created_at: 1_700_000_000,
        expires_at: null,
        ...overrides,
    };
}

describe('shareOpenBlockReason — the dead button can never ship silently', () => {
    it('blocks when no address is advertised', () => {
        const reason = shareOpenBlockReason(share({ access_urls: [] }));
        assert.ok(reason, 'empty access_urls must disable the Open button');
        assert.match(reason, /advertised/i);
    });

    it('blocks unreachable servers and names the hub vantage + NAT truth', () => {
        const reason = shareOpenBlockReason(share({ server_liveness: 'unreachable' }));
        assert.ok(reason);
        assert.match(reason, /hub cannot currently reach/i);
        assert.match(reason, /NAT/);
        assert.match(reason, /offline/);
    });

    it('allows reachable servers', () => {
        assert.equal(shareOpenBlockReason(share({ server_liveness: 'reachable' })), null);
    });

    it('allows unknown servers — unverified is not disproven', () => {
        assert.equal(shareOpenBlockReason(share({ server_liveness: 'unknown' })), null);
    });

    it('treats an old-hub payload without the trio as unknown, never false-live', () => {
        const legacy = share();
        assert.equal(shareLiveness(legacy), 'unknown');
        assert.equal(shareOpenBlockReason(legacy), null);
    });

    it('floors garbage wire vocabulary to unknown (parse, do not validate)', () => {
        assert.equal(shareLiveness(share({ server_liveness: 'banana' })), 'unknown');
        assert.equal(shareLiveness(share({ server_liveness: 'REACHABLE' })), 'unknown');
    });
});

describe('livenessBadge — three honest registers, no theatre', () => {
    it('reachable via heartbeat says reachable FROM THE HUB', () => {
        const badge = livenessBadge(share({ server_liveness: 'reachable', server_relay_active: false }));
        assert.equal(badge.tone, 'success');
        assert.match(badge.text, /Reachable from hub/);
    });

    it('reachable via relay is stated as relay truth', () => {
        const badge = livenessBadge(share({ server_liveness: 'reachable', server_relay_active: true }));
        assert.equal(badge.tone, 'success');
        assert.match(badge.text, /relay/i);
    });

    it('unreachable is an error tone', () => {
        const badge = livenessBadge(share({ server_liveness: 'unreachable' }));
        assert.equal(badge.tone, 'error');
        assert.match(badge.text, /Unreachable/);
    });

    it('unknown/absent is neutral — never doom, never false-live', () => {
        for (const s of [share(), share({ server_liveness: 'unknown' }), share({ server_liveness: 'banana' })]) {
            const badge = livenessBadge(s);
            assert.equal(badge.tone, 'neutral');
            assert.match(badge.text, /not verified/i);
        }
    });
});

describe('formatLastSeen — hub-clock prose', () => {
    const NOW_MS = 1_800_000_000_000;

    it('states time unknown for absent signal', () => {
        assert.match(formatLastSeen(null, NOW_MS), /time unknown/);
        assert.match(formatLastSeen(undefined, NOW_MS), /time unknown/);
    });

    it('renders age buckets from the epoch', () => {
        assert.match(formatLastSeen(1_800_000_000 - 10, NOW_MS), /less than a minute/);
        assert.match(formatLastSeen(1_800_000_000 - 300, NOW_MS), /5 min ago/);
        assert.match(formatLastSeen(1_800_000_000 - 7200, NOW_MS), /2 h ago/);
        assert.match(formatLastSeen(1_800_000_000 - 86_400 * 3, NOW_MS), /3 days ago/);
    });

    it('clamps future timestamps to zero age instead of negative prose', () => {
        assert.match(formatLastSeen(1_800_000_000 + 500, NOW_MS), /less than a minute/);
    });
});

describe('safeHref port — same sink guard as the vendored page', () => {
    it('blocks script-y schemes', () => {
        assert.equal(safeHref('javascript:alert(1)'), undefined);
        assert.equal(safeHref('data:text/html,<script>'), undefined);
        assert.equal(safeHref('vbscript:msgbox'), undefined);
    });

    it('blocks control-char-obfuscated schemes (browsers ignore them in URLs)', () => {
        assert.equal(safeHref('java\x09script:alert(1)'), undefined);
        assert.equal(safeHref('\x01javascript:alert(1)'), undefined);
    });

    it('passes ordinary and inert schemes', () => {
        assert.equal(safeHref('https://server.example.com'), 'https://server.example.com');
        assert.equal(safeHref('http://192.168.1.5:32400'), 'http://192.168.1.5:32400');
    });
});

describe('page wire-of-component law — the gate is structural, not a vibe', () => {
    it('binds the Open button :disabled through the block reason', () => {
        assert.match(
            PAGE_SOURCE,
            /:disabled="openBlock\(share\) !== null"/,
            'Open button must be disabled exactly when shareOpenBlockReason says so — removing this gate ships dead buttons silently',
        );
    });

    it('renders the block reason VISIBLY, not only as a title tooltip', () => {
        assert.match(
            PAGE_SOURCE,
            /\{\{\s*openBlock\(share\)\s*\?\?\s*statusLine\(share\)\s*\}\}/,
            'the reason sentence must appear on the card — the review complaint was silence',
        );
    });

    it('short-circuits openServer on the block reason and via safeHref', () => {
        assert.match(PAGE_SOURCE, /if \(openBlock\(share\) !== null\) \{/);
        assert.match(PAGE_SOURCE, /const safe = safeHref\(/);
        assert.match(PAGE_SOURCE, /window\.open\(safe, '_blank', 'noopener,noreferrer'\)/);
    });

    it('states the hub-vantage framing in page copy', () => {
        assert.match(PAGE_SOURCE, /from the hub/i);
        assert.match(PAGE_SOURCE, /behind NAT/);
    });

    it('mounts the hub-local page on /app/shared-with-me', () => {
        assert.match(MAIN_SOURCE, /import HubSharedWithMePage from '\.\/pages\/HubSharedWithMePage\.vue';/);
        const route = MAIN_SOURCE.match(/path: '\/app\/shared-with-me',[\s\S]*?\},/);
        assert.ok(route, 'shared-with-me route must exist in main.ts');
        assert.match(route[0], /component: HubSharedWithMePage,/);
        assert.ok(
            !/(?<!Hub)SharedWithMePage/.test(MAIN_SOURCE),
            'the vendored dead-button page must no longer be imported/mounted by the hub',
        );
    });
});

describe('cross-language wire vocabulary tripwire', () => {
    it('TS states equal the PHP ShareServerLiveness constants', () => {
        assert.match(PHP_MAPPER_SOURCE, /const REACHABLE = 'reachable';/);
        assert.match(PHP_MAPPER_SOURCE, /const UNREACHABLE = 'unreachable';/);
        assert.match(PHP_MAPPER_SOURCE, /const UNKNOWN = 'unknown';/);
    });

    it('DTO payload carries the trio the page reads', () => {
        const dtoSource = readFileSync(
            fileURLToPath(new URL('../../src/Hub/SharedLibraryDto.php', import.meta.url)),
            'utf8',
        );
        for (const key of ['server_liveness', 'server_last_seen_at', 'server_relay_active']) {
            assert.ok(dtoSource.includes(`'${key}' =>`), `toPayload() must emit ${key}`);
        }
    });
});
