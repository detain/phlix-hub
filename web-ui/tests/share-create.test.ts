/**
 * Share-create wire law — M-HUB-05 [review hub__casualgamer m2].
 *
 * Same posture as watch-party.test.ts: a TRUTH TABLE on the pure module
 * `src/utils/shareCreate.ts` (parse boundary, exact payload keys, refusal
 * sentences), plus CROSS-LANGUAGE pins reading the PHP as text — every code
 * the card curates must be a code `LibraryShareController::createShare` can
 * actually answer with, the collaborator-limit sentence must equal
 * `QuotaFrames::COLLABORATORS_TEXT` verbatim, the permission values must equal
 * the `LibraryShare` constants, and the three endpoints the card calls must be
 * registered in `src/Application.php`. A new server code, a renamed constant
 * or a moved route reddens a NAMED assertion here instead of shipping a dead
 * form in the portal.
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
    parseServers,
    parseLibraries,
    buildSharePayload,
    shareRefusalSentence,
    refusalCodeOf,
    SHARE_REFUSAL_SENTENCES,
} from '../src/utils/shareCreate.ts';

const REPO = (rel: string): string =>
    readFileSync(fileURLToPath(new URL(`../../${rel}`, import.meta.url)), 'utf8');

const CARD_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/components/ShareLibraryCard.vue', import.meta.url)),
    'utf8',
);
const PAGE_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/pages/HubManageSharesPage.vue', import.meta.url)),
    'utf8',
);
const UTIL_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/utils/shareCreate.ts', import.meta.url)),
    'utf8',
);
const CONTROLLER_SOURCE = REPO('src/Http/Controllers/LibraryShareController.php');
const QUOTA_SOURCE = REPO('src/Http/QuotaFrames.php');
const MODEL_SOURCE = REPO('src/Hub/LibraryShare.php');
const ROUTER_SOURCE = REPO('src/Application.php');

/** The single createShare method body, textually isolated for the code sweep. */
function createShareBody(): string {
    const start = CONTROLLER_SOURCE.indexOf('function createShare');
    assert.notEqual(start, -1, 'createShare must exist on the controller');
    const rest = CONTROLLER_SOURCE.slice(start);
    // Method end: the next line-initial `}` at the class indentation level.
    const end = rest.search(/\n {4}\}/);
    assert.ok(end > 0, 'createShare body must terminate');
    return rest.slice(0, end);
}

/** Every machine code `createShare` can answer with (memberError arms). */
function serverAnswerableCodes(): string[] {
    const body = createShareBody();
    const codes: string[] = [];
    const re = /memberError\(\s*\d+,\s*'([a-z_.]+)'/g;
    for (let m = re.exec(body); m !== null; m = re.exec(body)) {
        codes.push(m[1]);
    }
    return codes;
}

describe('parse boundary', () => {
    it('keeps only rows with a usable id and coerces display fields', () => {
        const servers = parseServers({
            servers: [
                { serverId: ' s1 ', serverName: 'Home', status: 'online' },
                { serverId: '', serverName: 'ghost' },
                'junk',
                null,
                { serverName: 'nameless' },
                { serverId: 's2', serverName: 42, status: null },
            ],
        });
        assert.deepEqual(servers, [
            { serverId: ' s1 ', serverName: 'Home', status: 'online' },
            { serverId: 's2', serverName: 's2', status: '' },
        ]);
    });
    it('reads the libraries envelope and drops junk rows', () => {
        assert.deepEqual(
            parseLibraries({ libraries: [{ id: 'l1', name: 'Movies' }, 7, { id: '' }, { id: 'l2' }] }),
            [{ id: 'l1', name: 'Movies' }, { id: 'l2', name: 'l2' }],
        );
    });
    it('returns nothing from a garbage envelope (never throws at the boundary)', () => {
        assert.deepEqual(parseServers(undefined), []);
        assert.deepEqual(parseServers('nope'), []);
        assert.deepEqual(parseLibraries({ libraries: 'nope' }), []);
    });
});

describe('outbound payload', () => {
    it('carries exactly the five keys createShare reads, email trimmed', () => {
        assert.deepEqual(
            buildSharePayload('srv-1', { id: 'lib-9', name: 'TV Shows' }, '  a@b.c  ', 'readwrite'),
            {
                collaborator_email: 'a@b.c',
                server_id: 'srv-1',
                library_id: 'lib-9',
                library_name: 'TV Shows',
                permission: 'readwrite',
            },
        );
    });
});

describe('refusal sentences', () => {
    it('curate known codes and fall through to the wire sentence otherwise', () => {
        assert.equal(shareRefusalSentence('share_exists', 'ignored'),
            SHARE_REFUSAL_SENTENCES['share_exists']);
        assert.equal(shareRefusalSentence('something.new', 'the hub said this'), 'the hub said this');
        assert.equal(shareRefusalSentence(null, 'fallback wins'), 'fallback wins');
    });
    it('extracts the code out of an ApiError-shaped rejection, junk-safe', () => {
        assert.equal(refusalCodeOf({ body: { code: 'user_not_found' } }), 'user_not_found');
        assert.equal(refusalCodeOf({ body: {} }), null);
        assert.equal(refusalCodeOf('plain string'), null);
        assert.equal(refusalCodeOf({ body: 'text' }), null);
    });
    it('names the invite-links detour for the unregistered-email refusal', () => {
        // The review's core symptom: an empty state you must guess around.
        // The user_not_found sentence must SEND the user to the right door.
        assert.match(SHARE_REFUSAL_SENTENCES['user_not_found'], /invite link/i);
    });
});

describe('cross-language tripwires (PHP is the authority)', () => {
    const answerable = serverAnswerableCodes();

    it('every curated code is a code createShare can answer with', () => {
        for (const code of Object.keys(SHARE_REFUSAL_SENTENCES)) {
            assert.ok(
                answerable.includes(code),
                `curated refusal code '${code}' is not in createShare's memberError set: ${answerable.join(', ')}`,
            );
        }
    });

    it('the collaborator-limit line equals QuotaFrames::COLLABORATORS_TEXT verbatim', () => {
        const m = QUOTA_SOURCE.match(/COLLABORATORS_TEXT = '([^']*)'/);
        assert.ok(m, 'QuotaFrames::COLLABORATORS_TEXT must exist to pin against');
        assert.equal(SHARE_REFUSAL_SENTENCES['quota.exceeded'], m[1]);
    });

    it('the form permission values equal the LibraryShare constants', () => {
        assert.match(MODEL_SOURCE, /const PERMISSION_READ = 'read';/);
        assert.match(MODEL_SOURCE, /const PERMISSION_READWRITE = 'readwrite';/);
        assert.match(CARD_SOURCE, /<option value="read">/);
        assert.match(CARD_SOURCE, /<option value="readwrite">/);
    });

    it('the three endpoints the card calls are registered hub routes', () => {
        assert.ok(ROUTER_SOURCE.includes("$r->get('/me/servers'"), '/me/servers route moved');
        assert.ok(ROUTER_SOURCE.includes("'/me/libraries'"), '/me/libraries route moved');
        assert.ok(ROUTER_SOURCE.includes("group('/api/v1/me/shares'"), '/api/v1/me/shares group moved');
        for (const endpoint of ['/api/v1/me/servers', '/api/v1/me/libraries', '/api/v1/me/shares']) {
            assert.ok(
                UTIL_SOURCE.includes(endpoint) || CARD_SOURCE.includes(endpoint),
                `the client must actually call ${endpoint}`,
            );
        }
    });
});

describe('page binding', () => {
    it('the Shares page mounts the card (an unmounted door is the old defect)', () => {
        assert.match(PAGE_SOURCE, /import ShareLibraryCard from '\.\.\/components\/ShareLibraryCard\.vue';/);
        assert.match(PAGE_SOURCE, /<ShareLibraryCard \/>/);
    });
    it('every failure and success renders a named, announced state', () => {
        assert.match(CARD_SOURCE, /role="alert"/);
        assert.match(CARD_SOURCE, /role="status"/);
        assert.match(CARD_SOURCE, /Invite links? create|invite link/);
    });
    it('never puts a token or secret in the payload (email is the identifier)', () => {
        assert.doesNotMatch(CARD_SOURCE, /password|token/);
    });
});
