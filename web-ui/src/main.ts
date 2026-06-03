import { createPhlixApp, MyServersPage, FederationPage, ManageSharesPage, AuditLogsPage } from '@phlix/ui';
import '@phlix/ui/style.css';
import '@phlix/ui/fonts.css';

const app = createPhlixApp({
    app: 'hub',
    // Top-bar nav for the hub's own pages. Supplying `menu` replaces the shell's
    // default Browse/Settings (which are media-server oriented and irrelevant to
    // the directory/relay hub), so only the hub surfaces are listed.
    menu: [
        { id: 'my-servers', label: 'My Servers', to: '/app/servers' },
        { id: 'federation', label: 'Federation', to: '/app/federation' },
        { id: 'manage-shares', label: 'Shares', to: '/app/shares' },
        { id: 'audit-logs', label: 'Audit Logs', to: '/app/audit-logs' },
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
        {
            path: '/app/audit-logs',
            name: 'audit-logs',
            component: AuditLogsPage,
        },
    ],
});
app.mount('#phlix-app');
