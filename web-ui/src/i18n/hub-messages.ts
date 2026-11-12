/**
 * Hub-local message catalog for the chrome this client OWNS — the top-bar
 * menu labels (`PhlixAppConfig.menu`, main.ts) and the quick-link labels the
 * hub pages hand to `HubPageHint`. Review hub Minor 50: these strings were
 * hardcoded English in the consumer, the one i18n gap the ui message seam
 * (src/i18n/index.ts) could not close on its own — menu labels are opaque
 * consumer strings that `AppHeader`/`NavLink` render VERBATIM (`" " + Y(e.label)`
 * in the pinned dist), never through `useMessages().t`, so no override of a ui
 * group can reach them.
 *
 * ## Why a separate map instead of ui's `messages` groups
 *
 * ui's merge is per-GROUP against its own DEFAULT_MESSAGES: an override for an
 * unknown group is dropped (see index.ts "Merge semantics"). A `hub` group
 * here would be silently discarded by the pinned bundle. The consumer-owned
 * surface therefore gets a consumer-owned catalog.
 *
 * ## Boot-time resolution, no runtime switching
 *
 * `setHubLocale()` runs once in main.ts with the SAME `resolveLocale()` result
 * that selects the ui catalogs — the estate has no runtime locale switch, and
 * the menu is built from config at boot. `useHubMessages()` stays bound to the
 * active catalog if a caller grabs it earlier (English default), never to a
 * stale snapshot.
 *
 * ## The byte-identical-en law
 *
 * `HUB_MESSAGES.en` holds the EXACT strings shipped for years (pinned by
 * tests/hub-menu-catalog.test.ts) — the localization is additive; an English
 * boot renders byte-identical output, and the S253 SPA gate proves the dist
 * matches this source.
 *
 * ## Deliberate residual (disclosed with the review item)
 *
 * Sentence/paragraph copy inside the hub pages (hint prose, watch-party error
 * states, security page labels) remains English here: fragment-by-fragment
 * localization of prose without estate translation review produces worse text
 * than a consistent English, and estate precedent (phlix-ui itself, ~50 of its
 * views on t()) treats chrome-nav localization + inline prose as acceptable
 * partial adoption. The catalog is the seam that follow-up page copy threads
 * through; adding a key is one line per locale.
 *
 * ## The distinctness law (HUB-21)
 *
 * Within a locale no menu label may equal a sibling or be a bare prefix of
 * one: outgoing Shares and incoming Shared-With-Me once collapsed onto a
 * single stem (es 'Compartido' / 'Compartido conmigo', ja '共有' / '共有中' —
 * the latter even reads as "currently sharing", i.e. outgoing). An extension
 * is legal only when its extra words name another menu section
 * (federationShares = federation x shares). A quick link that shares a key
 * with a menu item names its page exactly as that item does, case aside
 * (links.incomingShares names /app/federation/shares by direction). es uses
 * ui's own "recurso compartido" vocabulary for a share. Pinned by
 * tests/hub-menu-catalog.test.ts.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import type { SupportedLocale } from './index.ts';

/** The two-level catalog shape; EN defines the key space for all locales.
 *  Exported for the parity/byte-identity test (web-ui/tests/hub-menu-catalog.test.ts). */
export const HUB_MESSAGES = {
    en: {
        menu: {
            myServers: 'My Servers',
            search: 'Search',
            federation: 'Federation',
            federationShares: 'Federation Shares',
            shares: 'Shares',
            sharedWithMe: 'Shared With Me',
            watchParty: 'Watch Party',
            inviteLinks: 'Invite Links',
            requests: 'Requests',
            security: 'Security',
            admin: 'Admin',
        },
        links: {
            incomingShares: 'Incoming shares',
            hubSettings: 'Hub settings',
            inviteLinks: 'Invite links',
            myServers: 'My servers',
            sharedWithMe: 'Shared with me',
        },
    },
    es: {
        menu: {
            myServers: 'Mis servidores',
            search: 'Buscar',
            federation: 'Federación',
            federationShares: 'Recursos compartidos de la federación',
            shares: 'Recursos compartidos',
            sharedWithMe: 'Compartido conmigo',
            watchParty: 'Fiesta de visionado',
            inviteLinks: 'Enlaces de invitación',
            requests: 'Solicitudes',
            security: 'Seguridad',
            admin: 'Administración',
        },
        links: {
            incomingShares: 'Contenido recibido',
            hubSettings: 'Ajustes del hub',
            inviteLinks: 'Enlaces de invitación',
            myServers: 'Mis servidores',
            sharedWithMe: 'Compartido conmigo',
        },
    },
    fr: {
        menu: {
            myServers: 'Mes serveurs',
            search: 'Rechercher',
            federation: 'Fédération',
            federationShares: 'Partages de la fédération',
            shares: 'Partages',
            sharedWithMe: 'Partagé avec moi',
            watchParty: 'Soirée streaming',
            inviteLinks: 'Liens d’invitation',
            requests: 'Demandes',
            security: 'Sécurité',
            admin: 'Administration',
        },
        links: {
            incomingShares: 'Partages reçus',
            hubSettings: 'Paramètres du hub',
            inviteLinks: 'Liens d’invitation',
            myServers: 'Mes serveurs',
            sharedWithMe: 'Partagé avec moi',
        },
    },
    de: {
        menu: {
            myServers: 'Meine Server',
            search: 'Suchen',
            federation: 'Föderation',
            federationShares: 'Föderations-Freigaben',
            shares: 'Freigaben',
            sharedWithMe: 'Mit mir geteilt',
            watchParty: 'Filmabend',
            inviteLinks: 'Einladungslinks',
            requests: 'Anfragen',
            security: 'Sicherheit',
            admin: 'Administration',
        },
        links: {
            incomingShares: 'Eingehende Freigaben',
            hubSettings: 'Hub-Einstellungen',
            inviteLinks: 'Einladungslinks',
            myServers: 'Meine Server',
            sharedWithMe: 'Mit mir geteilt',
        },
    },
    it: {
        menu: {
            myServers: 'I miei server',
            search: 'Cerca',
            federation: 'Federazione',
            federationShares: 'Condivisioni della federazione',
            shares: 'Condivisioni',
            sharedWithMe: 'Condiviso con me',
            watchParty: 'Festa di visione',
            inviteLinks: 'Link di invito',
            requests: 'Richieste',
            security: 'Sicurezza',
            admin: 'Amministrazione',
        },
        links: {
            incomingShares: 'Condivisioni in arrivo',
            hubSettings: 'Impostazioni dell’hub',
            inviteLinks: 'Link di invito',
            myServers: 'I miei server',
            sharedWithMe: 'Condiviso con me',
        },
    },
    pt_BR: {
        menu: {
            myServers: 'Meus servidores',
            search: 'Pesquisar',
            federation: 'Federação',
            federationShares: 'Compartilhamentos da federação',
            shares: 'Compartilhamentos',
            sharedWithMe: 'Compartilhado comigo',
            watchParty: 'Sessão de exibição',
            inviteLinks: 'Links de convite',
            requests: 'Solicitações',
            security: 'Segurança',
            admin: 'Administração',
        },
        links: {
            incomingShares: 'Compartilhamentos recebidos',
            hubSettings: 'Configurações do hub',
            inviteLinks: 'Links de convite',
            myServers: 'Meus servidores',
            sharedWithMe: 'Compartilhado comigo',
        },
    },
    ja: {
        menu: {
            myServers: 'マイサーバー',
            search: '検索',
            federation: 'フェデレーション',
            federationShares: 'フェデレーション共有',
            shares: '共有',
            sharedWithMe: '自分宛ての共有',
            watchParty: 'パーティ観賞',
            inviteLinks: '招待リンク',
            requests: 'リクエスト',
            security: 'セキュリティ',
            admin: '管理',
        },
        links: {
            incomingShares: '受信共有',
            hubSettings: 'ハブ設定',
            inviteLinks: '招待リンク',
            myServers: 'マイサーバー',
            sharedWithMe: '自分宛ての共有',
        },
    },
} as const;

export type HubCatalog = typeof HUB_MESSAGES.en;
/** Distributive over groups: `keyof` of the VALUE UNION would intersect the
 *  groups' key spaces (offering only menu∩links keys). Mapped-type per group
 *  is the correct expansion. */
export type HubMessageKey = {
    [G in keyof HubCatalog]: `${G & string}.${keyof HubCatalog[G] & string}`;
}[keyof HubCatalog];

type Grouped = Record<string, Record<string, string>>;

/** Flatten `'group.key'` against the EN shape; a miss is a programmer typo —
 *  fail loud at render rather than print the raw key (ui's own t() law). */
function lookup(catalog: Grouped, key: HubMessageKey): string {
    const dot = key.indexOf('.');
    if (dot < 0) {
        throw new Error(`hub-messages: key must be 'group.key', got: ${key}`);
    }
    const group = catalog[key.slice(0, dot)];
    const value = group?.[key.slice(dot + 1)];
    if (typeof value !== 'string') {
        throw new Error(`hub-messages: unknown key '${key}'`);
    }
    return value;
}

let activeLocale: SupportedLocale = 'en';
let activeCatalog: Grouped = HUB_MESSAGES.en as unknown as Grouped;

/** Bind the translator to one locale's catalog. Called once at boot from
 *  main.ts with the resolved locale; 'en' (and the never-initialized default)
 *  render the byte-identical historical strings. */
export function setHubLocale(locale: SupportedLocale): void {
    activeLocale = locale;
    activeCatalog = HUB_MESSAGES[locale] as unknown as Grouped;
}

/** The locale the catalog is currently bound to (boot law: exactly one write
 *  from main.ts; exposed for the test's save/restore around locale probes). */
export function hubLocale(): SupportedLocale {
    return activeLocale;
}

/**
 * The translator: `t('menu.federation')`. Keys are typed against the EN
 * shape, so a renamed/removed key is a BUILD error (vue-tsc), not a runtime
 * surprise. Returns a stable closure over the ACTIVE catalog (late-bound —
 * safe to capture before setHubLocale ran at import time).
 */
export function useHubMessages(): { t: (key: HubMessageKey) => string } {
    return { t: (key: HubMessageKey): string => lookup(activeCatalog, key) };
}
