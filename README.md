# Phlix Hub

**Central cloud directory + reverse-tunnel relay for [Phlix](https://github.com/detain/phlix) media servers.**
Sign in once, reach any of your servers from anywhere — no port forwarding, no static IP, no VPN. Fully self-hostable.

[![CI](https://github.com/detain/phlix-hub/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/phlix-hub/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/phlix-hub/graph/badge.svg)](https://codecov.io/gh/detain/phlix-hub)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![Psalm](https://img.shields.io/badge/Psalm-level%201-brightgreen)](https://psalm.dev/)
[![Code style](https://img.shields.io/badge/code%20style-PSR--12-blueviolet)](https://www.php-fig.org/psr/psr-12/)
[![License: MPL 2.0](https://img.shields.io/badge/License-MPL%202.0-blue.svg)](LICENSE)

---

## Table of contents

- [What is the Hub?](#what-is-the-hub)
- [Features](#features)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [One-line install](#one-line-install)
- [Updating an existing install](#updating-an-existing-install)
- [Uninstalling](#uninstalling)
- [Running alongside phlix-server](#running-alongside-phlix-server)
- [Quick start (development)](#quick-start-development)
- [Production install on Ubuntu](#production-install-on-ubuntu)
  - [1. System packages](#1-system-packages)
  - [2. MySQL: database, user, and grants](#2-mysql-database-user-and-grants)
  - [3. Application code](#3-application-code)
  - [4. Environment configuration](#4-environment-configuration)
  - [5. Run migrations](#5-run-migrations)
  - [6. Run as a systemd service](#6-run-as-a-systemd-service)
  - [7. Reverse proxy & TLS](#7-reverse-proxy--tls)
- [Docker](#docker)
- [Configuration reference](#configuration-reference)
- [Database schema](#database-schema)
- [HTTP API](#http-api)
- [Connecting a media server](#connecting-a-media-server)
- [Testing & quality](#testing--quality)
- [Project structure](#project-structure)
- [Related repositories](#related-repositories)
- [License](#license)

---

## What is the Hub?

A Phlix media server normally lives on your home network behind NAT. The **Hub** is the small,
self-hostable cloud service that makes those servers reachable from anywhere:

- Each server **claims** itself to the Hub once (a short pairing code), then holds an outbound
  **WebSocket reverse tunnel** open to the Hub.
- Remote clients (apps, browsers) connect to the Hub, which **relays** their traffic down the
  tunnel to the right server — so the server never needs an inbound port open.
- The Hub is also a **directory**: it tracks which servers you own, their liveness (heartbeats),
  shared libraries, invite links, and media requests.

You can use the public Hub or run your own — the same codebase powers both.

## Features

- **Accounts & auth** — signup/login, Argon2id password hashing, HMAC-SHA256 JWT access &
  refresh tokens, and a published JWKS endpoint. **The first account created is auto-promoted
  to admin** — on a fresh deployment register your admin account *before* exposing `:8800` to
  the network; whoever signs up first wins. Close the door afterwards with `HUB_SIGNUPS_ENABLED=false`
  at boot or the live `auth.signups_disabled` hub setting (admin console → Settings).
- **Server claiming** — short-lived claim codes, enrollment JWTs, and a server registry with
  per-server public keys (JWK).
- **Reverse-tunnel relay** — servers hold an outbound WebSocket to the Hub; remote clients are
  multiplexed down that tunnel over a compact binary frame protocol. Idle tunnels are reaped and
  liveness is tracked with heartbeats. The tunnel data-plane applies real backpressure:
  when the send buffer is full, the upstream reader is paused and resumed only when the buffer
  drains, preventing silent frame drops on slow streams.
- **Subdomain allocation** — each enrolled server can be assigned `&lt;subdomain&gt;.&lt;public-domain&gt;`
  for clean, per-server URLs.
- **Library sharing** — share a specific library on one of your servers with another Hub user,
  with read-only or read/write permission levels. The **Shares** page carries the direct door
  (share instantly with a registered collaborator) above the list-and-revoke view; for people
  without an account yet, **Invite Links** create the account and the share in one step.
- **Invite links** — single-use, signed invite links that grant library access to a recipient.
- **Hub-to-hub federation** — federate with peer hubs to share libraries across hubs, with
  per-peer sessions and admin delegation. Honest limit: delegation is currently local
  bookkeeping only — `createAdminDelegation` writes the DB row plus an audit entry, but the
  master-side wire push is not implemented, so no `admin_delegation` frame ever reaches the
  peer today (the reserved envelope is documented as such on `:8805` in the OpenAPI spec).
- **Media requests** — a Jellyseerr-class request queue. Users request movies/series; admins
  approve, and the Hub talks to Sonarr/Radarr to fulfil them.
- **Web UI & admin console** — the hub's front door is a Vue SPA (the shared `@phlix/ui` design
  system) served at `/app` (`/` redirects to `/app/servers`). Every signed-in user gets **My Servers**,
  **Server Detail**, **Search**, **Federation**, **Federation Shares**, **Shares**, **Shared With Me**,
  **Invite Links**, and **Requests**; there is a public **invite-acceptance** page at `/app/invite/:token`
  (the target of the public `GET /invite/{token}` link). On top of that a `requiresAdmin` **admin console**
  at `/app/admin/*` (Hub Dashboard, Users, Logs, Settings, Audit Logs, request approval). The Vue SPA is
  now the **only** web UI: the legacy server-rendered (Smarty) pages have been **deleted** and their old
  paths 302-redirect to `/app` (e.g. `/my-servers` → `/app/servers`, `/login` → `/app/login`, the public
  `/invite/{token}` → `/app/invite/{token}`). The `smarty/smarty` dependency was removed — the hub renders
  no templates. Everything is backed by a full JSON API under `/api/v1` (incl. `/api/v1/admin/*`).
- **MCP server** — an [MCP](https://modelcontextprotocol.io) endpoint at `/mcp` (JSON-RPC over
  `POST`, SSE over `GET`) exposing read-only tools over the servers you own. Authenticated with
  scoped personal access tokens minted at `/api/v1/me/mcp-tokens` and stored only as a SHA-256 hash.
- **OAuth 2.0 authorization server** — `/oauth/authorize`, `/oauth/token`, and the scoped resource
  surface `/oauth/userinfo`, with **S256-only** PKCE and a scope vocabulary shared with MCP. Clients
  are registered from the CLI (`php bin/phlix oauth:client:register`).
- **Alexa skill** — an account-linked skill endpoint at `/alexa/skill`, gated by full Amazon
  signature + certificate-chain verification, that drives playback on your servers by voice.
- **Operations-ready** — structured JSON logging (Monolog) across dedicated channels
  (app, error, hub, relay, audit), a `/health` endpoint, and idempotent SQL migrations.

## Architecture

The Hub runs as a set of long-lived [Workerman](https://www.workerman.net/) workers in a single
process group:

| Worker | Default port | Purpose |
|--------|--------------|---------|
| HTTP | `8800` | REST API + the Vue SPA (`/app`; legacy page paths 302-redirect here) + `/health` |
| Relay (server-facing) | `8802` | Servers connect here to open their outbound tunnel |
| Relay (client-facing) | `8803` | Client-mount WS (`GET /client/{server_id}`). ⚠ **Declared unavailable** in the multi-process topology: every mount is refused with close 1011 (`client_mount_bridge_unavailable`) — see `docs/websockets.md`; live client↔server traffic rides the HTTP relay proxy (`/api/v1/servers/{id}/proxy/*`) |
| SyncPlay relay | `8804` | Synchronised-playback clients (`GET /syncplay/{server_id}`) |
| Federation | `8805` | Hub-to-hub peer tunnel |

Every WebSocket surface is documented in [`docs/websockets.md`](docs/websockets.md); the `:8800`
HTTP surface is described by [`openapi.yaml`](openapi.yaml).

Supporting pieces:

- **PSR-11 container** (PHP-DI 7) wires services; routes are registered in
  [`src/Application.php`](src/Application.php).
- **MySQL** is accessed through an async connection pool (`workerman/mysql`). The pool is
  initialised lazily so `/health` stays up even if the database is briefly unreachable.
- **JWT** auth is symmetric (HS256); Ed25519 keys are used for signing enrollment/relay material,
  and the public key set is served at `/.well-known/jwks.json`.

## Requirements

- **PHP 8.3+**. Nine extensions are hard boot gates — the hub refuses to start without them
  (`scripts/assert-required-extensions.php`, each entry citing the exact symbol the code calls):
  `openssl`, `curl`, `sodium`, `mbstring`, `json`, `hash`, `filter`, `ctype`, `posix`.
  `openssl` is additionally what makes any TLS dial (federation `wss://`/`https://` masters,
  relay TLS) possible at runtime — without it those paths fail loud, not silent.
- **`pcntl`** — not boot-gated (Workerman requires it for process management rather than hub
  source calling it directly; it ships with Ubuntu's `php-cli` package).
- **Swoole + php-uv are recommended, not required.** They enable Workerman's coroutine runtime;
  the hub runs without them but logs an explicit `Swoole extension not detected — coroutine
  runtime will not be active` warning at boot. `scripts/install.sh` compiles both from source;
  the manual package list below deliberately omits them (apt has no php-swoole on the supported
  releases) — build them or use `ghcr.io/detain/phlix-base`, which carries PHP 8.3 + Swoole + php-uv.
- **MySQL 8.0+** (or MariaDB 10.6+)
- **Composer 2**
- A POSIX host (Linux recommended; Workerman uses `pcntl`/`posix` for process management)

## One-line install

On a fresh Ubuntu/Debian host, [`scripts/install.sh`](scripts/install.sh) does everything in
[Production install](#production-install-on-ubuntu) for you — system packages, MySQL database +
user, code, env file, JWT secret, migrations, a systemd service, and an HAProxy reverse proxy
with an auto-renewing Let's Encrypt certificate:

> The installer also compiles the **Swoole + php-uv** extensions from source (the coroutine
> runtime Workerman uses), idempotently skipping the build when they already load, and runs a
> `disable_functions` preflight — see
> [Swoole & php-uv on Linux](https://detain.github.io/phlix-docs/install/linux#swoole-php-uv-coroutine-runtime).

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh | sudo bash
```

To set up HTTPS at the same time, pass your domain and an email for Let's Encrypt:

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh \
  | sudo bash -s -- --domain hub.example.com --admin-email you@example.com
```

The script prompts for the install path, database user/password, and hostname when run in a
terminal (with sensible defaults), and runs **fully unattended** when piped or given `-y`. See
`sudo bash scripts/install.sh --help` for every flag. Prefer to do it by hand? Follow the
[step-by-step guide](#production-install-on-ubuntu) below.

### Install flags

`sudo bash scripts/install.sh --help` lists every option. The most useful:

| Flag | Effect |
|---|---|
| `--domain HOST` | Public hostname for the hub (enables TLS when paired with `--admin-email`) |
| `--admin-email EMAIL` | Email registered with Let's Encrypt |
| `--db-name`, `--db-user`, `--db-pass`, `--db-host`, `--db-port` | MySQL identity (random password if `--db-pass` omitted) |
| `--jwt-secret SECRET` | HMAC secret used to sign JWTs (random 32-byte hex if omitted) |
| `--service-user USER` | System user to run as (default `phlix-hub` — dedicated system account, created if missing) |
| `--workers N` | HTTP worker processes (default 4) |
| `--branch NAME` | Git branch or tag to install (default `master`) |
| `--repo URL` | Git repository URL (default `detain/phlix-hub`) |
| `--tls` / `--no-tls` | Force or skip Let's Encrypt + HAProxy TLS |
| `--no-proxy` | Skip the managed HAProxy entirely (use your own reverse proxy) |
| `--update` | Pull new code + run migrations on an existing install (preserves env + secrets) |
| `--uninstall` | Remove the install — interactive prompts before each destructive step |
| `--purge` | With `--uninstall`, also drop the DB, delete the Let's Encrypt cert, and remove the dedicated system user |
| `-y`, `--non-interactive` | Never prompt; use defaults/flags |
| `--interactive` | Force prompts even when piped |

> Default service user changed from `www-data` to `phlix-hub` so the hub runs under
> its own dedicated system account, isolated from the apache/nginx-owned `www-data`.
> Existing installs that were created on `www-data` keep running on `www-data` —
> `--update` reads `User=` from the systemd unit rather than rewriting it.

## Updating an existing install

The same `scripts/install.sh` updates an in-place install **without rotating any secrets**. It
reads the existing `/etc/phlix-hub.env` (so the JWT secret and DB password are preserved),
pulls the latest code, refreshes Composer dependencies, runs pending migrations, and restarts
the service:

```bash
sudo bash /opt/phlix-hub/scripts/install.sh --update -y
```

Or via the one-liner:

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh \
  | sudo bash -s -- --update -y
```

Pin to a specific tag or branch with `--branch`:

```bash
sudo bash /opt/phlix-hub/scripts/install.sh --update --branch v0.2.0 -y
```

What `--update` does, in order:

1. Discovers the install path from the systemd unit's `WorkingDirectory` (so non-default
   `--install-path` setups are detected automatically).
2. Reads `/etc/phlix-hub.env` and reuses every value — `HUB_JWT_SECRET`, `HUB_DB_PASSWORD`,
   `HUB_PUBLIC_DOMAIN`, etc. are never regenerated.
3. `git fetch --depth 1 origin $BRANCH` then `git reset --hard origin/$BRANCH` in the install
   directory. Uncommitted local edits are **discarded** — the script warns first.
4. `composer install --no-dev --optimize-autoloader` (follows `composer.lock`).
5. Clears the legacy `var/smarty/{compile,cache}` directories **only if they still exist**
   (vestigial from the pre-SPA Smarty UI — a no-op on installs created after it was removed).
6. Runs `scripts/run-migrations.php` — idempotent, only pending migrations apply.
7. `systemctl daemon-reload` then `systemctl restart phlix-hub`.
8. `curl http://localhost:$HUB_PORT/health` as a final sanity check.

What it explicitly does **not** touch: the env file, MySQL grants, HAProxy config, or the
Let's Encrypt certificate. If a release adds new `HUB_*` env vars, append them to
`/etc/phlix-hub.env` yourself — anything the code expects but doesn't find falls back to its
documented default.

## Backing up and restoring

The Hub has exactly **three pieces of state**. A backup that does not carry all
three together is not a backup — restoring a database against a different
secret/key pair (or a fresh install's auto-generated key) produces a hub that
starts cleanly and then fails closed in ways that look like data loss.

| Piece | Where | What breaking it costs |
|-------|-------|------------------------|
| **Database** | MySQL schema `phlix_hub` (credentials in `/etc/phlix-hub.env`) | Every server registration, user, claim code, invite, share, quota row, federation peer/key registration, rate-limit history, audit trail and the migration ledger. Restore **only onto a schema migrated to the same or a newer code version** (the `migrations` table checksums files; see *Database migrations* above). |
| **JWT session secret** | `HUB_JWT_SECRET` inside `/etc/phlix-hub.env` (chmod 600) | Signs every access/refresh token (`config/auth.php`; ≥32 bytes). If it is lost, changed, or the env file is restored from a different era, **every issued session becomes unverifiable**: all users are logged out at once and refresh tokens cannot save them. When the var is missing entirely, `AuthServicesProvider` falls back to a process-local random secret — tokens die on every restart, so never run production without it. |
| **Hub signing key** | `config/hub-signing-key.pem`, mode **0600**, auto-generated on first boot by `HubServicesProvider` → `Ed25519KeyManager` (path override: `hub_signing_key_path` in `config/server.php`) | The hub's Ed25519 identity. It signs enrollment artifacts servers present on pairing and is the master-side key in the federation mutual proof-of-key ceremony. If it is lost and a fresh one is generated, **already-registered servers fail verification** (re-pair them) and **federation peers whose leaf keys were registered under the old identity refuse the hub** until trust is re-established with each peer. |

Everything else is derived and safe to lose: `.logs/`, `var/` (Smarty
compile/cache, DI container cache, Swoole HOME lock, Workerman pid/status), and
`public/assets/app/` is a committed build.

Backup (cron-friendly, keeps the trio in one archive):

```bash
#!/usr/bin/env bash
set -euo pipefail
STAMP=$(date +%F_%H%M)
DEST=/var/backups/phlix-hub/$STAMP; mkdir -p "$DEST"
# 1. Database (consistent snapshot without locking tables).
mysqldump --single-transaction --routines phlix_hub | gzip > "$DEST/hub-db.sql.gz"
# 2. Environment (contains HUB_JWT_SECRET + DB credentials) and 3. signing key.
install -m 600 /etc/phlix-hub.env               "$DEST/phlix-hub.env"
install -m 600 /opt/phlix-hub/config/hub-signing-key.pem "$DEST/hub-signing-key.pem"
find /var/backups/phlix-hub -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
```

Restore, in the order that keeps every boot gate happy:

1. Stop the service (`systemctl stop phlix-hub`).
2. Create the schema and reload: `mysql phlix_hub < hub-db.sql.gz`.
3. Put `phlix-hub.env` at `/etc/phlix-hub.env` **before** first boot (mode 600,
   owned by root — the service reads it via `EnvironmentFile`), and the key at
   `config/hub-signing-key.pem` **before** first boot (mode 600, owned by the
   service user) — the auto-generators only fire when the file is absent, and a
   pre-existing valid file is always kept.
4. `systemctl start phlix-hub`, then `curl -fs http://127.0.0.1:8800/health`.

If the JWT secret or the signing key cannot be recovered, generate replacements
(new 64-char hex secret; delete the key file to auto-regenerate) and accept the
consequences above: mass re-login, and re-pairing of servers / re-trusting of
federation peers.

## Uninstalling

`scripts/install.sh --uninstall` removes an existing install. By default it is **interactive**
and prompts separately before each destructive step. The MySQL database and the Let's Encrypt
certificate are **kept** unless you opt in explicitly:

```bash
sudo bash /opt/phlix-hub/scripts/install.sh --uninstall
```

Add `--purge` to also drop the database (and user) and delete the Let's Encrypt certificate
via `certbot delete`. Combine with `-y` for a fully unattended teardown:

```bash
sudo bash /opt/phlix-hub/scripts/install.sh --uninstall --purge -y
```

Piped, non-interactive runs require an explicit `-y` to proceed.

What it removes, only if it finds them:

1. The `phlix-hub` systemd service — `stop`, `disable`, remove the unit, `daemon-reload`.
2. HAProxy fragment at `/etc/haproxy/phlix-managed/phlix-hub.cfg.fragment`, and
   `/etc/haproxy/haproxy.cfg` is rebuilt. If phlix-server is still installed, its frontend
   and backend stay. If phlix-hub was the last Phlix project, the pre-Phlix snapshot at
   `/etc/haproxy/haproxy.cfg.pre-phlix.bak` is restored (or `haproxy.cfg` is removed and
   haproxy is stopped + disabled if no snapshot exists).
3. The combined PEM at `/etc/haproxy/certs/<domain>.pem`.
4. `/etc/cron.d/phlix-hub-certbot` and `/etc/letsencrypt/renewal-hooks/deploy/phlix-haproxy.sh`.
5. The Let's Encrypt cert via `certbot delete --cert-name <domain>` — only with `--purge` or
   interactive confirmation.
6. The MySQL database and dedicated user — only with `--purge` or interactive confirmation.
7. The install directory (`rm -rf`, with a denylist of system paths like `/`, `/etc`, `/opt`).
8. `/etc/phlix-hub.env` (env file).
9. The dedicated system user (`phlix-hub` or whatever `User=` the systemd unit was using) via
   `userdel` — only with `--purge` or interactive confirmation. Refuses to touch shared OS
   accounts (`www-data`, `root`, `daemon`, etc.). Cross-detects phlix-server's systemd unit
   and refuses to remove a user that's still being used by the sibling service.

System packages (`php-*`, `mysql-server`, `haproxy`, `certbot`) and `ufw` rules are left in
place — uninstall them yourself with `apt remove` / `ufw delete` if you no longer need them.

## Running alongside phlix-server

Both installers can share a single HAProxy instance — they auto-merge into one
`/etc/haproxy/haproxy.cfg`. Just run both installers normally; whichever runs second detects
the first's fragment and rebuilds a combined config that routes by `Host:` header.

```bash
# 1. Install phlix-hub first (with TLS).
curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh \
  | sudo bash -s -- --domain hub.example.com --admin-email you@example.com -y

# 2. Install phlix-server, also with TLS, on a different hostname.
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh \
  | sudo bash -s -- --domain phlix.example.com --admin-email you@example.com -y
```

After both finish, `/etc/haproxy/haproxy.cfg` is a Phlix-managed config carrying both
projects' frontends and backends, with HAProxy picking the right cert per SNI hostname from
`/etc/haproxy/certs/`.

**How the merge works.** Each install drops a fragment at
`/etc/haproxy/phlix-managed/<project>.cfg.fragment` with `fe_http`, `fe_https`, and `backends`
sections. A shared rebuilder then assembles the final `haproxy.cfg` from every fragment it
finds. HAProxy's `crt /etc/haproxy/certs/` directive auto-loads every `.pem` in that directory
and picks the right one per SNI hostname.

The first install snapshots any pre-Phlix `haproxy.cfg` to
`/etc/haproxy/haproxy.cfg.pre-phlix.bak`.

**Uninstall behaviour**: `--uninstall` removes only that project's fragment and rebuilds. If
other Phlix projects remain, their frontend stays untouched. When the **last** Phlix project
is uninstalled, the rebuilder restores the pre-Phlix snapshot (or removes `haproxy.cfg`
outright if there was no pre-Phlix config) and stops/disables `haproxy`.

The **hub server-tunnel port** (`:8802`) is a separate listener — servers connect to that port
directly. Open it on the firewall but don't put it behind the HAProxy 80/443 frontend.

**What the managed install makes reachable:**

| Port | Surface | Managed HAProxy path | `--no-proxy` path |
| --- | --- | --- | --- |
| `8800` | HTTP API + SPA + `/health` | routed at `/` via 80/443 | ufw-opened |
| `8802` | server tunnels | direct — ufw-opened (pairing law: never proxied) | ufw-opened |
| `8803` | client mount (declared unavailable) | routed at `/client/` | loopback only — point your proxy at it |
| `8804` | SyncPlay watch-party clients | routed at `/syncplay/` | ufw-opened (plaintext WS — close it once your proxy routes it) |
| `8805` | federation MASTER ingress (peer dials) | routed at `/relay/federation/` | ufw-opened (same plaintext caveat) |
| `2206` | cross-process channel broker | loopback only | loopback only |

Under the managed HAProxy the two relay WebSocket surfaces are **not** opened on the
firewall — and from there their stories differ. Federation leaves ride the existing 443
frontend end to end: a leaf's dial target is operator-configured, and the managed
`/relay/federation/` route carries `wss://<master-domain>/relay/federation/{hub_id}` with
no extra port, TLS included. Browser SyncPlay clients now split. The route
exists, and on a domain-bound managed install the installer itself steers a
seam-aware browser onto it
(R-HUB-13): the generated env block gains `HUB_WS_URL` — `wss://<domain>`
under TLS, `ws://<domain>` on the plain-HTTP managed frontend, always
matching the frontend the fragments actually built (certbot-failure fallback
included) — and `HUB_WS_PATH=/syncplay` (the ui seam — `HUB_WS_URL` is the
master switch; a lone `HUB_WS_PATH` refines nothing), and
`SharedUiController` grammar-validates both (invalid values fail soft to
unset) into the shell's `window.__PHLIX__.wsUrl/wsPath` bootstrap —
seam-aware `@phlix/ui` clients served by the hub then dial the managed route
with no operator step at all. The hub portal bundle never consults the seam:
its dial law stays the legacy direct form
`ws(s)://<page-host>:8804/syncplay/{server_id}` with the port fixed by
`SyncPlayRelayWorker::DEFAULT_PORT`. Watch parties dialed by that bundle
therefore still require the operator to **also** `ufw allow 8804/tcp`; that
completes the plain `ws://<host>:8804` form for HTTP-served pages (`8804` is
a plaintext listener, so an HTTPS-served page's `wss://` direct dial cannot
complete — the managed route is the only viable door there, and the portal
bundle reaches it only once the disclosed bundle-wiring rider lands).
No-domain managed installs and `--no-proxy` boxes are never seeded — there
the installer cannot name an origin clients will actually reach — and the
manual escape hatch stays theirs: hand-set `HUB_WS_URL=wss://<hub-domain>`
with `HUB_WS_PATH=/syncplay` in `/etc/phlix-hub.env` (both grammar-validated
per the env table below; the installer never overwrites a non-empty value)
and restart `phlix-hub`.
`--no-proxy` installs open `8804`/`8805` in ufw (when it is active), so the plain
`ws://<host>:8804` / `ws://<host>:8805` forms documented in
[`docs/websockets.md`](docs/websockets.md) are reachable there directly.

If you'd rather use your own reverse proxy (nginx, Caddy, Traefik, etc.) instead of the
managed HAProxy, pass `--no-proxy` to either install script. Each service then listens on its
own port (8800 / 8802 / 8803 / 8804 / 8805 for phlix-hub, 8096 for phlix-server) and you point
your proxy at those — replicate the same path prefixes the managed config uses (`/client/`,
`/syncplay/`, `/relay/federation/`) and see §7 for the WebSocket pass-through requirements.

Everything else is already namespaced (env files, systemd units, install dirs, service users,
MySQL DBs, backend ports, certbot artefacts) so there are no other co-install conflicts.

## Quick start (development)

```bash
git clone https://github.com/detain/phlix-hub.git
cd phlix-hub
composer install

# Point the Hub at a MySQL instance (see Configuration reference below).
export HUB_DB_HOST=127.0.0.1 HUB_DB_USER=phlix_hub HUB_DB_PASSWORD=phlix_hub HUB_DB_NAME=phlix_hub
export HUB_JWT_SECRET="$(openssl rand -hex 32)"

php scripts/run-migrations.php     # create the schema (idempotent)
php start.php start                # start the Hub (Ctrl-C to stop)

curl http://localhost:8800/health  # => {"status":"ok",...}
```

Then open <http://localhost:8800/signup> to create the first account — it is auto-promoted to
**admin**. On a public deployment make sure that first signup is *yours* before opening `:8800`
to the network, then lock registration down: `HUB_SIGNUPS_ENABLED=false` at boot, or flip the
live `auth.signups_disabled` hub setting under `/app/admin/settings` (no restart needed).
After signing in, `/my-servers` lists your servers and `/claim-server` walks through pairing a
new one.

> Run `php start.php start -d` to daemonize; `stop`, `restart`, `reload`, and `status`
> are also available.

### CLI (`bin/phlix`)

A small [`webman/console`](https://www.workerman.net/doc/webman/components/command.html) CLI
ships at `bin/phlix`:

```bash
php bin/phlix list         # list available commands (works with no database)
php bin/phlix migrate      # apply migrations/*.sql (idempotent; tracking table)
php bin/phlix smoke:jwt    # smoke-test the JWT create/validate round-trip

php bin/phlix oauth:client:register   # register an OAuth client (prints the one-time secret)
php bin/phlix oauth:client:list       # list registered clients and their status
php bin/phlix oauth:client:disable    # disable a client (add --revoke-tokens to cut live tokens)
```

`migrate` is the CLI equivalent of `php scripts/run-migrations.php`.

## Production install on Ubuntu

These steps target **Ubuntu 24.04**. On 22.04 the distro PHP is 8.1, which predates the Hub's 8.3 floor — the guidance below is to move to 24.04 (or newer) rather than pull in a third-party PHP build. Run as a sudo-capable user.

### 1. System packages

```bash
# PHP: use the version-agnostic php-* package names so apt installs the
# distro's current PHP. Ubuntu 24.04 ships PHP 8.3 by default, which meets
# the Hub's requirement.
sudo apt update
sudo apt install -y \
  php-cli php-mysql php-mbstring php-curl \
  php-xml php-bcmath php-gd php-zip \
  git unzip mysql-server

php -v   # confirm PHP 8.3 or newer

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

> `pcntl`, `posix`, and `sodium` ship with the `php-cli` package on Ubuntu — verify with
> `php -m | grep -E 'pcntl|posix|sodium'`. If your distro's default PHP is older than 8.3,
> upgrade to Ubuntu 24.04 (or newer) rather than pulling in a third-party PHP build.

### 2. MySQL: database, user, and grants

Secure the server first (sets the root password, removes anonymous users, etc.):

```bash
sudo mysql_secure_installation
```

Then create the database and a dedicated, least-privilege user. Open a root shell with
`sudo mysql` and run:

```sql
-- Database (utf8mb4 throughout)
CREATE DATABASE phlix_hub
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Dedicated user. The Hub connects over TCP to 127.0.0.1 by default, so the
-- user host must match. Use a strong, unique password.
CREATE USER 'phlix_hub'@'127.0.0.1' IDENTIFIED BY 'CHANGE-ME-strong-password';

-- Least privilege: only the rights the app needs on its own schema.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON phlix_hub.* TO 'phlix_hub'@'127.0.0.1';

FLUSH PRIVILEGES;
```

Notes:

- The migration runner issues `CREATE TABLE` / `ALTER TABLE`, so `CREATE`, `ALTER`, and `INDEX`
  are required in addition to the CRUD grants.
- If the Hub runs on a **different host** than MySQL, create the user for that host (or `'%'`)
  and set `HUB_DB_HOST` accordingly. Make sure MySQL's `bind-address` allows remote connections.
- MySQL distinguishes `'localhost'` (unix socket) from `'127.0.0.1'` (TCP). The Hub uses TCP, so
  grant to `'127.0.0.1'`. To also allow socket logins for manual `mysql` use, create a second
  `'phlix_hub'@'localhost'` user.

Verify the credentials work:

```bash
mysql -h 127.0.0.1 -u phlix_hub -p phlix_hub -e 'SELECT 1;'
```

### 3. Application code

```bash
sudo git clone https://github.com/detain/phlix-hub.git /opt/phlix-hub
cd /opt/phlix-hub
sudo composer install --no-dev --optimize-autoloader
sudo mkdir -p .logs
sudo chown -R www-data:www-data /opt/phlix-hub
```

### 4. Environment configuration

The Hub is configured entirely through environment variables (see the
[reference](#configuration-reference)). Create an env file the service will load:

```bash
sudo tee /etc/phlix-hub.env >/dev/null <<EOF
HUB_HOST=0.0.0.0
HUB_PORT=8800
HUB_WORKERS=4
HUB_PUBLIC_DOMAIN=hub.example.com

HUB_DB_HOST=127.0.0.1
HUB_DB_PORT=3306
HUB_DB_USER=phlix_hub
HUB_DB_PASSWORD=CHANGE-ME-strong-password
HUB_DB_NAME=phlix_hub

# REQUIRED in production: a real >=32-byte secret, generated as the file is
# written (the heredoc is deliberately UNQUOTED so $(...) expands once).
# Boot-time pre-flight refuses documented placeholder literals outright.
HUB_JWT_SECRET=$(openssl rand -hex 32)
EOF
sudo chmod 600 /etc/phlix-hub.env
```

> The `HUB_DB_PASSWORD` placeholder above still needs a real value matching the MySQL user
> you created in step 2 — the boot gate polices only `HUB_JWT_SECRET`.

`HUB_JWT_SECRET` is enforced before the worker fork (R14-08): a value on the placeholder denylist
(`CHANGE-ME-run-openssl-rand-hex-32`, `change-me`, `your-secret-here`, `replace-me`, … —
case/space-insensitive) aborts startup with `exit 1` rather than minting tokens under a
publicly-guessable key. An **unset** (or <32-byte) secret falls back to a random per-process key
only in development (`APP_ENV`/`HUB_ENV` = `dev|local`, or `HUB_JWT_ALLOW_DEV_SECRET=1`) — that
fallback invalidates every token on restart and differs per worker, so set a real secret in
production with `openssl rand -hex 32` and keep it stable.

### 5. Run migrations

```bash
sudo -u www-data --preserve-env \
  env $(grep -v '^#' /etc/phlix-hub.env | xargs) \
  php /opt/phlix-hub/scripts/run-migrations.php
```

The runner records applied migrations in a `migrations` table and is **idempotent** — re-running
it after a successful apply is a no-op.

> **Editing an already-applied migration.** The `migrations` ledger also stores a
> comment-normalized checksum of each applied file (a `checksum` column added by migration
> `041_migrations_checksum`). If you hand-edit the SQL of a migration that has **already run** (a
> "rewrite-class" migration), the next `run-migrations.php` / `bin/phlix migrate` detects the
> changed content, logs a **WARNING** (via `error_log()`, visible in the deploy output / CLI
> stderr), **safely re-applies** the file, and refreshes its stored checksum — instead of silently
> skipping it forever on filename alone. Header/comment-only edits do **not** trigger a re-apply
> (comments are stripped before hashing). Migrations that already existed before the checksum column
> was introduced are backfilled without re-running, so upgrading never mass-re-applies your existing
> schema. Because a re-applied file executes again, keep migrations re-run-safe (`CREATE TABLE IF NOT
> EXISTS`, additive/idempotent DDL) — the same contract that already governs writing migrations.

### 6. Run as a systemd service

`scripts/install.sh` is the **canonical generator** of the unit file — it writes the
full hardened definition below (service user, port gate, graceful reload, resource
limits, sandbox). If you install by hand, mirror it; the inline copy here is kept
current with the template in `scripts/install.sh` (step 6, "systemd service").

```bash
sudo tee /etc/systemd/system/phlix-hub.service >/dev/null <<'EOF'
[Unit]
Description=Phlix Hub
Documentation=https://docs.phlix.media
After=network.target mysql.service
Wants=mysql.service
StartLimitIntervalSec=500
# Burst of 10 (not 5): with the ExecStartPre port gate below each failed start
# attempt takes up to ~93 s, so 5 failures would fit inside a 500 s window and
# trip start-limit-hit — leaving the hub down until a manual reset-failed.
StartLimitBurst=10

[Service]
Type=simple
User=phlix-hub
Group=phlix-hub
WorkingDirectory=/opt/phlix-hub
EnvironmentFile=/etc/phlix-hub.env
# Swoole's RemoteObject bridge writes a lock to $HOME/.swoole; the system user
# has no home, so point HOME at a writable path inside the install root.
Environment="HOME=/opt/phlix-hub/var"
# Cross-origin desktop access (optional; M-HUB-09): the Windows client's
# hub-mode renderer runs the same UI bundle at origin app://- against this
# hub's https base, so its REST calls are cross-origin for the browser
# engine. Unset, the hub denies all CORS (the shipped default); uncomment to
# grant the desktop origin. The position law applies — this opens public
# control endpoints to preflight, not authed REST (authed routes answer the
# auth challenge first, before any CORS grant). See 'Security & posture
# envs' below and openapi.yaml 'Cross-origin (CORS)'.
#Environment="PHLIX_CORS_ALLOW_ORIGINS=app://-"
# Restart-race gate (2026-07-10 incident): the OLD master can hold its listen
# sockets for seconds-to-minutes after SIGTERM; without this wait the new
# master throws "Address already in use" and crash-loops. Waits up to 90 s for
# :8802/:8803/:8804/:8805/:2206 (the config/server.php defaults) to be free.
# If you move HUB_RELAY_PORT, HUB_CLIENT_RELAY_PORT, HUB_SYNCPLAY_PORT,
# HUB_FEDERATION_PORT or HUB_CHANNEL_PORT, update the gate's values to match.
ExecStartPre=/usr/bin/env bash /opt/phlix-hub/scripts/wait-for-free-ports.sh -t 90 8800 8802 8803 8804 8805 2206
ExecStart=/usr/bin/php /opt/phlix-hub/start.php start
# GRACEFUL reload: Workerman arms its SIGKILL timer only for SIGUSR1, so
# SIGUSR2 (Worker.php:1390/$gracefulStop) is the reload that does NOT cut live
# relay tunnels and in-flight HLS. POST /api/v1/admin/restart sends the same
# signal, so 'systemctl reload' and the in-app Restart button behave alike.
# (The pid file lives at /opt/phlix-hub/var/hub.pid — do NOT point
# HUB_PID_FILE at /var/run: the sandbox below cannot write there.)
ExecReload=/bin/kill -SIGUSR2 $MAINPID
ExecStop=/bin/kill -SIGTERM $MAINPID
Restart=on-failure
RestartSec=3s
TimeoutStopSec=30
# Must cover the up-to-90 s ExecStartPre port gate plus worker boot.
TimeoutStartSec=120
# Swoole 6's io_uring loop pins locked memory; the 8 MB RLIMIT_MEMLOCK default
# makes io_uring init fail with ENOMEM and workers intermittently drop conns.
LimitMEMLOCK=infinity
# systemd does NOT read /etc/security/limits.conf: this line is what the daemon
# gets. The relay surfaces run on count=1 workers — ONE process holds a socket
# per paired server AND per connected client — so the 1024 soft default stops
# accepting tunnels with "Too many open files" long before anything else binds.
LimitNOFILE=1048576
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phlix-hub
# Sandbox hardening. The install root stays READ-ONLY; every runtime write is
# pinned under ReadWritePaths: .logs/ (app/error/hub/relay/audit + workerman
# logs), var/ (Swoole HOME lock, Smarty compile/cache, the DI container compile
# dir, config/ needs write for the Ed25519 hub signing key — HubServicesProvider
# writes config/hub-signing-key.pem, mode 0600 — Workerman pid/status files).
# NOTE: MemoryDenyWriteExecute (breaks PHP JIT/opcache), PrivateDevices and
# SystemCallFilter (io_uring is syscall-sensitive) are deliberately NOT set.
# The optional DNS-alias feature (StaticZoneManager) defaults its zone dir
# under /home/phlix/data — ProtectHome hides it; enablement must repoint
# 'dns_zone_dir' into a path listed here.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/opt/phlix-hub/.logs /opt/phlix-hub/var /opt/phlix-hub/config
RestrictNamespaces=true
LockPersonality=true
RemoveIPC=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
ProtectHostname=true
ProtectClock=true
RestrictSUIDSGID=true
RestrictRealtime=true

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now phlix-hub
sudo systemctl status phlix-hub
curl http://localhost:8800/health
```

### 7. Reverse proxy & TLS

Terminate TLS at a reverse proxy in front of the Hub. The HTTP API (`8800`) plus the three
path-namespaced WebSocket surfaces need to be reachable through it: the client mount
(`8803`, `GET /client/{server_id}`), the SyncPlay relay (`8804`, `GET /syncplay/{server_id}`)
and — only when this hub advertises itself to other hubs as a federation master — the
federation ingress (`8805`, `GET /relay/federation/{hub_id}`). (The `8803` listener still
serves the rate-limited, authenticated 1011 refusal — mount traffic itself is declared
unavailable in this topology; see the worker table above.) Example nginx server block:

```nginx
server {
    listen 443 ssl;
    server_name hub.example.com;

    ssl_certificate     /etc/letsencrypt/live/hub.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/hub.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8800;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Belt-and-braces upgrade settings: :8800 itself serves no WebSocket
        # surface (the :8802 server tunnels are direct-connect per the pairing
        # law, and every proxied mount has its own leg below).
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
    }

    # Client mount (GET /client/{server_id}, WebSocket) — the leg the reachability
    # promise above makes, matching the managed HAProxy be_hub_client_relay route
    # (scripts/install.sh). X-Forwarded-For / X-Real-IP are what the mount reads
    # to key its per-IP rate limit behind a proxy. Honest posture: this mount is
    # DECLARED UNAVAILABLE in the deployed topology — after the auth ladder
    # (limiter -> relay token -> ownership) :8803 answers every upgrade with WS
    # close 1011 and logs client_mount_bridge_unavailable (docs/websockets.md).
    # Route it anyway so clients get that loud structured refusal, not a
    # proxy-level error. Live client-to-server traffic rides the HTTP relay
    # proxy (/api/v1/servers/{id}/proxy/*), already covered by `location /`.
    location /client/ {
        proxy_pass http://127.0.0.1:8803;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
    }

    # SyncPlay relay (watch-party clients). The path must reach the listener
    # unprefixed — its parser anchors on ^/syncplay/. Auth rides the
    # `Authorization: Bearer <token>` header or the `bearer, <token>` WebSocket
    # subprotocol, never the query string.
    location /syncplay/ {
        proxy_pass http://127.0.0.1:8804;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;  # long-lived rooms; the managed HAProxy carries timeout tunnel 1h
    }

    # Federation master ingress (leaf-hub dials). Authentication is the Ed25519
    # handshake ceremony, not TLS — but wss:// stays the production shape.
    location /relay/federation/ {
        proxy_pass http://127.0.0.1:8805;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
    }
}
```

If you allocate per-server subdomains, point a **wildcard** record (`*.hub.example.com`) at the
Hub and add a matching wildcard TLS certificate.

> **TLS for server subdomains:** automated ACME (Let's Encrypt) provisioning is **not** built in.
> Provision certificates out-of-band (e.g. a wildcard cert via certbot DNS-01) and point the Hub
> at them.

## Docker

A `Dockerfile` is provided. The image builds on the shared base
(`ghcr.io/detain/phlix-base`: PHP 8.3 + Swoole + php-uv) and runs the Workerman daemon
(`start.php`) as PID 1 — **no nginx, no php-fpm, no supervisord**. The entrypoint
(`docker/docker-entrypoint.sh`) applies pending migrations at boot, then `exec`s the daemon;
the image `EXPOSE`s `8800 8802 8803 8804 8805` and its healthcheck probes `/health`.

```bash
docker build -t phlix-hub .

# Export real values first — the shell below refuses to start the container with them unset,
# and placeholder JWT literals are refused at boot anyway.
export HUB_DB_PASSWORD='…matching a real MySQL user…'

docker run -d --name phlix-hub \
  -p 8800:8800 -p 8802:8802 -p 8803:8803 -p 8804:8804 -p 8805:8805 \
  -e HUB_DB_HOST=host.docker.internal \
  -e HUB_DB_USER=phlix_hub \
  -e HUB_DB_PASSWORD="${HUB_DB_PASSWORD:?export HUB_DB_PASSWORD before running}" \
  -e HUB_DB_NAME=phlix_hub \
  -v phlix-hub-config:/var/phlix/config \
  phlix-hub
```

`HUB_JWT_SECRET` may be passed explicitly (`-e HUB_JWT_SECRET="$(openssl rand -hex 32)"`) or
left unset: the entrypoint then generates one key **once** and persists it to
`/var/phlix/config/hub_jwt_secret` — mount that path as a volume (as above) so sessions survive
container re-creation. An ephemeral generated key without the volume invalidates all tokens on
every restart.

Migrations run automatically from the entrypoint on every boot (look for the
`PHLIX-HUB-MIGRATIONS-OK` banner); set `HUB_MIGRATIONS_STRICT=1` to refuse booting on a
half-migrated schema instead of the start-anyway default. A manual idempotent re-run stays
available:

```bash
docker exec phlix-hub php /var/www/html/scripts/run-migrations.php
```

Point `HUB_DB_HOST` at a reachable MySQL instance (a linked container, `host.docker.internal`, or
an external host).

**Logs.** The application writes rotating files under `.logs/` inside the container —
`app.log`, `error.log`, `hub.log`, `relay.log`, `audit.log` (5 channels, 30 rotations each;
see [`config/logger.php`](config/logger.php)) — **not** stdout. `docker logs phlix-hub`
therefore shows only the entrypoint banners and Workerman's own console output
(Workerman's internal log file is the sixth neighbor there, `HUB_WORKERMAN_LOG`,
default `.logs/workerman.log`). To follow the application:

```bash
docker exec phlix-hub tail -f /var/www/html/.logs/app.log
```

`.logs/` lives in the image's workdir (`/var/www/html`), so it is replaced on every
container re-creation — mount a volume (`-v phlix-hub-logs:/var/www/html/.logs`) to
retain logs across upgrades.

## Configuration reference

All settings are environment variables, read in [`config/`](config). Defaults shown are the
development fallbacks.

### Server ([`config/server.php`](config/server.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_HOST` | `0.0.0.0` | HTTP bind address |
| `HUB_PORT` | `8800` | HTTP listen port |
| `HUB_WORKERS` | `2` | Number of HTTP worker processes |
| `HUB_WORKERMAN_LOG` | `.logs/workerman.log` | Workerman's own log file |
| `HUB_PUBLIC_DOMAIN` | `phlix.media` | Base domain for per-server subdomains |
| `HUB_BASE_URL` | `https://<HUB_PUBLIC_DOMAIN>`, else `http://localhost:<HUB_PORT>` | Public base URL baked into enrollment JWTs and the JWKS URL — must be reachable **by enrolled servers**, not just in-process |
| `HUB_RELAY_PORT` | `8802` | Server-tunnel WS listen port (`RelayWorker`) |
| `HUB_CLIENT_RELAY_PORT` | `8803` | Client-mount WS listen port (`ClientRelayWorker`) |
| `HUB_SYNCPLAY_PORT` | `8804` | SyncPlay relay listen port (`SyncPlayRelayWorker`) |
| `HUB_FEDERATION_PORT` | `8805` | Hub-to-hub federation listen port (`FederationWorker`) |
| `HUB_CHANNEL_PORT` | `2206` | Cross-process channel broker (relay RPC between HTTP workers and the count=1 relay) |
| `HUB_MAX_SERVERS_PER_USER` | `0` | Per-user server-claim quota (`0` = unlimited); live twin of the `server.max_servers_per_user` hub setting |
| `HUB_MAX_USERS_PER_SERVER` | `0` | Per-server collaborator cap (`0` = unlimited); live twin of `server.max_users_per_server` |
| `HUB_ENROLLMENT_TTL` | `604800` | Enrollment-JWT lifetime (seconds); effective value re-resolved per mint (hub-setting override wins) |
| `HUB_AUDIT_RETENTION_DAYS` | `365` | Days of `audit_logs` the maintenance reaper retains (R19-11). Deliberately env-only, **not** a `hub_settings` key — an operator dial, not an admin-UI one. Must be ≥ 1: non-positive or oversized values are clamped back to `365` with a logged warning — a `0` here can never arm a wipe-everything sweep |
| `HUB_MCP_PLAYBACK_CONTROL` | `false` | Registers the `playback_control` MCP tool — the only MCP tool that mutates server state. **Read the S261 review warning in [`config/server.php`](config/server.php) before enabling** (retroactively activates the scope on pre-existing tokens) |
| `HUB_MCP_SSE_KEEPALIVE` / `HUB_MCP_SSE_MAX_SECONDS` | `15` / `900` | `GET /mcp` SSE keep-alive interval and hard stream-lifetime ceiling |
| `HUB_RELAY_RECONNECT_DRAIN_GRACE` | `5.0` | Seconds an incumbent tunnel keeps draining in-flight requests after a validated server reconnect displaces it (`0` = hard displacement) |

> The six listen-port envs were wired through `config/server.php` in R14-15 — before that the
> auxiliary ports existed only as `?? <default>` fallbacks no config set (configurability
> theater). Defaults are byte-identical to the previous literals, so behaviour with the envs
> unset is unchanged.

#### Security & posture envs

Verified against `config/` and the consuming classes; each default preserves shipped behaviour.
Where a live `hub_settings` twin exists (admin-console override), it is named. Exactly two
twin'd keys carry `restart: true` in the vendored schema (`@phlix/shared` `hub-settings.schema.json`):
`server.metrics.enabled` and `server.rate_limit` — for those, the admin-console value is read at
boot per worker, so changing it needs a restart to take effect (all other twins are live-per-request).
The same law cuts the other way for rate limiting: `server.rate_limit` (a JSON override) merges over
the `PHLIX_HUB_RATELIMIT_*` envs below, so when it is set the env defaults are NOT the whole truth.

| Variable | Default | What it does / unblocks |
|----------|---------|-------------------------|
| `HUB_SIGNUPS_ENABLED` | `true` | `false`/`0`/`no`/`off` closes self-service registration: `POST /api/v1/auth/{register,signup}` answers **403** `auth.signups_disabled` before any hashing work. Live twin: `auth.signups_disabled` |
| `HUB_FEDERATION_ENABLED` | `true` | `false` stops new federation dials, drops inbound DATA frames pre-verification, and rejects controller mutators with **409** `provider.not_configured`. The `:8805` listener stays bound until restart (pre-fork socket). Live twin: `federation.enabled` |
| `HUB_MAINTENANCE_MODE` | `false` | `true` answers **503** `provider_unavailable` for API traffic. Exempt by design: `/health`, `/api/v1/auth/*`, hub-settings + admin-settings GET/PUT (the off-switch), `/api/v1/admin/restart`; the SPA shell and hashed assets still serve. Live twin: `hub.maintenance_mode` |
| `HUB_RELAY_TLS` | `false` | Terminates TLS on the `:8802` server-tunnel listener (`wss://`) — what you must enable to pair servers over untrusted networks. Needs `HUB_RELAY_TLS_CERT` / `HUB_RELAY_TLS_KEY` (both default `null`) |
| `HUB_RELAY_TLS_CERT` / `HUB_RELAY_TLS_KEY` | `null` | PEM paths for the `:8802` TLS context above |
| `HUB_RELAY_REQUIRE_TLS` | `false` | Fail-closed `:8802` bind mandate — the listen-side mirror of the server's `PHLIX_RELAY_REQUIRE_TLS` (R17-01): `true` makes the relay worker REFUSE to boot when TLS is off, instead of binding a cleartext listener that carries enrollment JWTs. `false` = warn-only — a plaintext bind logs one loud `SECURITY` advisory on the relay channel at boot |
| `HUB_FEDERATION_TLS` | `false` | Terminates TLS natively on the `:8805` hub-to-hub federation listener (`wss://`) — for masters reached WITHOUT a TLS-terminating upstream proxy. Needs `HUB_FEDERATION_TLS_CERT` / `HUB_FEDERATION_TLS_KEY` (both default `null`). A plaintext bind logs one loud `SECURITY` advisory on the relay channel at boot (M22) |
| `HUB_FEDERATION_TLS_CERT` / `HUB_FEDERATION_TLS_KEY` | `null` | PEM paths for the `:8805` TLS context above |
| `HUB_FEDERATION_REQUIRE_TLS` | `false` | Fail-closed `:8805` bind mandate, the federation sibling of `HUB_RELAY_REQUIRE_TLS` above: `true` makes `FederationWorker::start()` throw before constructing the listener when TLS is off — no cleartext federation socket is ever bound (federation inbound stays down, the hub serves the rest). `false` = warn-only. Keep unset on managed installs, where HAProxy fronts `:8805` and terminates TLS upstream |
| `HUB_COOKIE_INSECURE` | unset | Drops `Secure` from session cookies — **local plain-HTTP development only** |
| `PHLIX_CORS_ALLOW_ORIGINS` | unset — **deny-all** | Cross-origin allowlist for the hub's REST HTTP surface (`src/Http/CorsPolicy.php`, read once at router construction — boot-scoped, changes need a hub restart): comma-separated **exact lowercase origins** (`PHLIX_CORS_ALLOW_ORIGINS=https://hub.example.com,app://-`); a malformed entry — wildcard, uppercase, path, whitespace — fails LOUD at boot instead of silently denying at runtime. Allowlisted origins earn the `OPTIONS` 204 preflight and an `Access-Control-Allow-Origin` echo on actual replies; `Access-Control-Allow-Credentials` is never emitted (hub REST is bearer-auth, and a cross-origin cookie ride is exactly what this posture refuses). A disallowed-origin preflight is byte-identical to an unknown-path 404 — a probe cannot map the route table. `app://-` is the origin the desktop (Electron Windows client) hub-mode renderer presents — add it when the hub serves packaged desktop clients. Honest scope limit (the position law, M-HUB-09 r2/r3): `OPTIONS` on AUTHED routes runs the auth chain before any CORS grant, so this knob opens cross-origin reach for the **public** control endpoints (health, auth, server-claims trio); authed desktop hub-mode REST deliberately stays beyond browser-CORS reach there — its live cross-origin work rides the CORS-exempt relay WebSockets (`:8802`–`:8804`). The same-origin `/app` portal needs nothing: same-origin fetches never preflight. Full policy text in `openapi.yaml` §"Cross-origin (CORS)" |
| `HUB_WS_HOST` | falls back to `HUB_PUBLIC_DOMAIN`, then `your-hub-host` | Host advertised in the `X-WS-Endpoint` steer when a plain HTTP GET hits the `:8802`/`:8803` WebSocket mounts — host only: the steer's `ws://` scheme and default port are fixed, not proxy/port-aware |
| `HUB_REQUESTS_AUTO_APPROVE` | `false` | Auto-approves freshly filed media requests (best-effort, after the `pending` row lands). Live twin: `requests.auto_approve` |
| `HUB_INVITE_DEFAULT_EXPIRY` | `604800` (7 days) | Default invite lifetime in seconds when the creator omits `expires_in`; `0` = never expire. An explicit body value always wins. Live twin: `invite.default_expiry_seconds` |

#### SPA shell hints (`window.__PHLIX__`)

The `/app` shell injects a small bootstrap payload (R12-02). Both hints default to `null`,
which keeps the SPA's legacy **same-origin** derivation of its WebSocket endpoints — set them
only behind a reverse proxy or port map that breaks same-origin:

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_WS_URL` | unset → `null`; seeded `ws(s)://<domain>` by `install.sh` on domain-bound managed installs | Explicit WS base origin (`ws(s)://host[:port]`, **no path**). Grammar-validated; an invalid value fails soft to `null` |
| `HUB_WS_PATH` | unset → `null`; seeded `/syncplay` there | Single absolute socket path (e.g. `/syncplay`). Grammar-validated; invalid → `null` |

#### Runtime files & container cache

| Variable | Default | What it does |
|----------|---------|--------------|
| `HUB_PID_FILE` | `var/hub.pid` (under the project root) | Workerman master PID file. `start.php` assigns it to `Worker::$pidFile`; the hardened systemd unit keeps it writable via `ReadWritePaths` — do NOT point it at `/var/run` (the sandbox cannot write there and `POST /api/v1/admin/restart` would 500) |
| `HUB_STATUS_FILE` | `var/hub.status` (under the project root) | Declared in `config/server.php` (`$envStr('HUB_STATUS_FILE', …)`) for Workerman's status-snapshot convention; **no consumer in `src/` today** — honest census entry, not a live dial |
| `HUB_MAINTENANCE_HEARTBEAT_FILE` | `var/maintenance-heartbeat.json` | S312 liveness record of the SEPARATE-FORK maintenance worker: written from inside the guarded sweep callback, read by `/health` — a crash-looping sweeper stops reporting `healthy` within `HUB_MAINTENANCE_STALE_SECONDS`. Must stay on a filesystem shared between the forks (not a private tmpfs) |
| `HUB_MAINTENANCE_STALE_SECONDS` | `180` | Seconds without a COMPLETED maintenance sweep before `/health` calls the maintenance worker down (default = 3× `config/process.php`'s `maintenance.poll_seconds`) |
| `PHLIX_HUB_CONTAINER_COMPILE` | unset (off) | Truthy (`1`/`true`/`yes`/`on`) enables PHP-DI's compiled-container cache (`src/Common/Container/ContainerFactory.php::shouldCompile()`), writing to `var/cache` — a production startup-speed dial; leave off in development so definition edits take effect immediately |

#### Rate limiting ([`config/server.php`](config/server.php) `rate_limit`)

Each abuse-prone surface has its own rate limiter (a single login-grade limiter is wrong for
everything but login). Sixteen profiles exist in three custody postures: **`login`, `mcp`, and
`signup` are shared and DB-backed**; eight HTTP surfaces (`proxy`, `heartbeat`, `jwks`, `alexa`,
`server_claims`, `invite_redeem`, `client_relay_token`, `client_request_create`) use per-worker in-memory limiters (soft-global — see the caveat
below); the five `count=1`-worker surfaces (`relay_connect`, `client_mount`,
`syncplay_mount`, `federation_connect`, `federation_frame`) are per-worker too,
but per-worker *is* global there. Every threshold is
env-overridable; absent keys fall back to the defaults below. HTTP surfaces that trip return
**429** with a `Retry-After` header and `{"error":"Too Many Requests","code":"rate_limited"}`; the
WebSocket handshakes (:8802/:8803/:8804/:8805) reject with WS close code **1013** (Try Again Later) instead.

| Variable | Default | Surface / key |
|----------|---------|---------------|
| `PHLIX_HUB_RATELIMIT_CAP` | `10000` | Max distinct keys tracked per limiter (memory ceiling) |
| `PHLIX_HUB_RATELIMIT_LOGIN_MAX` / `_LOGIN_WINDOW` | `5` / `900` | Login attempts, keyed by identity — **DB-backed, global** |
| `PHLIX_HUB_RATELIMIT_MCP_MAX` / `_MCP_WINDOW` | `10` / `900` | MCP personal-access-token auth failures, keyed `mcp:auth:*` — **DB-backed, global** |
| `PHLIX_HUB_RATELIMIT_SIGNUP_MAX` / `_SIGNUP_WINDOW` | `3` / `3600` | Account signup attempts, keyed `auth:signup:<ip>` — **DB-backed, global**, counts successes too |
| `PHLIX_HUB_RATELIMIT_PROXY_MAX` / `_PROXY_WINDOW` | `600` / `60` | Relay proxy, keyed `proxy:{userId}` (hit after the auth gate; generous for HLS bursts) |
| `PHLIX_HUB_RATELIMIT_HEARTBEAT_MAX` / `_HEARTBEAT_WINDOW` | `30` / `60` | Server heartbeat, keyed `heartbeat:{serverId}` (after JWT validation) |
| `PHLIX_HUB_RATELIMIT_JWKS_MAX` / `_JWKS_WINDOW` | `120` / `60` | `/.well-known/jwks.json`, keyed `jwks:{ip}` |
| `PHLIX_HUB_RATELIMIT_ALEXA_MAX` / `_ALEXA_WINDOW` | `60` / `60` | Alexa skill endpoint, keyed `alexa:<trusted ip>` (flood-cost cap only — Amazon's RSA signature is the credential) |
| `PHLIX_HUB_RATELIMIT_SERVER_CLAIMS_MAX` / `_SERVER_CLAIMS_WINDOW` | `60` / `60` | Server-claim pairing trio, keyed `server_claims:{new,status,claim}:<trusted ip>` |
| `PHLIX_HUB_RATELIMIT_INVITE_REDEEM_MAX` / `_INVITE_REDEEM_WINDOW` | `20` / `60` | Invite-link redemption, keyed `invite_redeem:{userId}` (attributable member; bounds the burn+share write chain) |
| `PHLIX_HUB_RATELIMIT_CLIENT_RELAY_TOKEN_MAX` / `_CLIENT_RELAY_TOKEN_WINDOW` | `10` / `60` | Client relay-token mint (`POST /me/servers/{id}/relay-token`), keyed `client_relay_token:{userId}` — attributable member; with the service-side cap of 20 active tokens per (user, server) scope this bounds both mint rate and standing credentials (M-HUB-22) |
| `PHLIX_HUB_RATELIMIT_CLIENT_REQUEST_CREATE_MAX` / `_CLIENT_REQUEST_CREATE_WINDOW` | `20` / `60` | Media-request creation (`POST /me/requests`), keyed `client_request_create:{userId}` — attributable member; paired with the pending dedup echo and the 50-pending standing cap on the member's queue (M-HUB-23) |
| `PHLIX_HUB_RATELIMIT_RELAY_CONNECT_MAX` / `_RELAY_CONNECT_WINDOW` | `10` / `60` | :8802 server relay-connect (WS), keyed by IP — `count=1` worker, truly global |
| `PHLIX_HUB_RATELIMIT_CLIENT_MOUNT_MAX` / `_CLIENT_MOUNT_WINDOW` | `30` / `60` | :8803 client-mount (WS), keyed by IP — `count=1` worker, truly global |
| `PHLIX_HUB_RATELIMIT_SYNCPLAY_MOUNT_MAX` / `_SYNCPLAY_MOUNT_WINDOW` | `30` / `60` | :8804 SyncPlay pre-auth connect (WS), keyed `syncplay_mount:<real ip>` — `count=1` worker, truly global; runs before the token lookup so a flood can't convert into DB work |
| `PHLIX_HUB_RATELIMIT_FEDERATION_CONNECT_MAX` / `_FEDERATION_CONNECT_WINDOW` | `10` / `60` | :8805 inbound federation connects (master role), keyed `federation_connect:<trusted ip>` — `count=1` worker, truly global |
| `PHLIX_HUB_RATELIMIT_FEDERATION_FRAME_MAX` / `_FEDERATION_FRAME_WINDOW` | `120` / `60` | :8805 federation frames, both roles, keyed `federation_frame:<remote peer hub id>` — `count=1` worker, truly global |
| `TRUSTED_PROXIES` | `127.0.0.0/8,::1/128` | Trusted proxy IPs/CIDRs used to resolve the real client address for the IP-keyed limiters (`src/Common/Http/TrustedProxyResolver.php`) — a forgeable leftmost `X-Forwarded-For` is never trusted |
| `PHLIX_FEDERATION_CA_BUNDLE` | unset | TLS trust-anchor selection for federation **dials** (this hub as leaf connecting to an `https://`/`wss://` master): absolute path to a PEM bundle that replaces the system store as verification anchor. Set it when the master serves a private-CA certificate; an unreadable path fails LOUD at dial (never a silent system-store fallback). `src/Federation/FederationPeerManager.php` (`buildMasterDialPlan`) — see also `docs/websockets.md`

> **Per-worker vs global caveat.** Most thresholds are enforced **per worker process**. The
> :8802/:8803/:8805 relay and federation workers are `count=1`, so per-worker == global for
> `relay_connect` / `client_mount` / `federation_connect` / `federation_frame`. `proxy`,
> `heartbeat`, `jwks`, `alexa`, `server_claims`, `invite_redeem`, `client_relay_token`, and `client_request_create` run across `HUB_WORKERS`
> HTTP workers, so their effective soft-global limit is roughly `max × HUB_WORKERS` — a
> documented, accepted tradeoff (a strict global cap for these would require a shared store,
> Redis/DB, and is future work).
>
> **`login`, `mcp`, and `signup` are the exceptions — they are genuinely global.** Their buckets
> are backed by the shared `login_rate_limit` DB table (migration `040_login_rate_limit`), so ALL
> HTTP workers share one counter per key and the **5/900, 10/900, and 3/3600 budgets are actually
> 5/900, 10/900, and 3/3600** — not the `~max × HUB_WORKERS` shape (≈20/900 with 4 workers, first
> 429 near attempt ~9) login had before HB-4.6 "Option B" moved credential-guessing surfaces onto
> the shared store.

> **Inbound body cap (M-HUB-30).** Every HTTP connection to the hub is bounded at **1 MiB**
> (`Application::MAX_REQUEST_BODY_BYTES`): the per-connection `maxPackageSize` is set in the
> HTTP worker's `onConnect` (the vendored Workerman default of 10 MB would let a probe make
> the hub materialize 10 MB per request on PUBLIC claim endpoints), and the dispatch boundary
> answers an over-cap body with **413** and the registered `invalid_payload` code — before the
> maintenance gate and before any route handler reads it. Relay (:8802/:8803), SyncPlay (:8804)
> and federation (:8805) frames carry their own smaller documented bounds (`docs/websockets.md`).

### Metrics ([`config/metrics.php`](config/metrics.php))

The relay-side metrics accumulators and their MySQL rollups. These knobs tune COLLECTION;
what they feed is the admin-gated REST surface `GET /api/v1/admin/metrics/`
(`snapshot` / `history` / `connections` / `routes` — see `src/Application.php`,
`registerMetricsRoutes()`) — **not** a Prometheus scrape endpoint. The `enabled` flag has a
live twin in the vendored settings schema: `server.metrics.enabled` (`restart: true` — read
at boot per worker).

| Variable | Default | What it does |
|----------|---------|--------------|
| `PHLIX_HUB_METRICS_ENABLED` | `true` | Master on/off — `false` no-ops every collector call (zero per-request overhead) |
| `PHLIX_HUB_METRICS_FLUSH_INTERVAL` | `5` | Seconds between the relay worker's in-memory→MySQL flushes |
| `PHLIX_HUB_METRICS_BUCKET_SECONDS` | `10` | Time-bucket granularity for rollup rows (aligned to `floor(ts / N) * N`) |
| `PHLIX_HUB_METRICS_RETENTION_DAYS` | `7` | Days of rollup rows the flush service retains before pruning |
| `PHLIX_HUB_METRICS_CONNECTION_TTL` | `15` | Seconds an untouched connection row is kept before pruning (live-connection view freshness) |
| `PHLIX_HUB_METRICS_ROUTE_CAP` | `200` | Max distinct (method, route) pairs tracked per bucket; further routes fold into one `__other__` bucket so a high-cardinality URL space cannot blow up memory |

### Database ([`config/database.php`](config/database.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_DB_HOST` | `127.0.0.1` | MySQL host |
| `HUB_DB_PORT` | `3306` | MySQL port |
| `HUB_DB_USER` | `phlix_hub` | MySQL user |
| `HUB_DB_PASSWORD` | `phlix_hub` | MySQL password |
| `HUB_DB_NAME` | `phlix_hub` | MySQL database name |
| `DB_POOL_ENABLED` | `1` | Coroutine connection pool (each coroutine leases its own connection). `0` falls back to the single mutex-serialised socket |
| `DB_POOL_SIZE` | `8` | Pool size per connection name **per worker** — keep `workers × connection names × DB_POOL_SIZE` under MySQL `max_connections` |

### Auth ([`config/auth.php`](config/auth.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_JWT_SECRET` | _(none in prod)_ | **Required in production.** ≥32-byte HMAC secret. A denylisted placeholder literal is refused at boot with `exit 1` (R14-08 pre-flight, folded into `resolveSecret()` so console commands and workers enforce it too); unset = hard boot refusal (`MissingJwtSecretException`) outside dev, random per-process fallback inside dev (`APP_ENV`/`HUB_ENV` `dev|local` or `HUB_JWT_ALLOW_DEV_SECRET=1`). The Docker entrypoint generates + persists one to `/var/phlix/config/hub_jwt_secret` when unset |
| `HUB_JWT_ACCESS_TTL` | `3600` | Access-token lifetime (seconds) |
| `HUB_JWT_REFRESH_TTL` | `604800` | Refresh-token lifetime (seconds) |

### Sonarr / Radarr (optional, for media requests — [`config/server.php`](config/server.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_SONARR_URL` | `http://localhost:8989` | Sonarr base URL |
| `HUB_SONARR_API_KEY` | _(empty)_ | Sonarr API key |
| `HUB_SONARR_ENABLED` | `0` | Enable Sonarr fulfilment |
| `HUB_RADARR_URL` | `http://localhost:7878` | Radarr base URL |
| `HUB_RADARR_API_KEY` | _(empty)_ | Radarr API key |
| `HUB_RADARR_ENABLED` | `0` | Enable Radarr fulfilment |

## Database schema

Migrations live in [`migrations/`](migrations) and are applied in filename order.
The schema below is the complete inventory: the 35 tables the migrations create,
grouped by subsystem, plus the `migrations` tracking table the runner creates
for itself.

| Table | Purpose |
|-------|---------|
| **Accounts and authentication** | |
| `users` | Hub accounts (Argon2id passwords; unique email + username) |
| `invite_links` | Single-use signed invite links |
| `login_rate_limit` | Shared, cross-worker login rate-limit buckets (one row per key; the only DB-backed limiter) |
| `auth_signup_guard` | First-admin election mutex — a single-row INSERT serialises concurrent registrations, so exactly one first user can ever self-elect |
| `auth_revoked_refresh_tokens` | Revocation registry: logouts record the `jti` of a presented refresh JWT, and token refresh refuses anything recorded here |
| `mcp_tokens` | Personal Access Tokens for the MCP endpoint (`POST /mcp`) — user-scoped, space-delimited scopes, SHA-256 hash only at rest |
| `oauth_clients` | Long-lived registry of OAuth 2.0 clients using the hub as authorization server (operator-managed) |
| `oauth_consent_requests` | ~10-minute, single-use consent requests, consumed by the consent POST |
| `oauth_authorization_codes` | ~60-second, single-use authorization codes, consumed by the token endpoint |
| `oauth_tokens` | Access/refresh grants (1 hour / 30 days) — revocable, rotated on refresh |
| **Servers and libraries** | |
| `servers` | Claimed media servers and their operational state (status, JWKS, hostname candidates) |
| `server_claims` | Pending/paired claim codes minted during pairing |
| `server_heartbeats` | Recent heartbeats for liveness and clock-skew detection |
| `server_libraries` | Libraries reported by servers via heartbeat |
| `server_library_hashes` | SHA-256 over each server's canonical library list, so heartbeats skip redundant library re-upserts when the list is unchanged |
| `shared_libraries` | Library grants from a server owner to another user |
| `library_shares` | Per-library shares with read-only / read-write levels |
| `dns_challenges` | DNS-01 challenge records for subdomain TLS |
| **Relay** | |
| `relay_sessions` | One row per open WebSocket relay session |
| `relay_user_quotas` | Per-user relay byte usage + download/upload caps + `max_concurrent_streams` |
| `relay_user_settings` | Durable per-user relay settings — the operator-configured bandwidth throttle (`throttle_bps`), which survives the monthly quota rollover |
| `client_relay_tokens` | Short-lived per-user, server-scoped relay bearer tokens — SHA-256 hash only at rest; the plaintext is returned exactly once at mint |
| **Content requests** | |
| `requests` | Jellyseerr-class request queue (pending → approved → available, or pending → rejected; approvals dispatch to Sonarr/Radarr). Note: the migration file is named `011_media_requests.sql` — the table it creates is `requests` |
| **Federation** | |
| `federation_hubs` | This hub's own identity and role (self-referential — exactly one row) |
| `federation_peers` | Known peer hubs: URL, Ed25519 key, relay / admin-delegation flags, connection status — the table the `:8805` handshake in [`docs/websockets.md`](docs/websockets.md) requires a dialer to already appear in |
| `federation_sessions` | Per-peer link sessions (heartbeat stamps, byte counters) |
| `federation_library_shares` | Outbound library shares to peers (read / readwrite; pending / active / revoked) |
| `federation_incoming_share_offers` | Share offers received from a peer hub, awaiting accept or reject |
| `federation_admin_delegations` | Users acting with delegated admin authority on behalf of a peer hub |
| **Telemetry, webhooks, settings** | |
| `metrics_rollup` | Time-bucketed hub metrics incl. relay gauges/counters (`relay_pending_requests`, `relay_reply_drops`, `relay_error_503`/`504`, `relay_cancels`, latency histogram) |
| `metrics_route_rollup` | Per-route traffic rollups from the same in-RAM flush |
| `metrics_connections` | Live snapshot of currently active relay connections |
| `webhooks` | User-defined HTTP callbacks for `phlix.*` event aliases |
| `hub_settings` | Hub-wide typed key/value configuration |
| `audit_logs` | Audit trail of administrative actions |
| **Runner** | |
| `migrations` | Applied-migration ledger (filename + checksum) — created and maintained by `bin/phlix migrate` itself, not by a `.sql` file |

## HTTP API

Selected endpoints (full surface in [`src/Application.php`](src/Application.php)). Protected
routes require a `Bearer` access token (or the session cookie, which also gates the legacy
page paths before they redirect to `/app`).

### Health & discovery

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/health` | Service + version JSON |
| `GET` | `/.well-known/jwks.json` | Public JWKS |

### Auth

| Method | Path | Notes |
|--------|------|-------|
| `POST` | `/api/v1/auth/register` | Create account (canonical; `/api/v1/auth/signup` is an alias) |
| `POST` | `/api/v1/auth/login` | Obtain access + refresh tokens |
| `POST` | `/api/v1/auth/refresh` | Exchange a refresh token |
| `POST` | `/api/v1/auth/logout` | Invalidate session |
| `GET` | `/api/v1/me`, `/api/v1/auth/me` | Current user, incl. `is_admin` (protected) |

### Servers

| Method | Path | Notes |
|--------|------|-------|
| `POST` | `/api/v1/server-claims/new` | Server mints a claim code |
| `POST` | `/api/v1/server-claims/claim` | User redeems a claim code (protected) |
| `GET` | `/api/v1/me/servers` | List your servers (protected) |
| `DELETE` | `/api/v1/me/servers/{id}` | Remove a server (protected) |
| `GET` | `/api/v1/me/servers/{id}/access-info` | Connection info (protected) |
| `POST` | `/api/v1/servers/{id}/heartbeat` | Server liveness (enrollment JWT) |
| `GET` | `/api/v1/servers/{id}/info` | Server metadata (enrollment JWT) |
| `POST`/`DELETE` | `/api/v1/servers/{id}/subdomain` | Allocate / revoke subdomain (enrollment JWT) |

### Sharing, invites & requests

| Method | Path | Notes |
|--------|------|-------|
| `POST`/`GET` | `/api/v1/me/shares` | Create / list library shares |
| `PATCH`/`DELETE` | `/api/v1/me/shares/{id}` | Update / delete a share |
| `POST`/`GET` | `/api/v1/me/invite-links` | Create / list invite links |
| `POST` | `/api/v1/me/invite-links/{token}/redeem` | Redeem an invite link and create a library share (protected) |
| `GET` | `/invite/{token}` | Accept an invite (public) — redirects to the SPA `/app/invite/{token}` acceptance page |
| `POST`/`GET` | `/api/v1/me/requests` | Create / list media requests |
| `GET` | `/api/v1/admin/requests` | Admin request queue |
| `POST` | `/api/v1/admin/requests/{id}/approve` | Approve a request |
| `POST` | `/api/v1/admin/requests/{id}/deny` | Deny a request |

### Admin (web console)

The Vue admin console at `/app/admin/*` is backed by these admin-gated endpoints
(`[AuthMiddleware, AdminMiddleware]` — 401 unauthenticated / 403 non-admin):

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/admin/dashboard/summary` | Server fleet (total/online/offline), active relay sessions, pending requests, user count |
| `GET` | `/api/v1/admin/dashboard/activity` | Recent audit events (`?limit=`) |
| `GET`/`POST` | `/api/v1/admin/users` | List / create accounts |
| `GET`/`PUT`/`DELETE` | `/api/v1/admin/users/{id}` | Fetch / update / delete an account |
| `POST` | `/api/v1/admin/users/{id}/set-admin` | Grant / revoke admin |
| `POST` | `/api/v1/admin/users/{id}/reset-password` | Set a new password |
| `GET` | `/api/v1/admin/logs`, `/logs/tail`, `/logs/tail-all` | Browse / tail the hub log files |
| `GET`/`PUT` | `/api/v1/admin/settings` | Read / persist hub settings |
| `GET` | `/api/v1/admin/users/{id}/bandwidth` | Read any user's current-period relay usage + caps (incl. `throttle_bps`) |
| `PUT` | `/api/v1/admin/users/{id}/quota` | Set a user's download/upload byte caps + concurrent-stream cap |
| `PUT` | `/api/v1/admin/users/{id}/throttle` | Set a user's durable relay bandwidth throttle (`throttle_bps`; `0` = Unlimited) |

The same logic is also reachable under `/api/v1/me/*` for back-compat (`/me/audit-logs`,
`/me/logs*`, `/me/hub-settings`, `/me/federation/*`).

### Bandwidth & quotas

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/me/bandwidth` | Read your own current-period relay usage + caps (protected) |

Per-user relay quotas cover monthly download (`quota_bytes_in`) and upload (`quota_bytes_out`)
byte caps plus a concurrent-stream cap (`max_concurrent_streams`); `0` means unlimited. Over a
byte cap the relay proxy refuses with **503** `quota.exceeded`; over the concurrent-stream cap it
returns **503** `stream.limit`. The concurrent counter is in-memory per HTTP worker (soft-global
≈ `max × HUB_WORKERS`).

Independent of the monthly byte caps, each user also has a **durable relay bandwidth throttle**
(`throttle_bps`, migration `043_relay_user_settings`) — a fixed-level rate cap (`0` = Unlimited, or
1/3/5/10/20/50 Mbps; default 3 Mbps) set via `PUT /api/v1/admin/users/{id}/throttle` and surfaced on
both `bandwidth` GETs. Unlike the period-scoped quota it does **not** reset each month. It is enforced
by a per-connection token bucket (`src/Relay/TokenBucket.php`) on both relay transports (native-client
WS relay and the browser HTTP-over-relay proxy via `src/Http/ConnectionResponseSink.php`). See
`docs/hub-admin/relay-tuning.md` in
[phlix-docs](https://github.com/detain/phlix-docs) for the full reference.

### MCP, OAuth & Alexa

| Method | Path | Notes |
|--------|------|-------|
| `POST` | `/mcp` | MCP JSON-RPC (PAT or OAuth `Bearer`) |
| `GET` | `/mcp` | MCP SSE stream (PAT or OAuth `Bearer`) |
| `GET`/`POST`/`DELETE` | `/api/v1/me/mcp-tokens`, `/api/v1/me/mcp-tokens/{id}` | Mint / list / revoke PATs (protected) |
| `GET`/`POST` | `/oauth/authorize` | Authorization + consent (hub session; PKCE `S256` required) |
| `POST` | `/oauth/token` | Code exchange / refresh (no hub session) |
| `GET` | `/oauth/userinfo` | Scoped resource surface (`OAuthResourceMiddleware`) |
| `POST` | `/alexa/skill` | Alexa skill endpoint (signature + certificate chain verified) |

### Relay (WebSocket)

Full protocol reference in [`docs/websockets.md`](docs/websockets.md).

| Endpoint | Port | Notes |
|----------|------|-------|
| Server tunnel | `8802` | Server opens its outbound tunnel here |
| `GET /client/{server_id}` | `8803` | Client mount — ⚠ **declared unavailable**: every authenticated mount is refused with close 1011 (`client_mount_bridge_unavailable`); live traffic rides the `:8800` HTTP relay proxy |
| `GET /syncplay/{server_id}` | `8804` | SyncPlay client joins a synchronised-playback session |
| Federation tunnel | `8805` | Peer hub connects for hub-to-hub shares |

## Connecting a media server

1. On the Hub, sign in and open **My Servers** (`/app/servers`) to start a claim, or the server
   requests a code via `POST /api/v1/server-claims/new`.
2. Enter the claim code on the server; the server is issued an **enrollment JWT** and registers
   its public key.
3. The server opens its **outbound relay tunnel** to the Hub and begins sending heartbeats.
4. The server now appears under **My Servers** (`/app/servers`), and remote clients can reach it
   through the Hub — no inbound ports required.

## Testing & quality

```bash
composer test     # PHPUnit (Unit + Integration suites)
composer cs       # PHP_CodeSniffer (PSR-12) via scripts/assert-phpcs-corpus.php
composer stan     # PHPStan (level 9)
composer psalm    # Psalm (errorLevel 1)

./vendor/bin/phpunit --testsuite E2E   # real MCP client SSE session (needs a booted hub + MySQL)
```

### Running the Integration suite (real MySQL)

`tests/Integration/**` talks to a real database and **self-skips** when the
`HUB_TEST_DB_*` variables are absent, so `composer test` on a box without MySQL
runs the unit suite only. Point them at a throwaway database — every test drops
and re-creates all tables and re-applies the whole `migrations/` chain, so never
aim them at a database you care about:

```bash
HUB_TEST_DB_HOST=127.0.0.1 HUB_TEST_DB_PORT=3306 \
HUB_TEST_DB_USER=root HUB_TEST_DB_PASSWORD=root HUB_TEST_DB_NAME=phlix_hub_test \
  ./vendor/bin/phpunit --testsuite Integration
```

The named database must already exist (the tests create tables, not schemas).
Expect roughly 15 s per test: each one re-applies all 35 migrations.

CI runs on every push and pull request via [GitHub Actions](.github/workflows/ci.yml) —
**twelve jobs** — and the image pair ships in a second workflow
([docker.yml](.github/workflows/docker.yml)). The full set, honestly enumerated (M-HUB-20:
this list previously claimed eight checks and omitted four jobs, the whole Docker workflow,
and the nightly trigger):

- **Composer Validation** (`composer-validate`) — `composer validate --strict`.
- **OpenAPI Description** (`openapi`, S66) — Redocly (version-pinned) lints `openapi.yaml`
  for structural validity only; whether the spec still *matches the route table* is a
  different question, answered by `OpenApiSpecMatchesRouterTest` inside the phpunit job.
- **Server Route Snapshot Currency** (`snapshot-currency`) — the vendored route snapshot's
  `source_sha` must still resolve against phlix-server master
  (`scripts/check-route-snapshot-currency.php`).
- **PHP CodeSniffer (PSR-12)** (`phpcs`) — asserts every security-path PHP extension is
  loaded (S258), then runs PHPCS over the whole corpus with an asserted file count (S299).
- **PHPStan** (`phpstan`) — level 9.
- **Psalm** (`psalm`) — errorLevel 1.
- **PHPUnit** (`phpunit`) — provisions a `mysql:8.0` service, applies the migration chain
  to it, and runs **both** suites through the S458 parallel runner (coverage uploaded to
  Codecov). The suite output then faces the **S173 zero-skip gate** —
  `scripts/assert-integration-tests-ran.php` reads PHPUnit's JUnit report and fails the
  build if any test skipped without an explicit, justified `ALLOWED_SKIPS` entry (to date
  exactly one: the no-swoole noop case in `CoroutineHooksTest`, whose branch is unreachable
  on the swoole-loading CI venue), or if fewer than 31 real-database tests executed — 31 is
  the enforced MIN floor, not the suite size (the current real-DB count is printed by the
  gate itself). ⚠ A skipped test is not a neutral absence: PHPUnit exits `0` with *"OK, but
  some tests were skipped!"*, so before this gate a missing MySQL service made every
  integration test — 31 at the time — skip on every run while the check stayed green.
- **Security Audit** (`composer-audit`) — Composer dependency audit.
- **Real MCP client SSE session** (`mcp-e2e`, S329) — boots the real hub on `:8800` against
  the MySQL service, mints PATs through the real `McpTokenService`, and drives the official
  `@modelcontextprotocol/sdk` client through initialise → tools/list → one tool call → clean
  close. A JUnit gate (`scripts/assert-mcp-e2e-ran.php`) fails when any required case is
  absent, skipped, or asserted nothing — never a bare green.
- **Cross-Repo Path Contract** (`cross-repo-paths`) — paths that sibling repositories cite
  into this tree still exist here.
- **UI Pin Skew** (`ui-pin-skew`, S181) — compares the `@phlix/*` pins this repo and its
  sibling consumers resolved to. Because pins go stale on OTHER repositories' clocks, this
  workflow additionally runs on a **nightly `schedule`** (`17 3 * * *`) — a check that only
  fires when this repo moves misses its own defect class. The schedule is workflow-level, so
  a nightly run re-executes all twelve jobs unchanged.
- **SPA Bundle Build + Compare Gate** (`spa-bundle`, S253) — a fresh build of `web-ui/` on
  the pinned Node must byte-match the committed `public/assets/app/` tree. This is the gate
  that makes "what you serve is what's in git" true.
- **Browser floor (M-HUB-10)** — the portal bundle targets
  `build.target: 'baseline-widely-available'` (≈Chrome/Edge 107+, Safari 16+, Firefox
  104+), DECLARED in `web-ui/vite.config.ts` rather than inherited from a Vite default.
  The served shell carries a boot placeholder + `<noscript>` notice, so a below-floor
  browser reads an explanation instead of a white screen. TV surfaces are separate
  packaged clients and never load this bundle.

The Docker workflow (S300) builds this repo's own image on this repo's PRs: **boot first,
publish second** — `docker-boot-gate` boots the freshly built image against a throwaway
MySQL and asserts it actually serves `/health` (a `docker build` never executes
ENTRYPOINT, so build-green alone once shipped an image that exited 2 on `docker run`),
and only then does `docker-build` publish it.

## Project structure

```
phlix-hub/
├── bin/              # bin/phlix CLI entry script (webman/console)
├── config/          # Environment-driven config (server, database, auth, logger, updates)
├── docker/           # docker-entrypoint.sh + php.ini (image bits; the image boots start.php directly)
├── docs/            # websockets.md — the :8802/:8803/:8804/:8805 surfaces
├── migrations/      # Idempotent SQL migrations
├── openapi.yaml     # HTTP surface description (linted with redocly.yaml)
├── public/
│   └── assets/       # Static files + built SPA — public/ holds NO index.php; the daemon entry
│                     # point is start.php at the repo root
├── scripts/
│   ├── run-migrations.php
│   └── assert-*.php     # CI gates (phpcs corpus, coverage, integration + MCP E2E currency)
├── src/
│   ├── Application.php   # Worker bootstrap + route registration
│   ├── Alexa/           # Skill envelope, signature/certificate-chain verification, media gateway
│   ├── Auth/            # JWT, users, auth manager
│   ├── Common/          # Container, database pool, logging, rate limiting, trusted-proxy resolver
│   ├── Console/         # bin/phlix commands (migrate, smoke:jwt, oauth:client:*)
│   ├── Http/            # Router, request/response, controllers, middleware, response sink
│   ├── Hub/             # Claims, heartbeats, sharing, DNS, TLS, relay sessions, update checks
│   ├── Federation/      # Hub-to-hub federation peers, shares, sessions
│   ├── Mcp/             # MCP protocol, tool registry + tools, PAT service, scopes
│   ├── OAuth/           # Authorization server: clients, codes, PKCE, tokens, consent, scopes
│   ├── Relay/           # Reverse-tunnel relay workers, frame codec, tunnels, throttle token bucket
│   ├── Requests/        # Media request manager
│   ├── Stats/           # Relay metrics registry, collector, flush service
│   └── SyncPlay/        # SyncPlay relay worker + pending-command dispatch
├── start.php         # Workerman daemon entry point — `php start.php start` (stop/restart/reload/status)
├── tests/           # PHPUnit Unit + Integration + E2E suites
├── web-ui/          # Vite + TypeScript SPA (@phlix/hub-web-ui), built to public/assets/app/
└── Dockerfile
```

## Related repositories

- [`detain/phlix`](https://github.com/detain/phlix) (a.k.a. `phlix-server`) — the local media server.
- [`detain/phlix-shared`](https://github.com/detain/phlix-shared) — shared interfaces, DTOs, and protocol types.

## License

[Mozilla Public License 2.0](LICENSE) — see [`LICENSE`](LICENSE) for the full text.

MPL-2.0 is **per-file weak copyleft**: you may run, modify, self-host and embed
the Hub in a larger project, including a commercial one, but if you modify a file
that is part of the Hub you must make that file's source available under MPL-2.0.
Files you add alongside it can be licensed however you like.

This matches [phlix-server](https://github.com/detain/phlix-server), since both
are server-side applications. The **interop layer stays MIT on purpose** —
[`phlix-shared`](https://github.com/detain/phlix-shared),
[`phlix-contracts`](https://github.com/detain/phlix-contracts),
[`phlix-tokens`](https://github.com/detain/phlix-tokens) and
[`phlix-syncplay`](https://github.com/detain/phlix-syncplay) are consumed by
third-party clients and plugins, where copyleft would reach into every consumer.

"Phlix" is a trademark; see
[TRADEMARK.md](https://github.com/detain/phlix-server/blob/master/TRADEMARK.md)
for what that does and doesn't restrict. Attribution is appreciated, not required.

Note that `crell/tukio` (the event dispatcher) is LGPL-3.0-or-later. MPL-2.0
lists the GPL family as Secondary Licenses (§1.12, §3.3), so it is compatible;
redistributors must still meet each dependency's own terms.
