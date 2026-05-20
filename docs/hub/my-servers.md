# My Servers Dashboard

**Applies to:** Hub (phlix-hub)

The **My Servers** page (`GET /my-servers`) shows every Phlix server you have claimed and attached to your Hub account.

## Viewing your servers

1. Log in to Phlix Hub.
2. Click **My Servers** in the top navigation bar.
3. The dashboard displays all your claimed servers as cards.

Each server card shows:

| Field | Description |
|---|---|
| **Status badge** | Online (green dot) or Offline (gray dot) based on the last heartbeat received within the last 5 minutes |
| **Server name** | The name you assigned when claiming the server |
| **Version** |Installed Phlix Media Server version (e.g. `0.11.0`) |
| **Last seen** | Timestamp of the most recent heartbeat |
| **Direct access** | Hostname or IP address the server published for direct client connections |
| **Relay Active** | Shows when a secure relay tunnel is currently open (Phase C.6) |

## Removing a server

To remove a server from your account:

1. On the server card, click **Remove**.
2. Confirm the action when prompted.
3. The server card disappears from the dashboard.

This only unbinds the server from your Hub account — it does not uninstall Phlix Media Server from your hardware. The server can be re-claimed later with a new claim code.

## Empty state

If you have not claimed any servers, the dashboard displays an empty state with a prompt to claim your first server. See [Claim a Server](./claim-server.md) for the full flow.

## API

The dashboard is backed by:

- `GET /api/v1/me/servers` — returns `{servers: ServerInfoDto[]}` with the authenticated user's server list
- `DELETE /api/v1/me/servers/{id}` — removes a claimed server (204 on success, 403 if not owned, 404 if not found)
- `GET /api/v1/me/servers/{id}/access-info` — returns the best direct or relay URL for client access

All API endpoints require a valid Hub access token (`Authorization: Bearer <token>`).
