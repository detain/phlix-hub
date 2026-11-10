import { createPhlixApp, ServerDetailPage, FederationSharesPage, RequestsPage, InviteLinksPage, AcceptInvitePage, SearchPage, MusicAlbumPage, MusicArtistsPage, MusicArtistPage, MusicTracksPage, MusicPlayerPage, BooksPage, BookDetailPage, BookReaderPage, AudiobooksPage, AudiobookDetailPage, AudiobookPlayerPage, PhotoAlbumsPage, PhotoAlbumPage, PhotoViewPage, PhotoSlideshowPage, buildHubAdminRoutes, mcpTokensMenuItem, useServerStore } from '@phlix/ui';
import '@phlix/ui/style.css';
import '@phlix/ui/fonts.css';
import { messagesForLocale, resolveLocale } from './i18n';
import { setHubLocale, useHubMessages } from './i18n/hub-messages';
import HubMyServersPage from './pages/HubMyServersPage.vue';
import HubFederationPage from './pages/HubFederationPage.vue';
import HubManageSharesPage from './pages/HubManageSharesPage.vue';
import HubSharedWithMePage from './pages/HubSharedWithMePage.vue';
import HubSecuritySettingsPage from './pages/HubSecuritySettingsPage.vue';
import HubWatchPartyPage from './pages/HubWatchPartyPage.vue';
import { needsServerSelection, SERVER_DIRECTORY_PATH } from './utils/mediaGuard';
import { installMaintenanceNotice } from './utils/maintenanceNotice';

interface MinimalRouter {
    beforeEach(guard: (to: { path: string }) => true | { path: string }): void;
}

// i18n seam (config-time, @phlix/ui R6.5c): resolve the boot locale ONCE at
// startup (VITE_PHLIX_LOCALE → navigator.language → 'en' — no runtime switcher
// per estate doctrine). The locale drives BOTH catalogs: the ui-catalog override
// map passed into createPhlixApp (mergeMessages lays it OVER ui's English
// defaults; 'en' resolves to an EMPTY override, so English rendering is
// byte-identical to omitting the field — see src/i18n/index.ts for the full
// law), and the hub-local chrome catalog (src/i18n/hub-messages.ts — the menu
// labels below, which ui renders VERBATIM and no messages override can reach;
// review hub Minor 50).
const bootLocale = resolveLocale();
setHubLocale(bootLocale);
const { t } = useHubMessages();

const app = createPhlixApp({
    messages: messagesForLocale(bootLocale),
    app: 'hub',
    // The hub's home is its servers directory — never the media-server Browse page
    // (which calls server-only endpoints like /api/v1/libraries that 404 on the
    // hub). Login/signup land here and the brand link points here.
    home: '/app/servers',
    // The continue-watching endpoint is a media-server surface; don't poll it from
    // the hub shell (it only 404s until inline browsing is scoped to a server).
    features: { resumeSync: false },
    // Top-bar nav for the hub's own pages. Supplying `menu` replaces the shell's
    // default Browse/Settings (which are media-server oriented and irrelevant to
    // the directory/relay hub), so only the hub surfaces are listed. "Admin" is
    // `requiresAdmin`, so the shell shows it only for an authenticated admin
    // (`useAuthStore().isAdmin`); the hub admin API is gated server-side regardless.
    // The labels ride the hub-local catalog (hub-messages.ts / Minor 50); ids,
    // routes, and the admin gating below are unchanged.
    menu: [
        { id: 'my-servers', label: t('menu.myServers'), to: '/app/servers' },
        { id: 'search', label: t('menu.search'), to: '/app/search' },
        { id: 'federation', label: t('menu.federation'), to: '/app/federation', requiresAdmin: true },
        { id: 'federation-shares', label: t('menu.federationShares'), to: '/app/federation/shares', requiresAdmin: true },
        { id: 'manage-shares', label: t('menu.shares'), to: '/app/shares' },
        { id: 'shared-with-me', label: t('menu.sharedWithMe'), to: '/app/shared-with-me' },
        // Watch parties (M-HUB-08, [review hub__casualgamer M5]): the :8804
        // relay's rooms had ZERO portal surface — the review's "invisible
        // marquee feature". Sits with the personal media surfaces, NOT behind
        // the admin gate: the wire gates on the caller's own server ownership.
        { id: 'watch-party', label: t('menu.watchParty'), to: '/app/watch-party' },
        { id: 'invite-links', label: t('menu.inviteLinks'), to: '/app/invite-links' },
        { id: 'requests', label: t('menu.requests'), to: '/app/requests' },
        // Self account security (M-HUB-07). Sits with the other personal
        // surfaces (MCP tokens below), NOT behind the admin gate: the endpoint
        // gates on the caller's own session + current-password proof.
        { id: 'security', label: t('menu.security'), to: '/app/settings/security' },
        // MCP personal-access tokens (S62 API / S243 UI). `createPhlixApp` mounts
        // the ROUTE for every `app: 'hub'` consumer, but menus are consumer-owned —
        // supplying `menu` above replaces the shell default — so without this line
        // /app/mcp-tokens is reachable only by typing the URL. The builder keeps the
        // label/icon/path in @phlix/ui (one source of truth, like `adminMenu()`).
        // Deliberately NOT `requiresAdmin`: the endpoints gate on `$request->userId`
        // and every hub user manages their own credentials, so it sits with the
        // ordinary entries, before the admin-only "Admin" link.
        mcpTokensMenuItem(),
        { id: 'admin', label: t('menu.admin'), to: '/app/admin/dashboard', requiresAdmin: true },
    ],
    // Routes carry the full /app prefix: the router's history base is '/', so the
    // prefix lives in the path itself (not the history base).
    extraRoutes: [
        {
            path: '/app/servers',
            name: 'my-servers',
            // W5: hub-local wrapper adds the plan §9 PageHint (the @phlix/ui
            // PageHint component is not exported at the vendored pin; see
            // components/HubPageHint.vue for the retirement condition).
            component: HubMyServersPage,
        },
        {
            path: '/app/search',
            name: 'search',
            component: SearchPage,
        },
        {
            path: '/app/settings/security',
            name: 'settings-security',
            // Hub-local since [review hub__casualgamer M7]: the vendored
            // SecuritySettingsPage only ever spoke webauthn — endpoints this
            // hub does not serve — so every control on it was a guaranteed
            // 404. The hub page owns the honest render (password rotation via
            // POST /api/v1/me/password + a truthful passkeys statement).
            component: HubSecuritySettingsPage,
        },
        // NOTE: this dynamic route MUST come after the static /app/servers route
        // Vue router matches in order — static routes first, then dynamic segments.
        {
            path: '/app/servers/:id',
            name: 'server-detail',
            component: ServerDetailPage,
            props: true,
        },
        {
            path: '/app/federation',
            name: 'federation',
            // W5: hint wrapper — see /app/servers above for the rationale.
            component: HubFederationPage,
        },
        {
            path: '/app/federation/shares',
            name: 'federation-shares',
            component: FederationSharesPage,
        },
        {
            path: '/app/shares',
            name: 'manage-shares',
            // W5: hint wrapper — see /app/servers above for the rationale.
            component: HubManageSharesPage,
        },
        {
            path: '/app/shared-with-me',
            name: 'shared-with-me',
            // Hub-local since the liveness rework [review hub__casualgamer M3]:
            // the vendored shares page cannot show per-share hub-vantage state
            // (package pin boundary); the hub page owns the honest render.
            component: HubSharedWithMePage,
        },
        {
            path: '/app/watch-party',
            name: 'watch-party',
            // Hub-local since [review hub__casualgamer M5] (M-HUB-08): the
            // portal never surfaced the :8804 relay rooms at all. The page
            // rides ONLY the documented wire (mint → bearer dial → canonical
            // syncplay_* catalog); see utils/watchPartyWire.ts for the law.
            component: HubWatchPartyPage,
        },
        // Deep party link — the review's missing "link handler". The server id
        // rides the path because the relay dial is `/syncplay/{server_id}` and
        // ownership is re-checked per (token, server); `props: true` feeds both
        // into the same page, which auto-dials the join. NOT `meta.public`:
        // minting a relay token needs the account session, and the shell's
        // login round-trip returns here (AcceptInvitePage above is public
        // because an invitee by definition has no session yet — different act).
        {
            path: '/app/party/:serverId/:code',
            name: 'party-join',
            component: HubWatchPartyPage,
            props: true,
        },
        {
            path: '/app/invite-links',
            name: 'invite-links',
            component: InviteLinksPage,
        },
        // PUBLIC invite-acceptance landing. The hub redirects the public
        // GET /invite/{token} link here; `meta.public` opts this route out of the
        // auth guard so an unauthenticated invitee can reach it (they then log in /
        // sign up and hop back). `:token(.*)` captures the whole token even when a
        // base64 fallback token contains a `/` (JWT tokens are URL-safe already).
        {
            path: '/app/invite/:token(.*)',
            name: 'accept-invite',
            component: AcceptInvitePage,
            props: true,
            meta: { public: true },
        },
        {
            path: '/app/requests',
            name: 'requests',
            component: RequestsPage,
        },
        // --- Media routes (music / books / audiobooks / photo) -------------
        // Entered only through in-app navigation: the vendored @phlix/ui
        // library surfaces and Search results push these /app/* paths while a
        // server is scoped. The hub serves no server-rendered /music, /books,
        // /audiobooks or /photo URLs to redirect from — verified against the
        // server route table (zero matches) — so any direct (un-scoped) visit
        // relies on utils/mediaGuard.ts and lands on /app/servers instead of
        // a dead API call. (review Minor 52: the per-route comments here
        // formerly recited a server-rendered redirect flow that was never
        // wired; the group note above is now the single source of entry truth.)

        // Music album detail.
        {
            path: '/app/music/album/:name',
            name: 'music-album',
            component: MusicAlbumPage,
            props: true,
        },
        // Music artists listing.
        {
            path: '/app/music/artists',
            name: 'music-artists',
            component: MusicArtistsPage,
        },
        // Music artist detail.
        {
            path: '/app/music/artist/:name',
            name: 'music-artist',
            component: MusicArtistPage,
            props: true,
        },
        // Music tracks listing.
        {
            path: '/app/music/tracks',
            name: 'music-tracks',
            component: MusicTracksPage,
        },
        // Music player.
        {
            path: '/app/music/player',
            name: 'music-player',
            component: MusicPlayerPage,
        },
        // Books listing.
        {
            path: '/app/books',
            name: 'books',
            component: BooksPage,
        },
        // Book detail.
        {
            path: '/app/books/:id',
            name: 'book-detail',
            component: BookDetailPage,
            props: true,
        },
        // Book reader.
        {
            path: '/app/books/:id/read',
            name: 'book-reader',
            component: BookReaderPage,
            props: true,
        },
        // Audiobooks listing.
        {
            path: '/app/audiobooks',
            name: 'audiobooks',
            component: AudiobooksPage,
        },
        // Audiobook detail.
        {
            path: '/app/audiobooks/:id',
            name: 'audiobook-detail',
            component: AudiobookDetailPage,
            props: true,
        },
        // Audiobook player.
        {
            path: '/app/audiobooks/:id/read',
            name: 'audiobook-player',
            component: AudiobookPlayerPage,
            props: true,
        },
        // Photo albums listing.
        {
            path: '/app/photo/albums',
            name: 'photo-albums',
            component: PhotoAlbumsPage,
        },
        // Photo album detail.
        {
            path: '/app/photo/album/:id',
            name: 'photo-album',
            component: PhotoAlbumPage,
            props: true,
        },
        // Single photo view.
        {
            path: '/app/photo/photo/:id',
            name: 'photo-view',
            component: PhotoViewPage,
            props: true,
        },
        // Photo slideshow.
        {
            path: '/app/photo/slideshow',
            name: 'photo-slideshow',
            component: PhotoSlideshowPage,
        },
        // The shared Vue admin section (AdminLayout sidebar + Hub Dashboard, Users,
        // Logs, Settings, Audit Logs) at /app/admin/*. Reachable via the gated
        // "Admin" nav entry above. Audit Logs lives inside this section now — it was
        // formerly a top-level /app/audit-logs route + menu item.
        ...buildHubAdminRoutes(),
    ],
});

// [review hub Major 39] media-surface server-selection guard: every guarded
// path (law + truth in src/utils/mediaGuard.ts) with no selected server lands
// on the server directory instead of a guaranteed `{error:'Not found'}` blob —
// the redirect IS the prompt; nav entries stay clickable by design.
// vue-router still installs the router object on globalProperties.$router at
// app.use() time (vue-router 5.2 verified); a missing one means the vendored
// shell's install broke — fail loudly rather than silently ship dead-ends.
const router = (app.config.globalProperties as { $router?: MinimalRouter }).$router;
if (router === undefined) {
    throw new Error('hub media guard: vue-router did not install $router — shell contract broken');
}
router.beforeEach((to) => (
    needsServerSelection(to.path) && !useServerStore().hasCurrent
        ? { path: SERVER_DIRECTORY_PATH }
        : true
));

app.mount('#phlix-app');

// SH-079: hub-level maintenance notice. Installs after mount so the banner's
// body-append lands on the live document; wraps globalThis.fetch for the whole
// app (the vendored @phlix/ui client uses fetch, so every API response is
// observed). Law + inert-outside-browser guarantees in src/utils/maintenanceNotice.ts.
installMaintenanceNotice();
