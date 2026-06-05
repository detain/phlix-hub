import { createPhlixApp, MyServersPage, FederationPage, ManageSharesPage, buildHubAdminRoutes } from '@phlix/ui';
import '@phlix/ui/style.css';
import '@phlix/ui/fonts.css';

const app = createPhlixApp({
    app: 'hub',
    // Top-bar nav for the hub's own pages. Supplying `menu` replaces the shell's
    // default Browse/Settings (which are media-server oriented and irrelevant to
    // the directory/relay hub), so only the hub surfaces are listed. "Admin" is
    // `requiresAdmin`, so the shell shows it only for an authenticated admin
    // (`useAuthStore().isAdmin`); the hub admin API is gated server-side regardless.
    menu: [
        { id: 'my-servers', label: 'My Servers', to: '/app/servers' },
        { id: 'federation', label: 'Federation', to: '/app/federation' },
        { id: 'manage-shares', label: 'Shares', to: '/app/shares' },
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
            path: '/app/federation',
            name: 'federation',
            component: FederationPage,
        },
        {
            path: '/app/shares',
            name: 'manage-shares',
            component: ManageSharesPage,
        },
        // The shared Vue admin section (AdminLayout sidebar + Hub Dashboard, Users,
        // Logs, Settings, Audit Logs) at /app/admin/*. Reachable via the gated
        // "Admin" nav entry above. Audit Logs lives inside this section now — it was
        // formerly a top-level /app/audit-logs route + menu item.
        ...buildHubAdminRoutes(),
    ],
});
app.mount('#phlix-app');
