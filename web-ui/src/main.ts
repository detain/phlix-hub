import { createPhlixApp, MyServersPage, FederationPage, ManageSharesPage, AuditLogsPage } from '@phlix/ui';

const app = createPhlixApp({
    app: 'hub',
    extraRoutes: [
        {
            path: '/servers',
            name: 'my-servers',
            component: MyServersPage,
        },
        {
            path: '/federation',
            name: 'federation',
            component: FederationPage,
        },
        {
            path: '/shares',
            name: 'manage-shares',
            component: ManageSharesPage,
        },
        {
            path: '/audit-logs',
            name: 'audit-logs',
            component: AuditLogsPage,
        },
    ],
});
app.mount('#phlix-app');
