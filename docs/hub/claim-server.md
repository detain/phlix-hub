# Claim a Server

**Applies to:** Hub (phlix-hub)

Claiming attaches a running Phlix Media Server to your Hub account so it appears in your [My Servers](./my-servers.md) dashboard and can be accessed through the Hub relay when direct access is unavailable.

## Prerequisites

- A running Phlix Media Server (any platform)
- The server must be able to reach the Hub's public URL
- You must have a Hub account

## Step 1 — Generate a claim code on the server

On the machine running Phlix Media Server, run:

```bash
php scripts/pair-with-hub.php
```

The script outputs a claim code in the format `XXXX-XXXX` (for example `A2B4-C9D1`).

## Step 2 — Enter the claim code

1. Log in to Phlix Hub.
2. Click **Claim a New Server** (on the My Servers page, or navigate directly to `/claim-server`).
3. Enter the claim code from Step 1.
4. Click **Claim Server**.

On success, the server appears in your My Servers dashboard with an "online" status after it sends its first heartbeat.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Server shows "Never connected" | The server cannot reach the Hub's public URL. Check network/firewall settings. |
| "Invalid claim code" error | The claim code has expired (codes expire after 15 minutes) or was already used. Run `pair-with-hub.php` again on the server for a fresh code. |
| Server appears offline | The server's heartbeat interval may be too long. Check the server's `heartbeat_interval` config. |

## Technical details

Claiming creates a record in the `servers` table on the Hub, associated with your user ID. The server then periodically sends heartbeats to `POST /api/v1/servers/{id}/heartbeat` containing its current status, version, and hostname candidates.

Removing a claimed server through the Hub dashboard does not affect the server itself — it only removes the Hub registration. The server can be re-claimed at any time.
