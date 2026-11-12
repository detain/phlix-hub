/**
 * HUB-S9 — federation page honesty disclosures (hub-local half; review
 * HUB-87, HUB-37).
 *
 *  1. Admin-delegation disclosure. The hint names admin delegation in its
 *     scope list, but the wrapped @phlix/ui FederationPage has no delegation
 *     control: the feature lives only on admin-gated API routes. The hint's
 *     API-only sentence names every one of those routes. Tripwires: a
 *     delegation route registered in src/Application.php that the hint does
 *     not name, or a vendored FederationPage that grows a delegation control
 *     (the "no control" sentence would then lie), each redden here.
 *  2. servers→hubs band-aid. The vendored page still says "servers" where
 *     the hub means peer hubs. The correction is a STANDING band
 *     (HubPageHint :dismissible="false") directly above the wrapped page, so
 *     dismissing the main hint can't leave the wrong noun as the only copy.
 *     Bidirectional pin against the vendored copy: while the legacy wording
 *     ships, the band must exist and quote it exactly; once the pin carries
 *     the corrected noun (upstream item, re-vendor rides HUB-S52) this file
 *     reddens until the band is retired.
 *  3. HubPageHint's `dismissible` contract (default true; false = no
 *     dismiss button, no storage read/write, role="note").
 *  4. House register rule (tests/copy-register.test.ts) on the page prose.
 *
 * Pure file reads — no DOM; runs under `node --test` (`npm test`).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const PATH = (rel: string): string => fileURLToPath(new URL(rel, import.meta.url));
const READ = (rel: string): string => readFileSync(PATH(rel), 'utf8');

const PAGE = READ('../src/pages/HubFederationPage.vue');
const HINT = READ('../src/components/HubPageHint.vue');
const APPLICATION = READ('../../src/Application.php');
const VENDORED_PAGE_REL = '../node_modules/@phlix/ui/src/pages/FederationPage.vue';

const TEMPLATE = PAGE.slice(PAGE.indexOf('<template>'));

/** Collapse whitespace so assertions survive template re-wrapping. */
const flat = (s: string): string => s.replace(/\s+/g, ' ');
/** Visible prose of a template fragment (tags dropped). */
const prose = (s: string): string => flat(s.replace(/<[^>]+>/g, ''));

/** Each <HubPageHint ...>…</HubPageHint> element: opening-tag attrs + body. */
function hubHints(): Array<{ attrs: string; body: string; start: number; end: number }> {
    const out: Array<{ attrs: string; body: string; start: number; end: number }> = [];
    const re = /<HubPageHint\b([\s\S]*?)>([\s\S]*?)<\/HubPageHint>/g;
    for (let m = re.exec(TEMPLATE); m !== null; m = re.exec(TEMPLATE)) {
        out.push({ attrs: flat(m[1]), body: m[2], start: m.index, end: m.index + m[0].length });
    }
    return out;
}

const hints = hubHints();
const mainHint = hints.find((h) => /hint-id="federation"/.test(h.attrs));
const band = hints.find((h) => /hint-id="federation-terminology"/.test(h.attrs));

/** Admin-delegation routes registered under /api/v1/me/federation. */
function registeredDelegationRoutes(): Array<{ verb: string; path: string }> {
    const groupAt = APPLICATION.indexOf("group('/api/v1/me/federation'");
    assert.ok(groupAt >= 0, 'Application.php lost the /api/v1/me/federation group — re-aim this tripwire');
    const group = APPLICATION.slice(groupAt, APPLICATION.indexOf('}, [$authMiddleware', groupAt));
    const routes: Array<{ verb: string; path: string }> = [];
    const re = /\$r->(get|post|put|patch|delete)\(\s*'([^']*admin-delegation[^']*)'/g;
    for (let m = re.exec(group); m !== null; m = re.exec(group)) {
        routes.push({
            verb: m[1].toUpperCase(),
            path: '/api/v1/me/federation' + m[2].replace(/\{(\w+)\}/g, ':$1'),
        });
    }
    return routes;
}

describe('HUB-S9 — admin-delegation disclosure (HUB-87/HUB-37 hub-local half)', () => {
    it('the main federation hint exists and still names admin delegation in its scope', () => {
        assert.ok(mainHint, 'HubFederationPage lost its hint-id="federation" HubPageHint');
        assert.match(prose(mainHint.body), /\(peers, shared libraries, admin delegation\)/);
    });

    it('every registered admin-delegation route is named, with its verb, in the API-only sentence', () => {
        const routes = registeredDelegationRoutes();
        // GET + POST /admin-delegations, DELETE /admin-delegations/{id},
        // PUT /peers/{id}/admin-delegation at the dispatch tip.
        assert.ok(routes.length >= 4, 'expected >= 4 admin-delegation routes, found ' + routes.length);
        const text = prose(mainHint!.body);
        for (const { verb, path } of routes) {
            // Route path must appear as a whole token (not as a prefix of a longer one).
            const pathRe = new RegExp(path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![\\w/-])');
            assert.match(text, pathRe, `hint omits ${verb} ${path}`);
            const near = text.slice(Math.max(0, text.search(pathRe) - 24), text.search(pathRe));
            assert.ok(near.includes(verb), `hint names ${path} but not its ${verb} verb (saw "…${near}")`);
        }
    });

    it('the sentence still discloses that the page exposes no control for any of them', () => {
        assert.match(prose(mainHint!.body), /this page exposes no control that invokes any of them/);
        assert.match(prose(mainHint!.body), /master hub only/);
    });

    it('the vendored FederationPage really has no delegation control (else the disclosure lies)', () => {
        assert.ok(existsSync(PATH(VENDORED_PAGE_REL)), 'vendored @phlix/ui FederationPage.vue missing — run npm ci');
        const vendored = READ(VENDORED_PAGE_REL);
        assert.equal(
            /deleg/i.test(vendored),
            false,
            'the vendored FederationPage now mentions delegation — re-verify and rewrite the hint’s API-only sentence',
        );
    });
});

describe('HUB-S9 — servers→hubs standing band (band-aid until the pin carries the corrected noun)', () => {
    const vendored = existsSync(PATH(VENDORED_PAGE_REL)) ? READ(VENDORED_PAGE_REL) : '';
    const legacyShips = /Connect with other Phlix servers/.test(vendored) || /whole servers together/.test(vendored);

    it('band presence tracks the vendored legacy wording (retire it when the pin is corrected)', () => {
        assert.ok(vendored !== '', 'vendored @phlix/ui FederationPage.vue missing — run npm ci');
        if (legacyShips) {
            assert.ok(band, 'the vendored page still says "servers" but the standing terminology band is gone');
        } else {
            assert.equal(
                band,
                undefined,
                'the vendored @phlix/ui pin no longer says "servers" — retire the HUB-S9 terminology band (and this leg)',
            );
        }
    });

    it('the band is non-dismissible and sits directly above the wrapped page, after the main hint', () => {
        if (!band) return; // retired leg (see above)
        assert.match(band.attrs, /:dismissible="false"/);
        assert.ok(mainHint && band.start > mainHint.end, 'band must follow the dismissable hint');
        assert.match(TEMPLATE.slice(band.end), /^\s*<FederationPage \/>/, 'band must sit directly above <FederationPage />');
        assert.match(prose(band.body), /a peer is another Phlix hub, never a Phlix server/);
    });

    it('every phrase the band quotes from the wrapped page exists verbatim in the vendored copy', () => {
        if (!band) return;
        const quoted = [...prose(band.body).matchAll(/“([^”]+)”/g)].map((m) => m[1]);
        const fromPage = quoted.filter((q) => q.includes(' ')); // multi-word quotes are page citations
        assert.ok(fromPage.length >= 2, 'band should cite the legacy wording it corrects');
        for (const q of fromPage) {
            assert.ok(flat(vendored).includes(q), `band quotes “${q}”, which the vendored page doesn’t say`);
        }
    });

    it('the dismissable hint no longer carries the terminology correction (one home, the standing band)', () => {
        assert.equal(/Terminology note/.test(mainHint!.body), false);
        assert.equal(/servers/.test(prose(mainHint!.body)), false);
    });
});

describe('HUB-S9 — HubPageHint dismissible contract', () => {
    it('declares dismissible as an optional prop defaulting to true', () => {
        assert.match(HINT, /dismissible\?: boolean;/);
        assert.match(HINT, /\{ dismissible: true \}/);
    });

    it('a standing hint never reads or writes the dismissal key', () => {
        const effect = HINT.slice(HINT.indexOf('watchEffect('), HINT.indexOf('function hide'));
        assert.ok(
            effect.indexOf('if (!props.dismissible)') >= 0
                && effect.indexOf('if (!props.dismissible)') < effect.indexOf('localStorage.getItem'),
            'watchEffect must short-circuit before reading localStorage for a standing hint',
        );
        const hide = HINT.slice(HINT.indexOf('function hide'), HINT.indexOf('</script>'));
        assert.ok(
            hide.indexOf('if (!props.dismissible)') >= 0
                && hide.indexOf('if (!props.dismissible)') < hide.indexOf('localStorage.setItem'),
            'hide() must refuse before writing localStorage for a standing hint',
        );
    });

    it('renders the dismiss button only when dismissible, and a note role when standing', () => {
        const tpl = flat(HINT.slice(HINT.indexOf('<template>'), HINT.indexOf('</template>')));
        assert.match(tpl, /<button v-if="dismissible" type="button" class="hub-page-hint__dismiss"/);
        assert.match(tpl, /:role="dismissible \? undefined : 'note'"/);
    });
});

describe('HUB-S9 — house register rule on the federation page prose', () => {
    const pageProse = prose(TEMPLATE.replace(/<!--[\s\S]*?-->/g, ' '));
    it('negations are contracted (tests/copy-register.test.ts)', () => {
        const hit = pageProse.match(
            /\b(cannot|could not|does not|do not|did not|has not|have not|is not|are not|was not|were not|will not|would not|should not)\b/i,
        );
        assert.equal(hit, null, `federation page prose speaks the expanded register: "${hit?.[0]}"`);
    });
    it('no straight apostrophe or straight double quote in member-visible prose', () => {
        assert.equal(/[A-Za-z]'[A-Za-z]/.test(pageProse), false, 'straight apostrophe in prose');
        assert.equal(/"/.test(pageProse), false, 'straight double quote in prose');
    });
});
