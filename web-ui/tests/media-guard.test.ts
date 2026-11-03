/**
 * [review hub Major 39] media-surface server-selection guard law.
 *
 * Two halves, house-style: a truth table over the pure predicate
 * (`src/utils/mediaGuard.ts`, the single source of the path law) and a
 * wiring-truth pass over `src/main.ts` (the guard must actually read the
 * predicate, the live store flag, and land on the server directory — a
 * predicate nobody mounts is theater).
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

import {
    MEDIA_PATH_PREFIXES,
    needsServerSelection,
    SERVER_DIRECTORY_PATH,
} from '../src/utils/mediaGuard.ts';

const MAIN_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/main.ts', import.meta.url)),
    'utf8',
);

describe('media guard path law', () => {
    it('pins the five guarded feature roots', () => {
        assert.deepEqual(
            [...MEDIA_PATH_PREFIXES].sort(),
            ['/app/audiobooks', '/app/books', '/app/music', '/app/photo', '/app/search'],
        );
    });

    it('guards every feature root', () => {
        for (const root of MEDIA_PATH_PREFIXES) {
            assert.ok(needsServerSelection(root), `${root} must be guarded`);
        }
    });

    it('guards deep children', () => {
        for (const path of [
            '/app/search/anything',
            '/app/music/album/Dark%20Side',
            '/app/books/42/read',
            '/app/audiobooks/7/listen',
            '/app/photo/slideshow',
        ]) {
            assert.ok(needsServerSelection(path), `${path} must be guarded`);
        }
    });

    it('never guards siblings glued to a prefix (boundary law)', () => {
        for (const path of [
            '/app/photomatic',
            '/app/searchhistory',
            '/app/musicgen',
            '/app/servers',
            '/app/servers/42',
            '/app/watch-party',
            '/app/party/srv-1/ABCD',
            '/app/admin/dashboard',
        ]) {
            assert.ok(!needsServerSelection(path), `${path} must NOT be guarded`);
        }
    });

    it('lands on the servers directory', () => {
        assert.equal(SERVER_DIRECTORY_PATH, '/app/servers');
    });
});

describe('main.ts wiring truth', () => {
    it('mounts the guard over the live store flag', () => {
        assert.match(MAIN_SOURCE, /needsServerSelection\(to\.path\)/, 'guard reads the predicate');
        assert.match(MAIN_SOURCE, /useServerStore\(\)\.hasCurrent/, 'guard reads the live selection');
        assert.match(MAIN_SOURCE, /path: SERVER_DIRECTORY_PATH/, 'redirect lands on the pinned constant');
        assert.match(MAIN_SOURCE, /router\.beforeEach\(/, 'the guard is actually registered');
    });

    it('keeps ONE source for the path law', () => {
        // The prefix list may live only in mediaGuard.ts; a second copy in
        // main.ts is the drift this module exists to prevent.
        assert.ok(!/MEDIA_PATH_PREFIXES = /.test(MAIN_SOURCE), 'main.ts must not redeclare the law');
        assert.match(MAIN_SOURCE, /from '\.\/utils\/mediaGuard/, 'main.ts imports the module');
    });

    it('fails loud when the shell contract broke', () => {
        assert.match(MAIN_SOURCE, /did not install \$router/, 'missing router is a hard error, not silent dead-ends');
    });
});
