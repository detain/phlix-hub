# Phlix Hub

**Central cloud directory + reverse-tunnel relay for [Phlix](https://github.com/detain/phlix) media servers.**
Sign in once, reach any of your servers from anywhere — no port forwarding, no static IP, no VPN. Fully self-hostable.

[![CI](https://github.com/detain/phlix-hub/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/phlix-hub/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/phlix-hub/graph/badge.svg)](https://codecov.io/gh/detain/phlix-hub)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![Psalm](https://img.shields.io/badge/Psalm-level%201-brightgreen)](https://psalm.dev/)
[![Code style](https://img.shields.io/badge/code%20style-PSR--12-blueviolet)](https://www.php-fig.org/psr/psr-12/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

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
  refresh tokens, and a published JWKS endpoint. The first account created is auto-promoted to admin.
- **Server claiming** — short-lived claim codes, enrollment JWTs, and a server registry with
  per-server public keys (JWK).
- **Reverse-tunnel relay** — servers hold an outbound WebSocket to the Hub; remote clients are
  multiplexed down that tunnel over a compact binary frame protocol. Idle tunnels are reaped and
  liveness is tracked with heartbeats.
- **Subdomain allocation** — each enrolled server can be assigned `&lt;subdomain&gt;.&lt;public-domain&gt;`
  for clean, per-server URLs.
- **Library sharing** — share a specific library on one of your servers with another Hub user,
  with read-only or read/write permission levels.
- **Invite links** — single-use, signed invite links that grant library access to a recipient.
- **Media requests** — a Jellyseerr-class request queue. Users request movies/series; admins
  approve, and the Hub talks to Sonarr/Radarr to fulfil them.
- **Server-rendered dashboard** — `/my-servers`, `/claim-server`, sharing, and request pages
  rendered with Smarty, plus a full JSON API under `/api/v1`.
- **Operations-ready** — structured JSON logging (Monolog) across dedicated channels
  (app, error, hub, relay, audit), a `/health` endpoint, and idempotent SQL migrations.

## Architecture

The Hub runs as a set of long-lived [Workerman](https://www.workerman.net/) workers in a single
process group:

| Worker | Default port | Purpose |
|--------|--------------|---------|
| HTTP | `8800` | REST API + server-rendered pages + `/health` |
| Relay (server-facing) | `8802` | Servers connect here to open their outbound tunnel |
| Relay (client-facing) | `8803` | Remote clients connect (`GET /client/{server_id}`) and are routed down a tunnel |

Supporting pieces:

- **PSR-11 container** (PHP-DI 7) wires services; routes are registered in
  [`src/Application.php`](src/Application.php).
- **MySQL** is accessed through an async connection pool (`workerman/mysql`). The pool is
  initialised lazily so `/health` stays up even if the database is briefly unreachable.
- **JWT** auth is symmetric (HS256); Ed25519 keys are used for signing enrollment/relay material,
  and the public key set is served at `/.well-known/jwks.json`.

## Requirements

- **PHP 8.3+** with the `pcntl`, `posix`, `json`, `mbstring`, `curl`, and `sodium` extensions
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
5. Clears `var/smarty/{compile,cache}` to avoid stale compiled templates.
6. Runs `scripts/run-migrations.php` — idempotent, only pending migrations apply.
7. `systemctl daemon-reload` then `systemctl restart phlix-hub`.
8. `curl http://localhost:$HUB_PORT/health` as a final sanity check.

What it explicitly does **not** touch: the env file, MySQL grants, HAProxy config, or the
Let's Encrypt certificate. If a release adds new `HUB_*` env vars, append them to
`/etc/phlix-hub.env` yourself — anything the code expects but doesn't find falls back to its
documented default.

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

If you'd rather use your own reverse proxy (nginx, Caddy, Traefik, etc.) instead of the
managed HAProxy, pass `--no-proxy` to either install script. Each service then listens on its
own port (8800 / 8802 / 8803 for phlix-hub, 8096 for phlix-server) and you point your proxy
at those.

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
php public/index.php start         # start the Hub (Ctrl-C to stop)

curl http://localhost:8800/health  # => {"status":"ok",...}
```

Then open <http://localhost:8800/signup> to create the first account (auto-promoted to admin).
After signing in, `/my-servers` lists your servers and `/claim-server` walks through pairing a
new one.

> Run `php public/index.php start -d` to daemonize; `stop`, `restart`, `reload`, and `status`
> are also available.

### CLI (`bin/phlix`)

A small [`webman/console`](https://www.workerman.net/doc/webman/components/command.html) CLI
ships at `bin/phlix`:

```bash
php bin/phlix list         # list available commands (works with no database)
php bin/phlix migrate      # apply migrations/*.sql (idempotent; tracking table)
php bin/phlix smoke:jwt    # smoke-test the JWT create/validate round-trip
```

`migrate` is the CLI equivalent of `php scripts/run-migrations.php`.

## Production install on Ubuntu

These steps target **Ubuntu 22.04 / 24.04**. Run as a sudo-capable user.

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
sudo tee /etc/phlix-hub.env >/dev/null <<'EOF'
HUB_HOST=0.0.0.0
HUB_PORT=8800
HUB_WORKERS=4
HUB_PUBLIC_DOMAIN=hub.example.com

HUB_DB_HOST=127.0.0.1
HUB_DB_PORT=3306
HUB_DB_USER=phlix_hub
HUB_DB_PASSWORD=CHANGE-ME-strong-password
HUB_DB_NAME=phlix_hub

# REQUIRED in production: a >=32-byte secret. Generate once and keep stable.
HUB_JWT_SECRET=CHANGE-ME-run-openssl-rand-hex-32
EOF
sudo chmod 600 /etc/phlix-hub.env
```

Generate a secret with `openssl rand -hex 32`. If `HUB_JWT_SECRET` is unset the Hub falls back to
a random per-process secret — fine for dev, but it invalidates every token on restart, so it must
be set in production.

### 5. Run migrations

```bash
sudo -u www-data --preserve-env \
  env $(grep -v '^#' /etc/phlix-hub.env | xargs) \
  php /opt/phlix-hub/scripts/run-migrations.php
```

The runner records applied migrations in a `migrations` table and is **idempotent** — re-running
it after a successful apply is a no-op.

### 6. Run as a systemd service

```bash
sudo tee /etc/systemd/system/phlix-hub.service >/dev/null <<'EOF'
[Unit]
Description=Phlix Hub
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
EnvironmentFile=/etc/phlix-hub.env
WorkingDirectory=/opt/phlix-hub
ExecStart=/usr/bin/php /opt/phlix-hub/public/index.php start
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now phlix-hub
sudo systemctl status phlix-hub
curl http://localhost:8800/health
```

### 7. Reverse proxy & TLS

Terminate TLS at a reverse proxy in front of the Hub. Both the HTTP API (`8800`) and the
client-facing relay (`8803`, WebSocket) need to be reachable. Example nginx server block:

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

        # WebSocket upgrade (relay + client mount)
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

A `Dockerfile` is provided (PHP 8.3 + Swoole/UV, nginx, supervisor).

```bash
docker build -t phlix-hub .

docker run -d --name phlix-hub \
  -p 8800:8800 -p 8802:8802 -p 8803:8803 \
  -e HUB_DB_HOST=host.docker.internal \
  -e HUB_DB_USER=phlix_hub \
  -e HUB_DB_PASSWORD=CHANGE-ME \
  -e HUB_DB_NAME=phlix_hub \
  -e HUB_JWT_SECRET="$(openssl rand -hex 32)" \
  phlix-hub

# Apply migrations against the configured database
docker exec phlix-hub php /var/www/html/scripts/run-migrations.php
```

Point `HUB_DB_HOST` at a reachable MySQL instance (a linked container, `host.docker.internal`, or
an external host).

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

### Database ([`config/database.php`](config/database.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_DB_HOST` | `127.0.0.1` | MySQL host |
| `HUB_DB_PORT` | `3306` | MySQL port |
| `HUB_DB_USER` | `phlix_hub` | MySQL user |
| `HUB_DB_PASSWORD` | `phlix_hub` | MySQL password |
| `HUB_DB_NAME` | `phlix_hub` | MySQL database name |

### Auth ([`config/auth.php`](config/auth.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_JWT_SECRET` | _(random per-process)_ | **Required in production.** ≥32-byte HMAC secret |
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

Migrations live in [`migrations/`](migrations) and are applied in filename order. The schema:

| Table | Purpose |
|-------|---------|
| `users` | Hub accounts (Argon2id passwords; unique email + username) |
| `servers` | Claimed media servers and their operational state |
| `server_claims` | Pending/paired claim codes minted during pairing |
| `server_heartbeats` | Recent heartbeats for liveness and clock-skew detection |
| `relay_sessions` | One row per open WebSocket relay session |
| `shared_libraries` | Library grants from a server owner to another user |
| `library_shares` | Per-library shares with read-only / read-write levels |
| `invite_links` | Single-use signed invite links |
| `webhooks` | User-defined HTTP callbacks for `phlix.*` event aliases |
| `media_requests` | Jellyseerr-class request queue |
| `dns_challenges` | DNS-01 challenge records for subdomain TLS |

## HTTP API

Selected endpoints (full surface in [`src/Application.php`](src/Application.php)). Protected
routes require a `Bearer` access token (or session cookie for SSR pages).

### Health & discovery

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/health` | Service + version JSON |
| `GET` | `/.well-known/jwks.json` | Public JWKS |

### Auth

| Method | Path | Notes |
|--------|------|-------|
| `POST` | `/api/v1/auth/signup` | Create account |
| `POST` | `/api/v1/auth/login` | Obtain access + refresh tokens |
| `POST` | `/api/v1/auth/refresh` | Exchange a refresh token |
| `POST` | `/api/v1/auth/logout` | Invalidate session |
| `GET` | `/api/v1/me` | Current user (protected) |

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
| `POST`/`DELETE` | `/servers/{id}/subdomain` | Allocate / revoke subdomain |

### Sharing, invites & requests

| Method | Path | Notes |
|--------|------|-------|
| `POST`/`GET` | `/api/v1/me/shares` | Create / list library shares |
| `PATCH`/`DELETE` | `/api/v1/me/shares/{id}` | Update / delete a share |
| `POST`/`GET` | `/api/v1/me/invite-links` | Create / list invite links |
| `GET` | `/invite/{token}` | Accept an invite (public page) |
| `POST`/`GET` | `/api/v1/me/requests` | Create / list media requests |
| `GET` | `/api/v1/admin/requests` | Admin request queue |
| `POST` | `/api/v1/admin/requests/{id}/approve` | Approve a request |
| `POST` | `/api/v1/admin/requests/{id}/deny` | Deny a request |

### Relay (WebSocket)

| Endpoint | Port | Notes |
|----------|------|-------|
| Server tunnel | `8802` | Server opens its outbound tunnel here |
| `GET /client/{server_id}` | `8803` | Client connects and is routed to its server |

## Connecting a media server

1. On the Hub, sign in and open `/claim-server` to start a claim (or the server requests a code
   via `POST /api/v1/server-claims/new`).
2. Enter the claim code on the server; the server is issued an **enrollment JWT** and registers
   its public key.
3. The server opens its **outbound relay tunnel** to the Hub and begins sending heartbeats.
4. The server now appears under `/my-servers`, and remote clients can reach it through the Hub —
   no inbound ports required.

## Testing & quality

```bash
composer test     # PHPUnit (Unit + Integration suites)
composer cs       # PHP_CodeSniffer (PSR-12)
composer stan     # PHPStan (level 9)
composer psalm    # Psalm (errorLevel 1)
```

CI runs on every push and pull request via [GitHub Actions](.github/workflows/ci.yml):

- Composer validation
- PHP_CodeSniffer (PSR-12)
- PHPStan (level 9)
- Psalm (errorLevel 1)
- PHPUnit (with coverage uploaded to Codecov)
- Composer security audit

## Project structure

```
phlix-hub/
├── config/          # Environment-driven config (server, database, auth, logger)
├── migrations/      # Idempotent SQL migrations
├── public/
│   └── index.php    # Workerman HTTP entry point
├── scripts/
│   └── run-migrations.php
├── src/
│   ├── Application.php   # Worker bootstrap + route registration
│   ├── Auth/            # JWT, users, auth manager
│   ├── Common/          # Container, database pool, logging, web portal
│   ├── Http/            # Router, request/response, controllers, middleware
│   ├── Hub/             # Claims, heartbeats, sharing, DNS, TLS, relay sessions
│   ├── Relay/           # Reverse-tunnel relay workers, frame codec, tunnels
│   └── Requests/        # Media request manager
├── tests/           # PHPUnit Unit + Integration suites
└── Dockerfile
```

## Related repositories

- [`detain/phlix`](https://github.com/detain/phlix) (a.k.a. `phlix-server`) — the local media server.
- [`detain/phlix-shared`](https://github.com/detain/phlix-shared) — shared interfaces, DTOs, and protocol types.

## License

MIT — see [`LICENSE`](LICENSE).
