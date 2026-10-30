/**
 * @phlix/ui pin-coordinate currency tripwire (M-HUB-12).
 *
 * Two source docblocks state the CURRENT `@phlix/ui` pin as a version
 * coordinate (the i18n seam's "SHIPS the locale bundles" claim and
 * HubPageHint.vue's package-boundary posture). Prose coordinates rot on every
 * re-pin cascade — this file makes that loud: every version-coordinate line in
 * each named docblock file must equal the tag pinned in `package.json`, so the
 * next cascade that bumps the pin without refreshing the prose reddens
 * `npm test` here.
 *
 * Pure file reads — no imports of app code, no DOM; runs under `node --test`
 * like the rest of this suite.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const here = fileURLToPath(new URL('.', import.meta.url));

/** The tag the manifest pins (tag-form GitHub archive URL). */
function pinnedUiTag(): string {
    const manifest = JSON.parse(readFileSync(here + '../package.json', 'utf8'));
    const spec = manifest.dependencies['@phlix/ui'];
    const match = typeof spec === 'string'
        ? spec.match(/^https:\/\/github\.com\/detain\/phlix-ui\/archive\/refs\/tags\/(v[\d.]+)\.tar\.gz$/)
        : null;
    assert.ok(
        match,
        '@phlix/ui must be pinned as a tag-form GitHub archive URL, got: ' + JSON.stringify(spec),
    );
    return match[1];
}

describe('ui pin-coordinate currency (M-HUB-12)', () => {
    const tag = pinnedUiTag();

    const sites = [
        { file: '../src/i18n/index.ts', anchor: 'release tag' },
        { file: '../src/components/HubPageHint.vue', anchor: 'vendored' },
    ];

    for (const site of sites) {
        it(`${site.file} states the live @phlix/ui pin (${tag}) at every coordinate line`, () => {
            const source = readFileSync(here + site.file, 'utf8');
            assert.ok(
                source.includes(site.anchor),
                site.file + " lost the '" + site.anchor + "' sentence the coordinate anchors — this docblock was rewritten; re-verify the tripwire still names the right sites",
            );
            const coordinateLines = source
                .split('\n')
                .map((line, i) => ({ line: line, i: i }))
                .filter(({ line }) => /v\d+\.\d+\.\d+/.test(line));
            assert.ok(
                coordinateLines.length > 0,
                site.file + ' lost its pin-coordinate prose entirely — refresh or remove the claim',
            );
            for (const { line, i } of coordinateLines) {
                assert.ok(
                    line.includes(tag),
                    site.file + ':' + (i + 1) + ' cites a stale @phlix/ui coordinate (package.json pins ' + tag + '): ' + line.trim(),
                );
            }
        });
    }
});
