/**
 * HOUSE REGISTER RULE — HUB-S5 (review hub HUB-22). This file is the single
 * declaration of the rule; the sources it pins point back here.
 *
 *  - UI copy (hub-local SPA chrome: templates, page-level sentences, banner
 *    copy) speaks the contracted register — can’t, couldn’t, doesn’t,
 *    hasn’t, isn’t, it’s, there’s — and every apostrophe is the typographic
 *    U+2019, never the straight ASCII one. This is the @phlix/ui shell’s
 *    own register, so a hub-local page reads like the shell around it.
 *  - API prose (the `message`/`error` sentences a hub controller puts on the
 *    wire) carries NO contractions. JSON prose cannot promise typography to
 *    every client, so it never needs an apostrophe in the first place.
 *
 * Scope of the pins (the HUB-S5 sweep): HubSharedWithMePage.vue,
 * HubSecuritySettingsPage.vue, ShareLibraryCard.vue, maintenanceNotice.ts
 * (UI leg) and ServerProxyController.php (API leg). Sentence tables owned by
 * other modules (shareLiveness, passwordForm, shareCreate, watchPartyWire)
 * keep their own test-pinned copy and are outside these pins.
 *
 * Mutation teeth: re-expanding any swept negation ("could not", "cannot",
 * "does not", …) in a pinned UI source, a straight apostrophe in UI prose,
 * or a contraction back in the relay_unavailable wire message each reddens a
 * named assertion below.
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

import { maintenanceNoticeCopy } from '../src/utils/maintenanceNotice.ts';

const READ = (rel: string): string =>
    readFileSync(fileURLToPath(new URL(rel, import.meta.url)), 'utf8');

const UI_SOURCES: Record<string, string> = {
    'HubSharedWithMePage.vue': READ('../src/pages/HubSharedWithMePage.vue'),
    'HubSecuritySettingsPage.vue': READ('../src/pages/HubSecuritySettingsPage.vue'),
    'ShareLibraryCard.vue': READ('../src/components/ShareLibraryCard.vue'),
};
const CONTROLLER_SOURCE = READ('../../src/Http/Controllers/ServerProxyController.php');

/** Uncontracted negations the UI register writes contracted. */
const EXPANDED_NEGATION =
    /\b(cannot|could not|does not|do not|did not|has not|have not|is not|are not|was not|were not|will not|would not|should not)\b/i;

/** Any English contraction, straight or typographic apostrophe. */
const CONTRACTION = /\b\w+n['’]t\b|\b(it|that|there|here|what|who|you|we|they|i)['’](s|re|ll|ve|d|m)\b/i;

/** Member-visible prose of a source: comments and <style> removed, so
 *  docblocks may keep their own (developer) register. */
function uiProse(source: string): string {
    return source
        .replace(/<style[\s\S]*?<\/style>/g, ' ')
        .replace(/<!--[\s\S]*?-->/g, ' ')
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/^\s*\/\/.*$/gm, ' ');
}

/** Template text only: tags, attributes and mustaches removed. */
function templateText(source: string): string {
    const start = source.indexOf('<template>');
    const end = source.lastIndexOf('</template>');
    assert.ok(start >= 0 && end > start, 'template found');
    return source
        .slice(start + '<template>'.length, end)
        .replace(/<!--[\s\S]*?-->/g, ' ')
        .replace(/\{\{[\s\S]*?\}\}/g, ' ')
        .replace(/<[^>]*>/g, ' ');
}

describe('house register rule — UI leg (HUB-S5)', () => {
    for (const [name, source] of Object.entries(UI_SOURCES)) {
        it(`${name}: negations are contracted in member-visible prose`, () => {
            const prose = uiProse(source);
            const hit = prose.match(EXPANDED_NEGATION);
            assert.equal(hit, null, `${name} speaks the expanded register: "${hit?.[0]}"`);
        });

        it(`${name}: no straight apostrophe in template prose or escaped in literals`, () => {
            assert.equal(/[A-Za-z]'[A-Za-z]/.test(templateText(source)), false, `${name} template has a straight apostrophe`);
            assert.equal(/\\'/.test(uiProse(source)), false, `${name} escapes a straight apostrophe into a literal`);
        });
    }

    it('maintenance banner copy (both audiences) follows the UI register', () => {
        for (const who of ['admin', 'member'] as const) {
            const copy = maintenanceNoticeCopy(who);
            const sentence = `${copy.lead} ${copy.text}${copy.link?.label ?? ''}`;
            assert.equal(EXPANDED_NEGATION.test(sentence), false, `${who}: expanded negation in "${sentence}"`);
            assert.equal(/\bit is\b/i.test(sentence), false, `${who}: "it is" reads "it’s" in UI copy`);
            assert.equal(sentence.includes("'"), false, `${who}: straight apostrophe in "${sentence}"`);
        }
    });
});

describe('house register rule — API leg (HUB-S5)', () => {
    it('ServerProxyController wire prose carries no contractions', () => {
        // Strip comments, then read every single-quoted PHP literal (with \' escapes).
        const code = CONTROLLER_SOURCE
            .replace(/\/\*[\s\S]*?\*\//g, ' ')
            .replace(/^\s*\/\/.*$/gm, ' ');
        const literals = [...code.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1].replace(/\\'/g, "'"));
        assert.ok(literals.length > 0, 'controller literals found');
        for (const literal of literals) {
            assert.equal(CONTRACTION.test(literal), false, `contraction on the wire: ${literal}`);
        }
    });

    it('the relay_unavailable message is pinned in the no-contraction register', () => {
        assert.ok(
            CONTROLLER_SOURCE.includes(
                "'This server is online but its secure relay tunnel is not connected. '\n"
                + "                        . 'Browsing over the hub is not available until the tunnel reconnects.'",
            ),
            'relay_unavailable message drifted',
        );
    });
});
