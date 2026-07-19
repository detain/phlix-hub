import { createPhlixApp, MyServersPage, ServerDetailPage, FederationPage, FederationSharesPage, ManageSharesPage, SharedWithMePage, RequestsPage, InviteLinksPage, SearchPage, SecuritySettingsPage, MusicAlbumPage, MusicArtistsPage, MusicArtistPage, MusicTracksPage, MusicPlayerPage, BooksPage, BookDetailPage, BookReaderPage, AudiobooksPage, AudiobookDetailPage, AudiobookPlayerPage, PhotoAlbumsPage, PhotoAlbumPage, PhotoViewPage, PhotoSlideshowPage, buildHubAdminRoutes } from '@phlix/ui';
import '@phlix/ui/style.css';
import '@phlix/ui/fonts.css';

const app = createPhlixApp({
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
    menu: [
        { id: 'my-servers', label: 'My Servers', to: '/app/servers' },
        { id: 'search', label: 'Search', to: '/app/search' },
        { id: 'federation', label: 'Federation', to: '/app/federation' },
        { id: 'federation-shares', label: 'Federation Shares', to: '/app/federation/shares' },
        { id: 'manage-shares', label: 'Shares', to: '/app/shares' },
        { id: 'shared-with-me', label: 'Shared With Me', to: '/app/shared-with-me' },
        { id: 'invite-links', label: 'Invite Links', to: '/app/invite-links' },
        { id: 'requests', label: 'Requests', to: '/app/requests' },
        { id: 'admin', label: 'Admin', to: '/app/admin/dashboard', requiresAdmin: true },
    ],
    // Routes carry the full /app prefix: the router's history base is '/', so the
    // prefix lives in the path itself (not the history base).
    extraRoutes: [
        {
            path: '/app/servers',
            name: 'my-servers',
            component: MyServersPage,
        },
        {
            path: '/app/search',
            name: 'search',
            component: SearchPage,
        },
        {
            path: '/app/settings/security',
            name: 'settings-security',
            component: SecuritySettingsPage,
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
            component: FederationPage,
        },
        {
            path: '/app/federation/shares',
            name: 'federation-shares',
            component: FederationSharesPage,
        },
        {
            path: '/app/shares',
            name: 'manage-shares',
            component: ManageSharesPage,
        },
        {
            path: '/app/shared-with-me',
            name: 'shared-with-me',
            component: SharedWithMePage,
        },
        {
            path: '/app/invite-links',
            name: 'invite-links',
            component: InviteLinksPage,
        },
        {
            path: '/app/requests',
            name: 'requests',
            component: RequestsPage,
        },
        // Music album detail — reached by redirecting the server-rendered
        // /music/albums/{name} to the SPA, or from the music library drill-down.
        {
            path: '/app/music/album/:name',
            name: 'music-album',
            component: MusicAlbumPage,
            props: true,
        },
        // Music artists listing — reached by redirecting the server-rendered
        // /music/artists to the SPA.
        {
            path: '/app/music/artists',
            name: 'music-artists',
            component: MusicArtistsPage,
        },
        // Music artist detail — reached by redirecting the server-rendered
        // /music/artists/{name} to the SPA, or from the artists listing.
        {
            path: '/app/music/artist/:name',
            name: 'music-artist',
            component: MusicArtistPage,
            props: true,
        },
        // Music tracks listing — reached by redirecting the server-rendered
        // /music/tracks to the SPA.
        {
            path: '/app/music/tracks',
            name: 'music-tracks',
            component: MusicTracksPage,
        },
        // Music player — reached by redirecting the server-rendered
        // /music/player to the SPA.
        {
            path: '/app/music/player',
            name: 'music-player',
            component: MusicPlayerPage,
        },
        // Books listing — reached by redirecting the server-rendered
        // /books to the SPA.
        {
            path: '/app/books',
            name: 'books',
            component: BooksPage,
        },
        // Book detail — reached by redirecting the server-rendered
        // /books/{id} to the SPA, or from the books listing.
        {
            path: '/app/books/:id',
            name: 'book-detail',
            component: BookDetailPage,
            props: true,
        },
        // Book reader — reached by redirecting the server-rendered
        // /books/{id}/read to the SPA.
        {
            path: '/app/books/:id/read',
            name: 'book-reader',
            component: BookReaderPage,
            props: true,
        },
        // Audiobooks listing — reached by redirecting the server-rendered
        // /audiobooks to the SPA.
        {
            path: '/app/audiobooks',
            name: 'audiobooks',
            component: AudiobooksPage,
        },
        // Audiobook detail — reached by redirecting the server-rendered
        // /audiobooks/{id} to the SPA, or from the audiobooks listing.
        {
            path: '/app/audiobooks/:id',
            name: 'audiobook-detail',
            component: AudiobookDetailPage,
            props: true,
        },
        // Audiobook player — reached by redirecting the server-rendered
        // /audiobooks/{id}/read to the SPA.
        {
            path: '/app/audiobooks/:id/read',
            name: 'audiobook-player',
            component: AudiobookPlayerPage,
            props: true,
        },
        // Photo albums listing — reached by redirecting the server-rendered
        // /photo/albums to the SPA.
        {
            path: '/app/photo/albums',
            name: 'photo-albums',
            component: PhotoAlbumsPage,
        },
        // Photo album detail — reached by redirecting the server-rendered
        // /photo/album/{id} to the SPA.
        {
            path: '/app/photo/album/:id',
            name: 'photo-album',
            component: PhotoAlbumPage,
            props: true,
        },
        // Single photo view — reached by redirecting the server-rendered
        // /photo/photo/{id} to the SPA.
        {
            path: '/app/photo/photo/:id',
            name: 'photo-view',
            component: PhotoViewPage,
            props: true,
        },
        // Photo slideshow — reached by redirecting the server-rendered
        // /photo/slideshow to the SPA.
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
app.mount('#phlix-app');
