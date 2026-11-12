/**
 * [review hub Minor 50] the hub-local chrome catalog: the top-bar menu labels
 * and page quick-link labels must come from src/i18n/hub-messages.ts in EVERY
 * supported locale — no hardcoded English string may survive in the wiring
 * files, and the English catalog must render byte-identical to the strings the
 * hub shipped for years (additive localization, the estate S253 dist law).
 *
 * Two families:
 *  - runtime: catalog parity across all 7 locales (key space defined by EN),
 *    EN byte-pins, locale switching through setHubLocale/useHubMessages, the
 *    late-bound active-catalog law, and fail-loud lookup errors;
 *  - structural (readFileSync): main.ts and the three hub pages speak ONLY
 *    t('menu.*')/t('links.*') for labels — a raw `label: '` literal in those
 *    files is exactly the regression this suite kills. ui renders menu labels
 *    VERBATIM (never through its own t()), so the hub-local catalog is the
 *    only seam; proven in src/i18n/hub-messages.ts's header.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { SUPPORTED_LOCALES } from '../src/i18n/index.ts';
import { HUB_MESSAGES, hubLocale, setHubLocale, useHubMessages } from '../src/i18n/hub-messages.ts';
import type { HubMessageKey } from '../src/i18n/hub-messages.ts';

const HERE = dirname(fileURLToPath(import.meta.url));
const src = (rel: string): string => readFileSync(join(HERE, '..', 'src', rel), 'utf8');

/** The full key space, EN-shaped. */
const KEYS: HubMessageKey[] = [
    'menu.myServers', 'menu.search', 'menu.federation', 'menu.federationShares', 'menu.shares',
    'menu.sharedWithMe', 'menu.watchParty', 'menu.inviteLinks', 'menu.requests', 'menu.security',
    'menu.admin',
    'links.incomingShares', 'links.hubSettings', 'links.inviteLinks', 'links.myServers',
    'links.sharedWithMe',
];

describe('hub-local chrome catalog (Minor 50)', () => {
    it('every locale carries exactly the EN key space', () => {
        const enShape = {
            menu: Object.keys(HUB_MESSAGES.en.menu).sort(),
            links: Object.keys(HUB_MESSAGES.en.links).sort(),
        };
        assert.deepEqual(enShape.menu, [
            'admin', 'federation', 'federationShares', 'inviteLinks', 'myServers', 'requests',
            'search', 'security', 'sharedWithMe', 'shares', 'watchParty',
        ], 'EN menu key set (11) rotated? main.ts consumes these exact keys');
        assert.deepEqual(enShape.links, [
            'hubSettings', 'incomingShares', 'inviteLinks', 'myServers', 'sharedWithMe',
        ], 'EN links key set (5) rotated? the hub pages consume these exact keys');

        for (const locale of SUPPORTED_LOCALES) {
            const catalog = HUB_MESSAGES[locale] as { menu: Record<string, string>; links: Record<string, string> };
            assert.deepEqual(Object.keys(catalog.menu).sort(), enShape.menu, `${locale} menu keys`);
            assert.deepEqual(Object.keys(catalog.links).sort(), enShape.links, `${locale} links keys`);
            for (const key of KEYS) {
                assert.equal(typeof setLocaleGet(locale, key), 'string', `${locale}:${key} present`);
                assert.ok(setLocaleGet(locale, key).length > 0, `${locale}:${key} non-empty`);
            }
        }
    });

    it('the EN catalog is byte-identical to the historical hardcoded strings', () => {
        setHubLocale('en');
        const { t } = useHubMessages();
        const HISTORIC: Record<string, string> = {
            'menu.myServers': 'My Servers',
            'menu.search': 'Search',
            'menu.federation': 'Federation',
            'menu.federationShares': 'Federation Shares',
            'menu.shares': 'Shares',
            'menu.sharedWithMe': 'Shared With Me',
            'menu.watchParty': 'Watch Party',
            'menu.inviteLinks': 'Invite Links',
            'menu.requests': 'Requests',
            'menu.security': 'Security',
            'menu.admin': 'Admin',
            'links.incomingShares': 'Incoming shares',
            'links.hubSettings': 'Hub settings',
            'links.inviteLinks': 'Invite links',
            'links.myServers': 'My servers',
            'links.sharedWithMe': 'Shared with me',
        };
        for (const [key, value] of Object.entries(HISTORIC)) {
            assert.equal(t(key), value, `EN byte-pin ${key}`);
        }
        // menu vs links deliberately differ in case ('Invite Links' vs 'Invite
        // links') — the historical truth, preserved, so this pin is meaningful.
    });

    it('setHubLocale switches rendering; the translator is late-bound', () => {
        const early = useHubMessages(); // captured before any explicit switch
        setHubLocale('en');
        assert.equal(early.t('menu.search'), 'Search', 'default active catalog is EN');
        setHubLocale('de');
        assert.equal(early.t('menu.search'), 'Suchen', 'late-bound: same closure sees the switch');
        setHubLocale('ja');
        assert.equal(early.t('menu.watchParty'), 'パーティ観賞');
        setHubLocale('en');
        assert.equal(early.t('menu.watchParty'), 'Watch Party');
    });

    it('spot-pins across locales catch catalog row-swaps', () => {
        assert.equal(setLocaleGet('es', 'menu.federation'), 'Federación');
        assert.equal(setLocaleGet('fr', 'menu.security'), 'Sécurité');
        assert.equal(setLocaleGet('de', 'menu.admin'), 'Administration');
        assert.equal(setLocaleGet('it', 'links.hubSettings'), 'Impostazioni dell’hub');
        assert.equal(setLocaleGet('pt_BR', 'menu.requests'), 'Solicitações');
        assert.equal(setLocaleGet('ja', 'links.incomingShares'), '受信共有');
    });

    it('[HUB-21] menu labels stay distinct: equal-folded or bare-truncation pairs are red', () => {
        // A top-bar label that is a bare prefix of a sibling reads as the same
        // item at a glance ('Compartido' / 'Compartido conmigo', '共有' / '共有中'
        // were exactly this: outgoing Shares vs incoming Shared-With-Me
        // collapsing onto one stem). An extension is legitimate only when the
        // extra words name ANOTHER menu section — 'Federation Shares' is
        // Federation x Shares, and every locale's federationShares row
        // composes the same way.
        for (const locale of SUPPORTED_LOCALES) {
            const menu = HUB_MESSAGES[locale].menu as Record<string, string>;
            const folded = Object.entries(menu).map(([key, label]) => [key, label.toLocaleLowerCase(locale.replace('_', '-'))] as const);
            for (const [keyA, a] of folded) {
                for (const [keyB, b] of folded) {
                    if (keyA === keyB) {
                        continue;
                    }
                    assert.notEqual(a, b, `${locale}: menu.${keyA} and menu.${keyB} render the same label`);
                    if (!b.startsWith(a)) {
                        continue;
                    }
                    const tail = b.slice(a.length);
                    const qualified = folded.some(([keyC, c]) => keyC !== keyA && keyC !== keyB && tail.includes(c));
                    assert.ok(
                        qualified,
                        `${locale}: menu.${keyB} '${menu[keyB]}' is a bare extension of menu.${keyA} '${menu[keyA]}' — `
                            + 'the extra words must name another menu section or the two items read as one',
                    );
                }
            }
        }
    });

    it('[HUB-21] quick links are distinct per locale and agree with the same-key menu item', () => {
        for (const locale of SUPPORTED_LOCALES) {
            const tag = locale.replace('_', '-');
            const menu = HUB_MESSAGES[locale].menu as Record<string, string>;
            const links = HUB_MESSAGES[locale].links as Record<string, string>;
            const foldedLinks = Object.values(links).map((label) => label.toLocaleLowerCase(tag));
            assert.equal(new Set(foldedLinks).size, foldedLinks.length, `${locale}: two quick links render the same label`);
            for (const key of Object.keys(links)) {
                if (key in menu) {
                    assert.equal(
                        links[key].toLocaleLowerCase(tag),
                        menu[key].toLocaleLowerCase(tag),
                        `${locale}: links.${key} must name the page exactly as menu.${key} does (case aside)`,
                    );
                }
            }
        }
    });

    it('[HUB-21] es/ja byte-pins for the distinct Shares / Shared-With-Me pair', () => {
        assert.equal(setLocaleGet('es', 'menu.shares'), 'Recursos compartidos');
        assert.equal(setLocaleGet('es', 'menu.federationShares'), 'Recursos compartidos de la federación');
        assert.equal(setLocaleGet('es', 'menu.sharedWithMe'), 'Compartido conmigo');
        assert.equal(setLocaleGet('ja', 'menu.shares'), '共有');
        assert.equal(setLocaleGet('ja', 'menu.sharedWithMe'), '自分宛ての共有');
        assert.equal(setLocaleGet('ja', 'links.sharedWithMe'), '自分宛ての共有');
    });

    it('lookup fails loud on a malformed or unknown key', () => {
        setHubLocale('en');
        const { t } = useHubMessages();
        assert.throws(() => t('no-dot' as HubMessageKey), /group\.key/);
        assert.throws(() => t('menu.nope' as HubMessageKey), /unknown key 'menu\.nope'/);
        assert.throws(() => t('nope.search' as HubMessageKey), /unknown key 'nope\.search'/);
    });

    it('main.ts wires the catalog and keeps ZERO raw menu labels', () => {
        const main = src('main.ts');
        assert.ok(
            main.includes("import { setHubLocale, useHubMessages } from './i18n/hub-messages'"),
            'main.ts imports the catalog seam',
        );
        assert.ok(main.includes('const bootLocale = resolveLocale();'), 'ONE boot locale for both catalogs');
        assert.ok(main.includes('setHubLocale(bootLocale);'), 'catalog bound at boot');
        assert.ok(main.includes('messages: messagesForLocale(bootLocale)'), 'ui seam uses the same locale');
        assert.equal(main.split("t('menu.").length - 1, 11, 'exactly 11 menu labels speak t()');
        for (const key of KEYS) {
            if (key.startsWith('menu.')) {
                assert.ok(main.includes(`label: t('${key}')`), `main.ts renders ${key}`);
            }
        }
        // The regression killer: no raw literal label survives in the wiring.
        assert.equal([...main.matchAll(/label:\s*([A-Za-z_$][\w$]*)?\s*'/g)].length, 0, 'raw label literal in main.ts');
        assert.ok(main.includes('mcpTokensMenuItem(),' ), 'the ui-owned MCP entry stays ui-owned');
    });

    it('the three hint pages speak t() for every quick link', () => {
        const pages: Array<[string, string[]]> = [
            ['pages/HubFederationPage.vue', ['links.incomingShares', 'links.hubSettings']],
            ['pages/HubManageSharesPage.vue', ['links.inviteLinks', 'links.myServers', 'links.hubSettings']],
            ['pages/HubMyServersPage.vue', ['links.inviteLinks', 'links.sharedWithMe', 'links.hubSettings']],
        ];
        for (const [rel, keys] of pages) {
            const text = src(rel);
            assert.ok(
                text.includes("import { useHubMessages } from '../i18n/hub-messages';"),
                `${rel} imports the catalog seam`,
            );
            assert.ok(text.includes('const { t } = useHubMessages();'), `${rel} binds t()`);
            for (const key of keys) {
                assert.ok(text.includes(`label: t('${key}')`), `${rel} renders ${key}`);
            }
            assert.equal([...text.matchAll(/label:\s*([A-Za-z_$][\w$]*)?\s*'/g)].length, 0, `raw label literal in ${rel}`);
        }
    });
});

/** Resolve one key under one locale via the production seam, then restore. */
function setLocaleGet(locale: (typeof SUPPORTED_LOCALES)[number], key: HubMessageKey): string {
    const keep = hubLocale();
    setHubLocale(locale);
    const value = useHubMessages().t(key);
    setHubLocale(keep);
    return value;
}
