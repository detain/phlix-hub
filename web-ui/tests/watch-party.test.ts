/**
 * Watch-party wire law — [review hub__casualgamer M5] (M-HUB-08).
 *
 * Two families of pins (same posture as security-settings-page.test.ts):
 *
 *  1. TRUTH TABLE on `src/utils/watchPartyWire.ts` (pure module, importable
 *     under node's native TS type stripping): the dial URL law (origin port
 *     NEVER wins, scheme follows https), the bearer subprotocol pair, every
 *     outbound frame's exact canonical name and field keys, the inbound parse
 *     boundary (garbage drops, members-dict tolerates junk rows, the joiner's
 *     `your_id` survives, opaque fan-outs are acknowledged without claims),
 *     the party-link path, refusal sentences (known codes curated, unknown
 *     codes verbatim + code — never a fake success), roster ordering, position
 *     and age math. The copy-link hint is pinned to the TRUTH (every member
 *     holds the same link — r2 MINOR-1) and every `socket.send` in
 *     `leaveParty` to the OPEN guard (r2 INFO-1).
 *  2. WIRE-OF-COMPONENT + CROSS-LANGUAGE pins reading .vue / main.ts /
 *     docs / PHP as TEXT: the menu entry exists (a route nobody navigates to
 *     is the same defect in a different coat), both routes mount the hub-local
 *     page, the deep link route is NOT public (minting needs the session),
 *     the page never puts a token in a URL (S237 law re-pinned client-side),
 *     and — the drift tripwires — `RELAY_PORT` equals `DEFAULT_PORT` in
 *     `SyncPlayRelayWorker.php`, the party link path equals the route path in
 *     `main.ts`, every `syncplay_*` name the client SENDS appears in the
 *     documented canonical catalog (`docs/websockets.md`), every mint-failure
 *     code the page names is exactly a code
 *     `ClientRelayTokenController::mint` can answer with, and every curated
 *     wire-error code appears in the docs or the worker. A new server code or
 *     an invented client type reddens a NAMED assertion here instead of
 *     shipping a lie in the UI.
 *  3. VENDORED-ENTRY cross-pins (r2, review M8-r1 MAJOR-1): the pinned
 *     `@phlix/ui` DOES export the dial law at its entry
 *     (`buildHubRelayUrl`/`HUB_SYNC_PLAY_PORT`, `dist/index.d.ts:16`) — the
 *     hub-local re-statement is a CHOSEN posture (the ui socket API is a
 *     store-bound pending-command consumer), so the two 8804 law producers
 *     are pinned against EACH OTHER: port equality, byte-identical URL
 *     output, and a regression pin that the false "unexported seam" premise
 *     never returns in the shipped docblocks.
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
    RELAY_PORT,
    buildRelayUrl,
    relaySubprotocols,
    buildPartyLink,
    normalizePartyCode,
    frameGroupCreate,
    frameGroupJoin,
    frameGroupList,
    frameGroupLeave,
    parsePartyFrame,
    partyErrorSentence,
    mintFailureSentence,
    MINT_FAILURE_SENTENCES,
    WIRE_ERROR_SENTENCES,
    sortMembersForDisplay,
    formatPlaybackPosition,
    formatAge,
    lastHeardClause,
    playbackLine,
    activitySentence,
    relayCloseSentence,
    relayUnreachableSentence,
    MAX_PARTY_MEMBERS,
    MAX_PARTY_QUEUE,
    RELAY_CLOSE_UNAUTHORIZED,
    RELAY_CLOSE_DO_NOT_RETRY,
    RELAY_CLOSE_TRY_AGAIN_LATER,
} from '../src/utils/watchPartyWire.ts';
import { formatLastSeen } from '../src/utils/shareLiveness.ts';
import type { PartyMember } from '../src/utils/watchPartyWire.ts';
import {
    HUB_SYNC_PLAY_PORT,
    buildHubRelayUrl,
    openHubRelayConnection,
} from '@phlix/ui';

const REPO = (rel: string): string =>
    readFileSync(fileURLToPath(new URL(`../../${rel}`, import.meta.url)), 'utf8');

const PAGE_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/pages/HubWatchPartyPage.vue', import.meta.url)),
    'utf8',
);
const MAIN_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/main.ts', import.meta.url)),
    'utf8',
);
const WIRE_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/utils/watchPartyWire.ts', import.meta.url)),
    'utf8',
);

/* ============================================================ truth table == */

describe('dial law (docs/websockets.md :8804, S237)', () => {
    it('pins the relay port — the origin port NEVER wins', () => {
        assert.equal(
            buildRelayUrl('https://hub.example.com:8443', 'srv-1'),
            'wss://hub.example.com:8804/syncplay/srv-1',
        );
    });

    it('follows the origin scheme https→wss, else ws', () => {
        assert.equal(buildRelayUrl('http://hub.lan', 'srv-1'), 'ws://hub.lan:8804/syncplay/srv-1');
        assert.ok(buildRelayUrl('https://hub.example.com', 'srv-1').startsWith('wss://'));
    });

    it('path carries the server id, percent-encoded', () => {
        assert.equal(
            buildRelayUrl('http://h', 'a/b?c'),
            'ws://h:8804/syncplay/a%2Fb%3Fc',
        );
    });

    it('throws instead of dialing an origin nobody chose', () => {
        assert.throws(() => buildRelayUrl(undefined, 'srv-1'), /no hub origin/);
    });

    it('honors the operator __PHLIX__ seam exactly like the pinned @phlix/ui lane', () => {
        const g = globalThis as { __PHLIX__?: unknown };
        try {
            g.__PHLIX__ = { wsUrl: 'wss://relay.example.com:9443', wsPath: null };
            assert.equal(
                buildRelayUrl('https://hub.example.com', 'srv/2'),
                'wss://relay.example.com:9443/syncplay/srv%2F2',
                'pinned wsUrl wins over origin derivation; wsPath null defaults to /syncplay',
            );
            g.__PHLIX__ = { wsUrl: 'ws://10.0.0.5', wsPath: '/relay-ws' };
            assert.equal(
                buildRelayUrl(undefined, 'srv-1'),
                'ws://10.0.0.5/relay-ws/srv-1',
            );
        } finally {
            delete g.__PHLIX__;
        }
        // Seam absent = legacy law (the parity twin below dials exactly this).
        assert.equal(
            buildRelayUrl('https://hub.example.com', 'srv-1'),
            'wss://hub.example.com:8804/syncplay/srv-1',
        );
    });

    it('carries the token ONLY as the bearer subprotocol pair', () => {
        assert.deepEqual(relaySubprotocols('tok'), ['bearer', 'tok']);
        // S237: a query-string credential is refused by design — the builder
        // must not even be able to emit one (no token parameter exists).
        assert.equal(buildRelayUrl.length, 2);
        assert.ok(!/\?[^)]*token/i.test(WIRE_SOURCE.replace(/^\s*\*.*$/gm, '')));
    });
});

describe('party link', () => {
    it('builds the deep route the SPA registers', () => {
        assert.equal(
            buildPartyLink('https://hub.example.com', 'srv 1', 'Movie Night'),
            'https://hub.example.com/app/party/srv%201/Movie%20Night',
        );
    });

    it('normalizePartyCode trims and refuses blank (the wire refuses it too)', () => {
        assert.equal(normalizePartyCode('  Movie Night  '), 'Movie Night');
        assert.equal(normalizePartyCode(''), null);
        assert.equal(normalizePartyCode('   '), null);
        assert.equal(normalizePartyCode(undefined), null);
        assert.equal(normalizePartyCode(42), null);
    });
});

describe('outbound frames — names and field keys are catalog law', () => {
    it('create/join/list/leave', () => {
        assert.deepEqual(frameGroupCreate('Friday', 'Ana'), {
            type: 'syncplay_group_create',
            group_name: 'Friday',
            member_name: 'Ana',
        });
        assert.deepEqual(frameGroupJoin('Friday', 'Bo'), {
            type: 'syncplay_group_join',
            group_id: 'Friday',
            member_name: 'Bo',
        });
        assert.deepEqual(frameGroupList(), { type: 'syncplay_group_list' });
        assert.deepEqual(frameGroupLeave(), { type: 'syncplay_group_leave' });
    });

    it('never sends password fields (the hub accepts-and-ignores inside the owner scope; rendering the field would advertise protection that does not exist)', () => {
        const joined = frameGroupJoin('x', 'y');
        assert.equal('password' in joined, false);
        assert.equal('password_hash' in joined, false);
    });
});

describe('inbound parse boundary (parse-don\'t-validate)', () => {
    const group = {
        group_id: 'Friday',
        group_name: 'Friday',
        member_count: 2,
        members: {
            'c-1': { id: 'c-1', name: 'Ana', is_host: true, joined_at: 100 },
            'c-2': { id: 'c-2', name: 'Bo', is_host: false, joined_at: 200 },
            junk: 'not-a-member',
        },
        host_id: 'c-1',
        current_media_id: 'media-9',
        current_media_duration: 0,
        playback_position: 61_500,
        playback_state: 'playing',
        queue: [{}, {}, {}],
        created_at: 100,
        last_activity_at: 250,
    };

    it('parses group_state with your_id (joiner shape)', () => {
        const frame = parsePartyFrame({ type: 'syncplay_group_state', group, your_id: 'c-2' });
        assert.ok(frame && frame.kind === 'group_state');
        assert.equal(frame.yourId, 'c-2');
        assert.equal(frame.state.groupId, 'Friday');
        assert.equal(frame.state.members.length, 2, 'junk member row dropped, real rows kept');
        assert.equal(frame.state.hostId, 'c-1');
        assert.equal(frame.state.playbackPositionMs, 61_500);
        assert.equal(frame.state.queueLength, 3);
    });

    it('broadcasts carry no your_id — the view keeps its own', () => {
        const frame = parsePartyFrame({ type: 'syncplay_group_state', group });
        assert.ok(frame && frame.kind === 'group_state');
        assert.equal(frame.yourId, null);
    });

    it('accepts a JSON string (the wire is text frames)', () => {
        const frame = parsePartyFrame(JSON.stringify({ type: 'syncplay_info', message: 'Bo joined the group' }));
        assert.deepEqual(frame, { kind: 'info', message: 'Bo joined the group' });
    });

    it('group_list rows coerce, blank-id rows drop, count falls back to rows', () => {
        const frame = parsePartyFrame({
            type: 'syncplay_group_list',
            groups: [
                { id: 'Friday', name: 'Friday', member_count: '2', has_password: false, current_media: 'm', is_playing: true },
                { id: '', name: 'ghost' },
            ],
        });
        assert.ok(frame && frame.kind === 'group_list');
        assert.equal(frame.groups.length, 1);
        assert.equal(frame.groups[0].memberCount, 2);
        assert.equal(frame.groups[0].isPlaying, true);
        assert.equal(frame.count, 1);
    });

    it('error frames keep code AND message', () => {
        const frame = parsePartyFrame({ type: 'syncplay_error', error_code: 'NOT_HOST', message: 'Only the host' });
        assert.deepEqual(frame, { kind: 'error', code: 'NOT_HOST', message: 'Only the host' });
    });

    it('host_elect requires the elected id', () => {
        assert.deepEqual(parsePartyFrame({ type: 'syncplay_host_elect', elected_id: 'c-2' }), {
            kind: 'host_elect',
            electedId: 'c-2',
        });
        assert.equal(parsePartyFrame({ type: 'syncplay_host_elect' }), null);
    });

    it('documented fan-outs stay opaque; garbage and bare-vocabulary die at the boundary', () => {
        assert.deepEqual(parsePartyFrame({ type: 'syncplay_playback_play', position: 1 }), {
            kind: 'relay',
            type: 'syncplay_playback_play',
        });
        assert.equal(parsePartyFrame({ type: 'group_join' }), null, 'bare vocabulary is not canonical');
        assert.equal(parsePartyFrame('not json'), null);
        assert.equal(parsePartyFrame(null), null);
        assert.equal(parsePartyFrame([1, 2]), null);
        assert.equal(parsePartyFrame({ noType: true }), null);
    });
});

describe('honest sentences', () => {
    it('known wire codes get curated sentences', () => {
        assert.match(partyErrorSentence('NOT_HOST', 'x'), /host/i);
    });

    it('unknown codes pass the hub message through VERBATIM with its code — never a fake success', () => {
        const s = partyErrorSentence('weird_code', 'raw hub words');
        assert.match(s, /weird_code/);
        assert.match(s, /raw hub words/);
    });

    it('mint sentences cover exactly the controller\'s refusal codes (map + tripwire below)', () => {
        assert.equal(Object.keys(MINT_FAILURE_SENTENCES).length, 3);
        assert.match(mintFailureSentence('server.not_owned', 'fb'), /another account/i);
        assert.equal(mintFailureSentence(null, 'fb'), 'fb');
        assert.equal(mintFailureSentence('unmapped', 'fb'), 'fb');
    });
});

describe('display math', () => {
    const m = (id: string, name: string, isHost: boolean, joinedAt: number | null): PartyMember => ({
        id, name, isHost, joinedAt,
    });

    it('host first, then join order, then id', () => {
        const ordered = sortMembersForDisplay([
            m('c-2', 'Bo', false, 200),
            m('c-1', 'Ana', true, 100),
            m('c-3', 'Cy', false, 150),
        ]);
        assert.deepEqual(ordered.map((x) => x.id), ['c-1', 'c-3', 'c-2']);
    });

    it('null join times sink without poisoning the sort', () => {
        const ordered = sortMembersForDisplay([m('z', 'Z', false, null), m('a', 'A', false, 100)]);
        assert.deepEqual(ordered.map((x) => x.id), ['a', 'z']);
    });

    it('position: m:ss below an hour, h:mm:ss above, never negative', () => {
        assert.equal(formatPlaybackPosition(61_500), '1:01');
        assert.equal(formatPlaybackPosition(0), '0:00');
        assert.equal(formatPlaybackPosition(-5), '0:00');
        assert.equal(formatPlaybackPosition(3_723_000), '1:02:03');
    });

    it('age bands from unix seconds', () => {
        assert.equal(formatAge(null, 1000), 'unknown');
        assert.equal(formatAge(999, 1000), 'just now');
        assert.equal(formatAge(1000 - 120, 1000), '2 min ago');
        assert.equal(formatAge(1000 - 7200, 1000), '2 h ago');
        assert.equal(formatAge(1_000_000 - 86_400 * 3, 1_000_000), '3 days ago');
        assert.equal(formatAge(1001, 1000), 'just now', 'future clamp');
    });
});

/* =================================================== wire-of-component ===== */

describe('portal wiring (menu + routes + page)', () => {
    it('the nav menu carries a Watch Party entry (a route nobody navigates to is the same defect in a different coat)', () => {
        // [review hub Minor 50] the label is catalog-driven; the byte-pin that
        // EN still renders 'Watch Party' lives in hub-menu-catalog.test.ts.
        assert.match(
            MAIN_SOURCE,
            /id:\s*'watch-party',\s*label:\s*t\('menu\.watchParty'\),\s*to:\s*'\/app\/watch-party'/,
        );
    });

    it('both routes mount the hub-local page', () => {
        assert.match(MAIN_SOURCE, /path:\s*'\/app\/watch-party'[\s\S]*?component:\s*HubWatchPartyPage/);
        assert.match(
            MAIN_SOURCE,
            /path:\s*'\/app\/party\/:serverId\/:code',\s*name:\s*'party-join',\s*component:\s*HubWatchPartyPage,\s*props:\s*true/,
        );
    });

    it('the deep route is NOT public — minting a relay token needs the session', () => {
        const idx = MAIN_SOURCE.indexOf("'/app/party/:serverId/:code'");
        const end = MAIN_SOURCE.indexOf('\n        },', idx);
        assert.ok(idx > 0 && end > idx, 'party route block found');
        const block = MAIN_SOURCE.slice(idx, end + 10);
        assert.ok(block.includes('props: true'));
        assert.equal(/public:\s*true/.test(block), false);
    });

    it('the page speaks only the four documented client ops', () => {
        for (const name of ['syncplay_group_create', 'syncplay_group_join', 'syncplay_group_list', 'syncplay_group_leave']) {
            assert.ok(WIRE_SOURCE.includes(`'${name}'`), `frame builder for ${name}`);
        }
    });

    it('the page mints per server (POST relay-token) and lists per account (GET /me/servers) — no other endpoints', () => {
        assert.match(PAGE_SOURCE, /\/api\/v1\/me\/servers\/' \+ serverId \+ '\/relay-token/);
        assert.match(PAGE_SOURCE, /http\.get<ServersResponse>\('\/api\/v1\/me\/servers'\)/);
        // The vendored server-facing syncplay REST (port 8097 world) is NOT
        // reachable through the hub (proxy allowlist pins media/search only)
        // — touching it here would be theatre.
        assert.equal(PAGE_SOURCE.includes('/api/v1/syncplay'), false);
    });

    it('the token NEVER rides a URL anywhere in the shipped surface (S237 client-side)', () => {
        const stripped = (PAGE_SOURCE + WIRE_SOURCE).replace(/^\s*(\*|\/\/).*$/gm, '');
        assert.equal(/\?\s*token=|\$\{[^}]*token[^}]*\}\s*(\/|')/.test(stripped), false);
        assert.ok(PAGE_SOURCE.includes('relaySubprotocols(token)'));
    });

    it('the honest scope sentence ships (no friend-join theatre)', () => {
        assert.match(PAGE_SOURCE, /still to come/);
        assert.match(MINT_FAILURE_SENTENCES['server.not_owned'], /still to come/);
    });

    it('leave/disconnect are real ops, copy failure degrades to the visible field', () => {
        assert.match(PAGE_SOURCE, /frameGroupLeave\(\)/);
        assert.match(PAGE_SOURCE, /socket\?\.close\(\)/);
        assert.match(PAGE_SOURCE, /copy it manually/);
    });
});

/* =================================================== cross-language pins === */

describe('drift tripwires against the PHP + docs sources', () => {
    it('RELAY_PORT equals the worker default port', () => {
        const php = REPO('src/SyncPlay/SyncPlayRelayWorker.php');
        const phpPort = php.match(/DEFAULT_PORT\s*=\s*(\d+)/);
        assert.ok(phpPort, 'DEFAULT_PORT literal found in SyncPlayRelayWorker.php');
        assert.equal(RELAY_PORT, Number(phpPort[1]));
    });

    it('the party link path equals the route path registered in main.ts', () => {
        assert.ok(MAIN_SOURCE.includes("'/app/party/:serverId/:code'"));
        assert.ok(WIRE_SOURCE.includes('/app/party/'));
    });

    it('every syncplay_* name the client SENDS is in the documented canonical catalog', () => {
        const docs = REPO('docs/websockets.md');
        const sent = WIRE_SOURCE.match(/type:\s*'(syncplay_[a-z_]+)'/g) ?? [];
        assert.ok(sent.length >= 4, 'the builders emit at least the four documented ops');
        for (const raw of sent) {
            const name = /'(syncplay_[a-z_]+)'/.exec(raw)?.[1] ?? '';
            assert.ok(docs.includes(`\`${name}\``), `${name} documented`);
        }
    });

    it('MINT_FAILURE_SENTENCES keys equal exactly the codes ClientRelayTokenController::mint answers with', () => {
        const php = REPO('src/Http/Controllers/ClientRelayTokenController.php');
        const body = php.slice(php.indexOf('public function mint'));
        const codes = [...body.matchAll(/'code'\s*=>\s*'([a-z._]+)'/g)].map((x) => x[1]);
        const unique = [...new Set(codes)];
        assert.ok(unique.length >= 3, 'the controller refuses with its documented codes');
        assert.deepEqual(
            Object.keys(MINT_FAILURE_SENTENCES).sort(),
            unique.sort(),
            'page vocab ↔ mint() vocab drift',
        );
    });

    it('every curated wire-error code exists in the docs or the worker', () => {
        const docs = REPO('docs/websockets.md');
        const php = REPO('src/SyncPlay/SyncPlayRelayWorker.php');
        for (const code of Object.keys(WIRE_ERROR_SENTENCES)) {
            assert.ok(
                docs.includes(code) || php.includes(`'${code}'`) || docs.includes(`\`${code}\``),
                `${code} is a wire vocabulary word`,
            );
        }
    });
});

/* ================================================= vendored-entry pins ==== */
/*
 * r2 (review hub__casualgamer M8-r1 MAJOR-1): at the pin, @phlix/ui's entry
 * DOES export the dial law. The hub-local re-statement is a chosen posture
 * (openHubRelayConnection is a store-bound pending_command/play_media
 * consumer — wrong shape for party ops), so the two 8804 law producers are
 * hereafter pinned against EACH OTHER, not excused by a false necessity claim.
 */

describe('dial law vs the pinned @phlix/ui entry (r2 cross-pins)', () => {
    it('HUB_SYNC_PLAY_PORT at the pin equals the hub-local RELAY_PORT', () => {
        assert.equal(HUB_SYNC_PLAY_PORT, RELAY_PORT);
    });

    it('buildHubRelayUrl and buildRelayUrl produce identical dials', () => {
        const cases = [
            ['https://hub.example.com', 'srv-1'],
            ['http://hub.lan:8443', 'srv/2'],
            ['https://h', 'a?b'],
            ['http://x.y:80', 'S e r v'],
        ] as const;
        for (const [base, id] of cases) {
            assert.equal(
                buildHubRelayUrl(base, id),
                buildRelayUrl(base, id),
                `dial law drift on (${base}, ${id})`,
            );
        }
    });

    it('the pin really exports the hubRelay seam the docblocks now claim', () => {
        const dts = readFileSync(
            fileURLToPath(new URL('../node_modules/@phlix/ui/dist/index.d.ts', import.meta.url)),
            'utf8',
        );
        assert.match(
            dts,
            /export \{ openHubRelayConnection, closeHubRelayConnection, getHubRelaySocket, parsePendingCommandFrame, buildHubRelayUrl, HUB_SYNC_PLAY_PORT, \} from '\.\/api\/hubRelay';/,
        );
        assert.equal(typeof openHubRelayConnection, 'function');
    });

    it('the false seam premise never returns in the shipped docblocks (regression)', () => {
        assert.equal(WIRE_SOURCE.includes('not exported from the pinned package entry'), false);
        assert.equal(PAGE_SOURCE.includes('exports none of it from the'), false);
        assert.match(WIRE_SOURCE, /dist\/index\.d\.ts:16\b/);
    });

    it('the copy-link hint tells the truth: every member holds the same link', () => {
        assert.match(PAGE_SOURCE, /Every member of this party holds the same link/);
        assert.equal(PAGE_SOURCE.includes('the host holds the link'), false);
    });

    it('every socket.send in leaveParty rides the readyState OPEN guard', () => {
        const fn = PAGE_SOURCE.slice(
            PAGE_SOURCE.indexOf('function leaveParty'),
            PAGE_SOURCE.indexOf('function disconnect'),
        );
        assert.ok(fn.length > 0, 'leaveParty body found');
        assert.equal((fn.match(/socket\.send/g) ?? []).length, 2);
        assert.equal((fn.match(/readyState === WebSocket\.OPEN/g) ?? []).length, 2);
    });
});

/* ================================================ house copy policy ======== */
/*
 * HUB-S3 (review hub HUB-7/60/61/62): the watch-party copy surface speaks the
 * member register declared in watchPartyWire.ts's HOUSE COPY POLICY block.
 * Every rule below is pinned so a later sentence cannot quietly regress it.
 */

/** Member-visible text of the page template: tags, attributes and mustache
 *  expressions removed (mustache string literals are checked separately). */
function templateText(source: string): string {
    const start = source.indexOf('<template>');
    const end = source.lastIndexOf('</template>');
    assert.ok(start >= 0 && end > start, 'page template found');
    return source
        .slice(start + '<template>'.length, end)
        .replace(/<!--[\s\S]*?-->/g, ' ')
        .replace(/\{\{[\s\S]*?\}\}/g, ' ')
        .replace(/<[^>]*>/g, ' ');
}

/** Sentence-shaped single-quoted literals in the page script + mustaches
 *  (a space inside = prose; paths and identifiers carry none). */
function proseLiterals(source: string): string[] {
    const code = source
        .replace(/<style[\s\S]*?<\/style>/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');
    return [...code.matchAll(/'([^'\n]*\s[^'\n]*)'/g)].map((m) => m[1]);
}

const CURATED = [...Object.values(WIRE_ERROR_SENTENCES), ...Object.values(MINT_FAILURE_SENTENCES)];

describe('house copy policy (HUB-S3)', () => {
    it('terms law: members never read the wire *group* or the internal *room*', () => {
        const banned = /\b(group|groups|room|rooms)\b/i;
        for (const sentence of CURATED) {
            assert.equal(banned.test(sentence), false, `curated sentence leaks wire/internal noun: ${sentence}`);
        }
        assert.equal(banned.test(templateText(PAGE_SOURCE)), false, 'template text leaks group/room');
        for (const literal of proseLiterals(PAGE_SOURCE)) {
            assert.equal(banned.test(literal), false, `page sentence leaks group/room: ${literal}`);
        }
    });

    it('register: no wire vocabulary or ports in curated sentences', () => {
        for (const sentence of CURATED) {
            assert.equal(/\b(frame|catalog|protocol operation|mint(ed)?)\b|:?8804/i.test(sentence), false, sentence);
        }
    });

    it('punctuation: typographic apostrophes, full stops, no shouting', () => {
        for (const sentence of CURATED) {
            assert.equal(sentence.includes("'"), false, `straight apostrophe: ${sentence}`);
            assert.match(sentence, /\.$/, `curated sentence ends with a full stop: ${sentence}`);
            assert.equal(/\b[A-Z]{2,}\b/.test(sentence), false, `ALL-CAPS emphasis: ${sentence}`);
        }
        const text = templateText(PAGE_SOURCE);
        assert.equal(text.includes("'"), false, 'template prose uses the typographic apostrophe');
        assert.equal(/\b(?!NAT\b)[A-Z]{2,}\b/.test(text), false, 'template prose shouts');
        assert.equal(text.includes('...'), false, 'ellipsis is the single character');
        for (const literal of proseLiterals(PAGE_SOURCE)) {
            assert.equal(/\b[A-Z]{2,}\b/.test(literal), false, `page sentence shouts: ${literal}`);
        }
    });

    it('caps quoted in copy equal the worker constants', () => {
        const php = REPO('src/SyncPlay/SyncPlayRelayWorker.php');
        assert.equal(MAX_PARTY_MEMBERS, Number(php.match(/MAX_ROOM_MEMBERS\s*=\s*(\d+)/)?.[1]));
        assert.equal(MAX_PARTY_QUEUE, Number(php.match(/CANONICAL_MAX_QUEUE_SIZE\s*=\s*(\d+)/)?.[1]));
        assert.match(partyErrorSentence('syncplay.group_full', 'Group is full'), new RegExp(`${MAX_PARTY_MEMBERS} members`));
        assert.match(partyErrorSentence('syncplay.queue_limit_exceeded', ''), /1,000 items/);
    });

    it('every refusal the worker can send a party member is curated (group_full, HANDLER_ERROR were raw)', () => {
        const php = REPO('src/SyncPlay/SyncPlayRelayWorker.php');
        assert.ok(php.includes("'syncplay.group_full'") && php.includes("'HANDLER_ERROR'"));
        for (const code of ['syncplay.group_full', 'HANDLER_ERROR']) {
            assert.equal(partyErrorSentence(code, 'raw').includes(code), false, `${code} curated`);
        }
    });

    it('group_not_found states what the worker means (blank name), not "no party has that name"', () => {
        const php = REPO('src/SyncPlay/SyncPlayRelayWorker.php');
        // The worker's only emitter: a join with a missing/blank group_id.
        assert.match(php, /!is_string\(\$roomField\) \|\| \$roomField === ''\) \{\s*\$this->sendCanonicalError\(\$client, 'syncplay\.group_not_found'/);
        const sentence = partyErrorSentence('syncplay.group_not_found', '');
        assert.match(sentence, /did not receive a party name/);
        assert.equal(/open with that name/.test(sentence), false);
    });

    it('INVALID_NEW_HOST matches its emitter ("Missing new host member ID")', () => {
        const php = REPO('src/SyncPlay/SyncPlayRelayWorker.php');
        assert.ok(php.includes("'INVALID_NEW_HOST', 'Missing new host member ID'"));
        assert.match(partyErrorSentence('INVALID_NEW_HOST', ''), /No member was chosen/);
    });

    it('unknown-code fallback keeps code + hub words and closes the sentence', () => {
        assert.equal(partyErrorSentence('weird', 'Raw words'), 'The hub refused that request (weird): Raw words.');
        assert.equal(partyErrorSentence('weird', 'Done.'), 'The hub refused that request (weird): Done.');
        assert.equal(partyErrorSentence('weird', '  '), 'The hub refused that request (weird): the hub gave no reason.');
    });

    it('activity feed re-voices the worker prose; the leave ack is not logged twice', () => {
        const php = REPO('src/SyncPlay/SyncPlayRelayWorker.php');
        assert.ok(php.includes("$displayName . ' joined the group'"), 'join prose emitter');
        assert.ok(php.includes("$memberName . ' left the group'"), 'leave-ack prose emitter');
        assert.equal(activitySentence('Bo joined the group'), 'Bo joined the watch party.');
        assert.equal(activitySentence('Ana left the group'), null);
        assert.equal(activitySentence('Something else'), 'Something else.');
        assert.equal(activitySentence('   '), null);
        assert.match(PAGE_SOURCE, /activitySentence\(frame\.message\)/);
        assert.match(PAGE_SOURCE, /noteActivity\('You left the watch party\.'\)/);
    });

    it('close sentences key on the worker close codes, and no longer blame token expiry', () => {
        const php = REPO('src/SyncPlay/SyncPlayRelayWorker.php');
        assert.equal(RELAY_CLOSE_UNAUTHORIZED, Number(php.match(/CLOSE_UNAUTHORIZED\s*=\s*(\d+)/)?.[1]));
        assert.equal(RELAY_CLOSE_DO_NOT_RETRY, Number(php.match(/CLOSE_DO_NOT_RETRY\s*=\s*(\d+)/)?.[1]));
        assert.equal(RELAY_CLOSE_TRY_AGAIN_LATER, Number(php.match(/CLOSE_TRY_AGAIN_LATER\s*=\s*(\d+)/)?.[1]));
        assert.match(relayCloseSentence(4401, true), /Sign in again/);
        assert.match(relayCloseSentence(1013, true), /Reconnect/);
        assert.match(relayCloseSentence(1011, false), /Pick the server again/);
        assert.match(relayCloseSentence(1006, true), /rejoin it by name/);
        assert.match(relayCloseSentence(1006, false), /before the watch party opened/);
        assert.match(PAGE_SOURCE, /relayCloseSentence\(event\.code, wasInParty\)/);
        assert.equal(/expire about an hour/.test(PAGE_SOURCE), false);
    });

    it('unreachable sentence names the endpoint really dialed; the README quote survives for :8804', () => {
        const readme = REPO('README.md');
        const quote = 'relay on port 8804 is unreachable';
        assert.ok(readme.includes(`"${quote}"`), 'README troubleshooting heading quotes the page');
        assert.ok(relayUnreachableSentence('ws://hub.lan:8804/syncplay/s').includes(quote));
        const seam = relayUnreachableSentence('wss://relay.example.com:9443/syncplay/s');
        assert.ok(seam.includes('relay.example.com:9443'));
        assert.equal(seam.includes('8804'), false, 'a seam dial never blames port 8804');
        assert.match(PAGE_SOURCE, /relayUnreachableSentence\(relayUrl\)/);
    });

    it('time register is shared with shareLiveness (min / h / days)', () => {
        const now = 1_800_000_000;
        for (const age of [300, 7200, 86_400 * 3]) {
            assert.equal(formatLastSeen(now - age, now * 1000), `last seen: ${formatAge(now - age, now)}`);
        }
    });

    it('a never-seen server reads as a sentence, not "last heard from it unknown"', () => {
        assert.equal(lastHeardClause(null, 1000), 'the hub has no record of hearing from it');
        assert.equal(lastHeardClause(880, 1000), 'the hub last heard from it 2 min ago');
        assert.match(PAGE_SOURCE, /lastHeardClause\(selectedServer\.lastSeenAt, now\(\)\)/);
    });

    it('playback reads as words, not the raw wire state with "@"', () => {
        assert.equal(playbackLine('playing', 61_500), 'Playing at 1:01');
        assert.equal(playbackLine('paused', 0), 'Paused at 0:00');
        assert.equal(playbackLine('stopped', 9_000), 'Stopped');
        assert.equal(playbackLine('buffering', 1_000), 'Buffering at 0:01');
        assert.equal(PAGE_SOURCE.includes(' @ '), false);
    });

    it('the page never shows a member their internal hub id', () => {
        assert.equal(PAGE_SOURCE.includes('You are member'), false);
        assert.equal(PAGE_SOURCE.includes('(id pending)'), false);
    });

    it('instructions live in visible hints, not in vanishing placeholders', () => {
        assert.equal(PAGE_SOURCE.includes('placeholder="Party name (this is also the join code)"'), false);
        assert.match(PAGE_SOURCE, /A name nobody is using starts a new, empty\s+watch party with you as host instead\./);
    });

    it('the clipboard-failure hint does not claim a selection the code never made', () => {
        assert.equal(PAGE_SOURCE.includes('the field above is selected'), false);
    });
});
