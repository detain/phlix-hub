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

<!-- Mechanical law: every H2 and H3 heading, in document order (GitHub slug rules).
     Add a heading, add its line here. Depth is uniform: H2 top level, H3 nested two spaces.
     The "Table of contents" heading itself is excluded (a TOC does not list itself). -->

- [What is the Hub?](#what-is-the-hub)
  - [Do I actually need a hub?](#do-i-actually-need-a-hub)
- [Features](#features)
- [Terms used in this document](#terms-used-in-this-document)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [One-line install](#one-line-install)
  - [Install flags](#install-flags)
- [Updating an existing install](#updating-an-existing-install)
- [Backing up and restoring](#backing-up-and-restoring)
- [Uninstalling](#uninstalling)
- [Running alongside phlix-server](#running-alongside-phlix-server)
- [Quick start (development)](#quick-start-development)
  - [CLI (`bin/phlix`)](#cli-binphlix)
- [Production install on Ubuntu](#production-install-on-ubuntu)
  - [1. System packages](#1-system-packages)
  - [2. MySQL: database, user, and grants](#2-mysql-database-user-and-grants)
  - [3. Application code](#3-application-code)
  - [4. Environment configuration](#4-environment-configuration)
  - [5. Firewall the exposure you just accepted](#5-firewall-the-exposure-you-just-accepted)
  - [6. Run migrations](#6-run-migrations)
  - [7. Run as a systemd service](#7-run-as-a-systemd-service)
  - [8. Reverse proxy & TLS](#8-reverse-proxy--tls)
- [Docker](#docker)
- [Configuration reference](#configuration-reference)
  - [Server (`config/server.php`)](#server-configserverphp)
  - [Metrics (`config/metrics.php`)](#metrics-configmetricsphp)
  - [Database (`config/database.php`)](#database-configdatabasephp)
  - [Auth (`config/auth.php`)](#auth-configauthphp)
  - [Sonarr / Radarr (optional, for media requests — `config/server.php`)](#sonarr--radarr-optional-for-media-requests--configserverphp)
- [Database schema](#database-schema)
- [HTTP API](#http-api)
  - [Copy conventions](#copy-conventions)
  - [Health & discovery](#health--discovery)
  - [Auth](#auth)
  - [Servers](#servers)
  - [Sharing, invites & requests](#sharing-invites--requests)
  - [Admin (web console)](#admin-web-console)
  - [Bandwidth & quotas](#bandwidth--quotas)
  - [MCP, OAuth & Alexa](#mcp-oauth--alexa)
  - [Relay (WebSocket)](#relay-websocket)
- [Watching with family and friends](#watching-with-family-and-friends)
- [Connecting a media server](#connecting-a-media-server)
- [Federation: pairing two hubs (operator walkthrough)](#federation-pairing-two-hubs-operator-walkthrough)
- [Portal admin basics](#portal-admin-basics)
  - [Pages every member can use](#pages-every-member-can-use)
  - [Admin console (`/app/admin/*`)](#admin-console-appadmin)
  - [What the Settings toggles actually do](#what-the-settings-toggles-actually-do)
- [Troubleshooting](#troubleshooting)
  - [My server shows offline under My Servers](#my-server-shows-offline-under-my-servers)
  - [The claim code isn't going through](#the-claim-code-isnt-going-through)
  - [Watch Party won't join — "relay on port 8804 is unreachable"](#watch-party-wont-join--relay-on-port-8804-is-unreachable)
  - [Too Many Requests (429) — what is and isn't configurable](#too-many-requests-429--what-is-and-isnt-configurable)
  - [Passwords: members and admin lockout](#passwords-members-and-admin-lockout)
  - [Getting help](#getting-help)
- [Testing & quality](#testing--quality)
  - [Running the Integration suite (real MySQL)](#running-the-integration-suite-real-mysql)
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

The Hub is **self-hosted software**: you run it on a machine you control, for your
servers. This repository documents that deployment only — there is no vendor-run public
instance pointed at from here.

### Do I actually need a hub?

Not always. The hub exists to defeat **NAT without port-forwarding** and to host the
social layer. Skip it when either of those is already solved another way:

- **Everyone watches on your home LAN** → the Phlix apps can reach the server's own
  address directly; a hub adds nothing.
- **You're willing to port-forward and run TLS yourself** → remote clients can dial
  the server's own :8097 WebSocket listener directly (that socket needs no hub
  role in the middle) — no relay needed.

Run a hub when you want remote access **without opening inbound ports** (servers dial
*out* to it), a **directory** of your servers with liveness, **library sharing** and
**invite links** for people who don't have accounts on the server itself, **media
requests** (Sonarr/Radarr fulfillment), or **hub-to-hub federation**.

The step-by-step user journeys — what-the-hub-is, remote-access, self-hosting,
claiming a server, library sharing, sharing with friends, invite links, requests —
live in the docs site: [phlix-docs → Hub](https://detain.github.io/phlix-docs/hub/what-is-the-hub).
This README is the operator/API reference; those pages are the illustrated path.

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
- **Subdomain allocation** — each enrolled server can be assigned `<subdomain>.<public-domain>`
  for clean, per-server URLs.
- **Library sharing** — share a specific library on one of your servers with another Hub user,
  with read-only or read/write permission levels. The **Shares** page carries the direct door
  (share instantly with a registered collaborator) above the list-and-revoke view; an
  invite recipient also needs a hub account to redeem — sign-up when it is open, else an
  admin-created account — the invite itself only grants the library share.
- **Invite links** — links carry an opaque token (stored only as a SHA-256 hash) and grant
  a library share to the signed-in hub account that redeems them; one use by default, more
  if the creator raises `max_uses`.
- **Hub-to-hub federation** — federate with peer hubs to share libraries across hubs, with
  per-peer sessions and admin delegation. Honest limit: delegation is currently local
  bookkeeping only — `createAdminDelegation` writes the DB row plus an audit entry, but the
  master-side wire push is not implemented, so no `admin_delegation` frame ever reaches the
  peer today (the reserved envelope is documented as such on `:8805` in the OpenAPI spec).
- **Watch Party (SyncPlay relay)** — watches in lockstep across devices: the hub carries the
  synchronized-playback messages (play/pause/seek) for viewers outside your home
  network, with a **Watch Party** page at `/app/watch-party` for hosting and joining watch parties.
  Account-scoped today — see [Watching with family and
  friends](#watching-with-family-and-friends) for what that means before you invite
  anyone.
- **Media requests** — a Jellyseerr-class request queue. Users request movies/series; admins
  approve, and the Hub talks to Sonarr/Radarr to fulfill them.
- **Web UI & admin console** — the hub's front door is a Vue SPA (the shared `@phlix/ui` design
  system) served at `/app` (`/` redirects to `/app/servers`). Every signed-in user gets **My Servers**,
  **Server Detail**, **Search**, **Shares**, **Shared With Me**, **Watch Party**, **Invite Links**,
  **Requests**, **Security** (own password change plus the Sessions tab: list, revoke one, revoke others), **MCP tokens** (own API credentials), and the
  media-browsing surfaces (music, books, audiobooks, photos) for the selected server; there is a
  public **invite-acceptance** page at `/app/invite/:token` (the target of the public
  `GET /invite/{token}` link). **Federation**, **Federation Shares**, and the `requiresAdmin`
  **admin console** at `/app/admin/*` (Hub Dashboard, Server Traffic, Users, Logs, Settings,
  Audit Logs, request approval) are admin-only — the nav hides them for members and every `/api/v1/admin/*` endpoint
  gates server-side regardless. See [Portal admin basics](#portal-admin-basics) for a page-by-page
  guide. The Vue SPA is
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

## Terms used in this document

Plain definitions for the vocabulary this README leans on — the jargon wall a
reader would otherwise hit. "Claim code" is the worked example: it is defined in the
glossary below, and the pairing walkthrough spells out its format where the
flow uses it.

- **SPA** — single-page application: the Vue app under `/app` that loads once
  and redraws itself client-side; the hub's entire web UI.
- **JWT** — JSON Web Token: a signed, self-describing credential string. Three
  kinds live here, and calling all of them "token" is the estate's worst habit:
  - **session JWT** — your login credential; the portal holds it and calls
    `/api/v1` with it.
  - **enrollment JWT** — issued to a media server ONCE, at claim redemption;
    the server presents it in `HELLO` on its :8802 tunnel to prove who it is.
  - **relay token** — a short-lived, per-session bearer a client uses to join
    :8803/:8804; carried in the WebSocket subprotocol (`["bearer", <tok>]`),
    never in a URL.
  - (A fourth "token" — the **invite token** — is the secret in
    `/invite/{token}` URLs; redeeming it is one-time.)
- **claim code** — the human-typed `ABCD-1234` pairing handoff (see
  *Connecting a media server*).
- **campaign markers** — codes like `S261`, `R-HUB-13`, `M-HUB-10`,
  `HB-4.6`, `R14-08`, a bare severity finding number ("Major 15"), bare
  commit SHAs, or "review hub__asphp M2" are provenance tags from this
  project's tracked review/audit campaign (each names a finding,
  requirement, or decision, not a public issue link). The severity-number
  form counts ranked findings in that campaign's Critical/Major tracks;
  the sentence citing one states its disposition there and then (cured,
  retired). They say *why* a rule exists; the code and behavior they
  reference is what ships.
- **orthography** — this README and the files under `docs/` are American in
  spelling (`behavior`, `synchronized`, `fulfill`, `initialize`,
  `serialized`, `unrecognized`), the convention the config/env vocabulary
  and the vendored contracts already speak (review hub__writer). Code
  identifiers and quotations from sibling repos keep their source spelling
  verbatim; the sweep may not "correct" a quote.
- **watch party / group / room** — one concept, three names, by layer (the
  naming-layers map phlix-server's README publishes, carried here): the
  **canonical wire** says *group* (`syncplay_group_*` frames, `group_id`);
  the hub **internals** say *room* (`SyncPlayRelayWorker`'s `room_state`,
  `MAX_ROOM_MEMBERS`, `broadcastToRoom`, and the legacy bare-room dialect);
  member-facing **copy** says *watch party*. `docs/websockets.md` keeps the
  wire/internal spellings; the portal shows members only the party copy.
- **federation** — hub-to-hub linking: two independent hubs share libraries
  with each other. Not a global network; strictly pairwise and consented.
- **peer** — the other hub in a federation link.
- **master / leaf** — the two roles of one federation link: the **leaf** dials
  (initiates the outbound connection), the **master** accepts. A hub may be
  master on some links and leaf on others.
- **dial** — to open an outbound WebSocket connection to another service
  (the hub never dials into your media server; your server dials the hub).
- **JWKS** — JSON Web Key Set: the hub's public-key list at
  `/.well-known/jwks.json`, how peers verify each other's signatures without
  shipping private material.
- **NAT** — Network Address Translation: why home servers have no public
  inbound address, and therefore why every persistent connection here is
  dialed OUTBOUND.
- **SNI** — Server Name Indication: the hostname a TLS client states
  mid-handshake so one endpoint can serve many certificates.
- **HLS** — HTTP Live Streaming: the segmented `.m3u8`+`.ts` playback format
  the relay fronts for remote viewing.
- **PKCE** — Proof Key for Code Exchange: the OAuth 2.0 extension that stops
  an intercepted authorization code from being redeemed; this hub accepts
  `S256` only.
- **backpressure** — pausing a fast sender when the receiver's buffer fills,
  instead of buffering forever or dropping bytes.
- **reaped** — force-closed by the liveness loop after missing keepalives
  (30 s ping / 90 s window on the relay lanes).
- **seam** — a deliberate configuration junction where behavior swaps without
  a code change (e.g. `HUB_WS_URL` is the seam between direct-:8804 dialing
  and proxy-fronted `wss://`).

## Architecture

The Hub runs as a set of long-lived [Workerman](https://www.workerman.net/) workers in a single
process group:

| Worker | Default port | Purpose |
|--------|--------------|---------|
| HTTP | `8800` | REST API + the Vue SPA (`/app`; legacy page paths 302-redirect here) + `/health` |
| Relay (server-facing) | `8802` | Servers connect here to open their outbound tunnel |
| Relay (client-facing) | `8803` | Client-mount WS (`GET /client/{server_id}`). ⚠ **Declared unavailable** in the multi-process topology: every mount is refused with close 1011 (`client_mount_bridge_unavailable`) — see `docs/websockets.md`; live client↔server traffic rides the HTTP relay proxy (`/api/v1/servers/{id}/proxy/*`) |
| SyncPlay relay | `8804` | Synchronized-playback clients (`GET /syncplay/{server_id}`) |
| Federation | `8805` | Federation link — the leaf hub dials the master for hub-to-hub shares |

Every WebSocket surface is documented in [`docs/websockets.md`](docs/websockets.md); the `:8800`
HTTP surface is described by [`openapi.yaml`](openapi.yaml).

Supporting pieces:

- **PSR-11 container** (PHP-DI 7) wires services; routes are registered in
  [`src/Application.php`](src/Application.php).
- **MySQL** is accessed through a coroutine connection pool (`workerman/mysql`, a
  mysqli wrapper; each coroutine leases its own connection — see `DB_POOL_ENABLED`
  in the configuration reference). The pool is
  initialized lazily so `/health` stays up even if the database is briefly unreachable.
- **JWT** auth is symmetric (HS256); Ed25519 keys are used for signing enrollment/relay material,
  and the public key set is served at `/.well-known/jwks.json`.

## Requirements

- **PHP 8.3+**. Nine extensions are hard gates — `openssl`, `curl`, `sodium`,
  `mbstring`, `json`, `hash`, `filter`, `ctype`, `posix` — asserted by
  `scripts/assert-required-extensions.php` (each entry citing the exact symbol the
  code calls). Be precise about WHERE the gate bites: it runs as a CI step in every
  PHP job (a build with a missing extension never ships), and the managed installer
  provisions them via the apt package list (`php-cli php-mysql php-mbstring
  php-curl …`) and the container base image carries them. **The hub process does not
  re-check them at boot**: a hand-assembled host missing e.g. `curl` starts and then
  fails at first symbol use (`Call to undefined function openssl_verify()` inside the
  Alexa signature gate presents as rejected signatures, not as a clear config error).
  On any install path other than the managed installer, install them yourself.
  `openssl` is additionally what makes any TLS dial (federation `wss://`/`https://` masters,
  relay TLS) possible at runtime — without it those paths fail loud, not silent.
- **`pcntl`** — not boot-gated (Workerman requires it for process management rather than the
  hub source calling it directly; it ships with Ubuntu's `php-cli` package).
- **Swoole + php-uv are recommended, not required.** They enable Workerman's coroutine runtime;
  the hub runs without them but logs an explicit `Swoole extension not detected — coroutine
  runtime will not be active` warning at boot. `scripts/install.sh` compiles both from source;
  the manual package list below deliberately omits them (apt has no php-swoole on the supported
  releases) — build them or use `ghcr.io/detain/phlix-base`, which carries PHP 8.3 + Swoole + php-uv.
- **MySQL 8.0+** (or MariaDB 10.6+ — upsert SQL is flavor-adapted at connect via `UpsertSyntax`; CI exercises MySQL)
- **Composer 2**
- A POSIX host (Linux recommended; Workerman uses `pcntl`/`posix` for process management)

## One-line install

On a fresh Ubuntu/Debian host, [`scripts/install.sh`](scripts/install.sh) does everything in
[Production install](#production-install-on-ubuntu) for you — system packages, MySQL database +
user, code, env file, firewall decisions, JWT secret, migrations, a systemd service, and an HAProxy reverse proxy
with an auto-renewing Let's Encrypt certificate:

> The installer also compiles the **Swoole + php-uv** extensions from source (the coroutine
> runtime Workerman uses), idempotently skipping the build when they already load, and runs a
> `disable_functions` preflight — see
> [Swoole & php-uv on Linux](https://detain.github.io/phlix-docs/install/linux#swoole-php-uv-coroutine-runtime).

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh | sudo bash
```

> **On a production host, pin what you pipe into root.** `master` floats — the
> bytes you execute today need not be the ones you reviewed. Pin by commit
> SHA: raw.githubusercontent.com serves any revision, so replace `master` in
> the URL above with the full 40-character SHA you audited, and record which
> SHA you ran. Or download, inspect, then run locally:
> `curl -fsSL <the same URL> -o /tmp/phlix-install.sh && less /tmp/phlix-install.sh && sudo bash /tmp/phlix-install.sh`.
> Day-one convenience is fine for throwaway boxes; on a box holding user
> credentials, a pinned script is the difference between an install and an
> open root-shell for whoever owns `master` that day.
>
> **There is no usable release tag yet — and `v1.0.0` is not one.** The only
> `v*` tag published on origin is `v1.0.0` (2026-05-19), a relic that
> pre-dates this repo's versioning scheme: the product is on the 0.x line
> (`VERSION` and `Version::VERSION` are 0.5.0, and `CHANGELOG.md` records
> releases up to 0.5.0 — none of which have ever been tagged). That
> tag sits hundreds of commits behind `master` (the count grows daily, so no
> fixed figure is quoted here); an install or `--update` that reaches
> it silently rolls you back five months of fixes. Until a real release tag
> exists, the SHA pin above is the audited-state path, and `--branch` should
> name `master`. Once valid tags are cut (`v0.5.0` or later), pin those.

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
| `--branch NAME` | Git branch or **tag name** to install (default `master`) — named refs only; a raw commit SHA is not resolved (the update resets to `origin/<name>`). See [One-line install](#one-line-install) on the stale `v1.0.0` tag |
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

Updating the hub's **web-UI assets** (`@phlix/ui` re-pin + Vite bundle rebuild under
`public/assets/app/`) is a separate procedure — the runbook for it lives in
[docs/ui-asset-rebuild.md](docs/ui-asset-rebuild.md).

```bash
sudo bash /opt/phlix-hub/scripts/install.sh --update -y
```

Or via the one-liner:

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh \
  | sudo bash -s -- --update -y
```

Pin to a specific branch or tag with `--branch` (named ref only — not a commit
SHA, and until a valid release tag is cut, `master` is the only sound value;
never `v1.0.0`, the stale pre-versioning-scheme tag — see
[One-line install](#one-line-install)):

```bash
sudo bash /opt/phlix-hub/scripts/install.sh --update --branch master -y
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
documented default, with one policy exception: `HUB_DB_PASSWORD` has no silent fallback
outside the dev-family (`dev`, `development`, `local`), the escape flag, or
PHPUnit — unset means the hub refuses to boot.

## Backing up and restoring

The Hub has exactly **three pieces of state**. A backup that does not carry all
three together is not a backup — restoring a database against a different
secret/key pair (or a fresh install's auto-generated key) produces a hub that
starts cleanly and then fails closed in ways that look like data loss.

| Piece | Where | What breaking it costs |
|-------|-------|------------------------|
| **Database** | MySQL schema `phlix_hub` (credentials in `/etc/phlix-hub.env`) | Every server registration, user, claim code, invite, share, quota row, federation peer/key registration, rate-limit history, audit trail and the migration ledger. Restore **only onto a schema migrated to the same or a newer code version** (the `migrations` table checksums files; see *[6. Run migrations](#6-run-migrations)* in the production install below). |
| **JWT session secret** | `HUB_JWT_SECRET` inside `/etc/phlix-hub.env` (chmod 600) | Signs every access/refresh token (`config/auth.php`; ≥32 bytes). If it is lost, changed, or the env file is restored from a different era, **every issued session becomes unverifiable**: all users are logged out at once and refresh tokens cannot save them. When the var is missing entirely, `AuthServicesProvider` falls back to a process-local random secret — tokens die on every restart, so never run production without it. |
| **Hub signing key** | `config/hub-signing-key.pem`, mode **0600**, auto-generated on first boot by `HubServicesProvider` → `Ed25519KeyManager` (path override: add `'hub_signing_key_path' => '<absolute path>'` to the array in `config/server.php` — the shipped file does not define the key, and `HubServicesProvider.php:180` reads it from there, so the override is an operator edit, not an env var) | The hub's Ed25519 identity. It signs enrollment artifacts servers present on pairing and is the master-side key in the federation mutual proof-of-key ceremony. If it is lost and a fresh one is generated, **already-registered servers fail verification** (re-pair them) and **federation peers whose leaf keys were registered under the old identity refuse the hub** until trust is re-established with each peer. |

Everything else is derived and safe to lose: `.logs/` and `var/` (DI container
cache, Swoole HOME lock, Workerman pid/status).
`public/assets/app/` is a committed build — recover it from the repository,
not from a backup.

Backup (cron-friendly, keeps the trio in one archive):

```bash
#!/usr/bin/env bash
set -euo pipefail
umask 077   # every artifact below carries member data — never depend on the caller's umask
STAMP=$(date +%F_%H%M)
DEST=/var/backups/phlix-hub/$STAMP; mkdir -p "$DEST"
# 1. Database (consistent snapshot without locking tables). The dump runs with
#    THIS hub's own credentials, read from the env file — a bare `mysqldump`
#    silently relies on whoever runs it happening to authenticate (a distro
#    root's unix_socket login is exactly that; this repo's installer creates
#    PASSWORD users, so the cron user needs them spelled out). The password
#    travels through a 0600 option file, never argv, where `ps` could read it.
CREDS=$(mktemp); trap 'rm -f "$CREDS"' EXIT; chmod 600 "$CREDS"
# shellcheck disable=SC1091
set -a; . /etc/phlix-hub.env; set +a
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' \
    "${HUB_DB_HOST:-127.0.0.1}" "$HUB_DB_USER" "$HUB_DB_PASSWORD" > "$CREDS"
mysqldump --defaults-extra-file="$CREDS" --single-transaction --routines \
    "${HUB_DB_NAME:-phlix_hub}" | gzip > "$DEST/hub-db.sql.gz"
# 2. Environment (contains HUB_JWT_SECRET + DB credentials) and 3. signing key.
install -m 600 /etc/phlix-hub.env               "$DEST/phlix-hub.env"
install -m 600 /opt/phlix-hub/config/hub-signing-key.pem "$DEST/hub-signing-key.pem"
find /var/backups/phlix-hub -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
```

Restore, in the order that keeps every boot gate happy:

1. Stop the service (`systemctl stop phlix-hub`).
2. Create the schema and reload — the dump is gzipped, so decompress into the
   client; feeding the `.gz` bytes directly into `mysql` fails with a syntax
   error and leaves an empty schema:

   ```bash
   gunzip -c hub-db.sql.gz | mysql --defaults-extra-file="$CREDS" "${HUB_DB_NAME:-phlix_hub}"
   ```

   (same 0600 option-file posture as the dump recipe above — not argv, not
   implicit socket auth)

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
3. The legacy merged PEM at `/etc/haproxy/certs/<domain>.pem` (present only on
   pre-2.9 HAProxy fallback installs or if an older release left one; live-shape
   installs bind `/etc/letsencrypt/live/` directly and keep no copy).
4. `/etc/cron.d/phlix-hub-certbot` and `/etc/letsencrypt/renewal-hooks/deploy/phlix-haproxy.sh`.
   The installer's renewal cron owns the schedule and masks the distro `certbot.timer`;
   the uninstall re-arms that timer only when no Phlix renewal cron remains on the host
   (a still-installed phlix-server cron keeps ownership).
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
projects' frontends and backends, with HAProxy picking the right cert per SNI hostname.

**How the merge works.** Each install drops a fragment at
`/etc/haproxy/phlix-managed/<project>.cfg.fragment` with `fe_http`, `fe_https`, and `backends`
sections. A shared rebuilder then assembles the final `haproxy.cfg` from every fragment it
finds. The `frontend fe_https` bind shape is capability-measured at rebuild time
(the dated HAProxy audit's §3.1 cure, recorded in `CHANGELOG.md`): on HAProxy >= 2.9 — every host on the supported matrix (Ubuntu 24.04+,
where the distro ships 2.9.x) — each annotated TLS fragment binds its certbot paths
directly (`crt /etc/letsencrypt/live/<domain>/fullchain.pem key-file .../privkey.pem`),
so a reload always picks up the current bytes at stable symlink paths and a rotation done
outside this host's certbot can never strand a stale copy. Only certs the rebuild cannot
reference live (operator extras, or domains without material) keep the legacy
`crt /etc/haproxy/certs/` directory bind. On pre-2.9 HAProxy (off-matrix) the builder
falls back to that merged-copy directory — a `cat fullchain + privkey` PEM per domain —
whose copies are refreshed by the deploy hook, and the hook's merged-copy leg stays
active there. `ACME` HTTP-01 challenges are answered straight from
`/var/www/phlix-acme` by the shared frontend, so renewals never stop the proxy.

The first install snapshots any pre-Phlix `haproxy.cfg` to
`/etc/haproxy/haproxy.cfg.pre-phlix.bak`.

**Uninstall behavior**: `--uninstall` removes only that project's fragment and rebuilds. If
other Phlix projects remain, their frontend stays untouched. When the **last** Phlix project
is uninstalled, the rebuilder restores the pre-Phlix snapshot (or removes `haproxy.cfg`
outright if there was no pre-Phlix config) and stops/disables `haproxy`.

The **hub server-tunnel port** (`:8802`) is a separate listener — servers connect to that port
directly. Open it on the firewall but don't put it behind the HAProxy 80/443 frontend.

**What the managed install makes reachable:**

| Port | Surface | Managed HAProxy path | `--no-proxy` path |
| --- | --- | --- | --- |
| `8800` | HTTP API + SPA + `/health` | routed at `/` via 80/443 | ufw-opened (plaintext REST + portal — bearer credentials ride it in the clear; front it with your own TLS or keep it loopback) |
| `8802` | server tunnels | direct — ufw-opened (pairing law: never proxied; **plaintext** unless `HUB_RELAY_TLS` is on — the installer seeds `HUB_RELAY_TLS=true` (wss) only on TLS-domain installs, where the Let's Encrypt deploy hook also keeps the rotated key readable by the hub; on a plaintext-install `HELLO` carries each server's durable enrollment JWT — per surface, the most secret to lose) | ufw-opened (same credential note) |
| `8803` | client mount (declared unavailable) | routed at `/client/` (the listener itself still binds every interface — the installer does not seed `HUB_WS_BIND=127.0.0.1` and does not ufw-open `8803`, so keep it behind an active ufw/cloud firewall or pin `HUB_WS_BIND` yourself) | not ufw-opened (binds all interfaces unless `HUB_WS_BIND` pinned) — point your proxy at it |
| `8804` | SyncPlay watch-party clients | routed at `/syncplay/` | ufw-opened (plaintext WS — close it once your proxy routes it) |
| `8805` | federation MASTER ingress (peer dials) | routed at `/relay/federation/` | ufw-opened (same plaintext caveat) |
| `2206` | cross-process channel broker | loopback only | loopback only |

Under the managed HAProxy the two relay WebSocket surfaces are **not** opened on
the firewall. Who dials what, from where:

- **Federation (`:8805`)** — peer-side ingress rides the shared 443 frontend;
  a leaf's dial target is operator-configured, and the managed
  `/relay/federation/` route carries `wss://<master-domain>/relay/federation/{hub_id}`
  with no extra port, TLS included.
- **SyncPlay watch parties (`:8804`)** — every browser path consults the
  operator seam before dialing: both the pinned `@phlix/ui` bundle and the
  hub's own portal bundle (`web-ui/`'s `buildRelayUrl` — the bundle-wiring
  rider, shipped at this repo's pin) read the shell's
  `window.__PHLIX__.wsUrl/wsPath` bootstrap. On a domain-bound managed
  install the installer itself seeds that seam
  (R-HUB-13): the generated env block gains `HUB_WS_URL` — `wss://<domain>`
  under TLS, `ws://<domain>` on the plain-HTTP managed frontend, always
  matching the frontend the fragments actually built (certbot-failure fallback
  included) — and `HUB_WS_PATH=/syncplay` (the ui seam — `HUB_WS_URL` is the
  master switch; a lone `HUB_WS_PATH` refines nothing), and
  `SharedUiController` grammar-validates both (invalid values fail soft to
  unset). Browsers — portal bundle included — then dial the managed
  `/syncplay/` route with no operator step, so `8804` never needs opening.
  It is a plaintext listener: an HTTPS-served page's `wss://` direct dial
  cannot complete against it (the managed route is the only viable door
  there), and on a TLS-served hub leaving it closed is pure upside — an open
  `8804` buys nothing while publishing a pre-auth token-carrying socket to
  the world.

The direct form survives exactly where the installer cannot name an origin
clients will actually reach — no-domain managed installs and `--no-proxy`
boxes. There the seam stays unset and clients fall back to the legacy
`ws(s)://<page-host>:8804/syncplay/{server_id}` with the port fixed by
`SyncPlayRelayWorker::DEFAULT_PORT`, so `--no-proxy` installs open
`8804`/`8805` in ufw (when it is active) and the plain
`ws://<host>:8804` / `ws://<host>:8805` forms documented in
[`docs/websockets.md`](docs/websockets.md) are reachable there directly.
The manual escape hatch stays open for everyone else too: hand-set
`HUB_WS_URL=wss://<hub-domain>` with `HUB_WS_PATH=/syncplay` in
`/etc/phlix-hub.env` (both grammar-validated per the env table below; the
installer never overwrites a non-empty value) and restart `phlix-hub`.

If you'd rather use your own reverse proxy (nginx, Caddy, Traefik, etc.) instead of the
managed HAProxy, pass `--no-proxy` to either install script. Each service then listens on its
own port (8800 / 8802 / 8803 / 8804 / 8805 for phlix-hub, 8096 for phlix-server) and you point
your proxy at those — replicate the same path prefixes the managed config uses (`/client/`,
`/syncplay/`, `/relay/federation/`) and see §8 for the WebSocket pass-through requirements.

Everything else is already namespaced (env files, systemd units, install dirs, service users,
MySQL DBs, backend ports, certbot artifacts) so there are no other co-install conflicts.

## Quick start (development)

```bash
git clone https://github.com/detain/phlix-hub.git
cd phlix-hub
composer install

# Point the Hub at a MySQL instance (see Configuration reference below).
export HUB_DB_HOST=127.0.0.1 HUB_DB_USER=phlix_hub HUB_DB_PASSWORD=phlix_hub HUB_DB_NAME=phlix_hub
export HUB_JWT_SECRET="$(openssl rand -hex 32)"

# Create the schema + dev user first — run-migrations.php only builds tables,
# it never creates the database (same grants as step 2 of the production install).
sudo mysql -e "CREATE DATABASE IF NOT EXISTS phlix_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'phlix_hub'@'127.0.0.1' IDENTIFIED BY 'phlix_hub'; GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON phlix_hub.* TO 'phlix_hub'@'127.0.0.1'; FLUSH PRIVILEGES"

php scripts/run-migrations.php     # create the tables (idempotent)
php start.php start                # start the Hub (Ctrl-C to stop)

curl http://localhost:8800/health  # => {"status":"ok",...}
```

There is deliberately **no `.env.example`** in this repo: nothing here loads a
`.env` file (no dotenv in the lock), and the former example carried
`XDEBUG_TRIGGER` as plain text, so it was removed as a footgun — the
Configuration reference's XDEBUG row records that history. The `export` lines
above are the whole dev bootstrap; the complete env vocabulary with defaults
lives in the [Configuration reference](#configuration-reference) below.

Then open <http://localhost:8800/app/signup> to create the first account — it is auto-promoted to
**admin**. On a public deployment make sure that first signup is *yours* before opening `:8800`
to the network, then lock registration down: `HUB_SIGNUPS_ENABLED=false` at boot, or flip the
live `auth.signups_disabled` hub setting under `/app/admin/settings` (no restart needed).
After signing in, `/app/servers` lists your servers and the claim flow there (legacy
`/claim-server` 302s to it) walks through pairing a new one.

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

php bin/phlix user:list                        # list hub accounts
php bin/phlix user:create <username> --email <addr> [--password …] [--admin] [--display-name …]
php bin/phlix user:promote <user-or-email>     # grant admin; --revoke demotes
php bin/phlix user:delete <user-or-email> [--force]   # last-admin is protected even with --force
```

`user:*` is the **only recovery path when the last admin locks itself out** —
signup can be closed (`auth.signups_disabled`) and the portal's admin controls
require an admin, so an account that forgets its way out of the building can
only be let back in from a root shell on the hub host. `--password` may be
omitted (interactive prompt) or piped as a single stdin line for scripting;
every command accepts `--json` for machine-readable output.

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
- `REFERENCES` is required because several migrations declare `FOREIGN KEY` constraints
  (e.g. 055's `auth_refresh_grants` → `auth_token_sessions`); it is not a CRUD grant.
- `DROP` is NOT required — an earlier version of this note claimed `049_invite_token_hash_unique.sql`
  needed it for its `ALTER TABLE … DROP INDEX` rebuild path. That claim was falsified by
  execution on MySQL 8.0.46 (the version the CI service and the `mysql:8.0` deploy family
  run): a user holding exactly the grants above — `SHOW GRANTS` captured, no `DROP` anywhere —
  completed both a standalone `ALTER TABLE … DROP INDEX` (rc 0) and the full fresh-install
  chain (executed on 2026-11-11 when the chain was 37 migrations, 001–051 with gaps; later
  migrations 052–058 postdate that proof) including 049's composite `MODIFY` + `ADD UNIQUE` + drop of
  010's `idx_token_hash`; `SHOW INDEX` confirmed the plain index gone and
  `uk_invite_links_token_hash` in its place. Index drops ride the `ALTER` privilege.
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
# Create the dedicated service account first (same shape install.sh uses),
# then hand the whole tree to it. The unit below runs as phlix-hub, so the
# tree — including .logs/, var/ and config/ (the Ed25519 signing key lands in
# config/ on first boot) — must be writable by phlix-hub, NOT by www-data:
# a world-shared web user would defeat the isolation law above.
sudo getent group phlix-hub >/dev/null || sudo groupadd --system phlix-hub
sudo getent passwd phlix-hub >/dev/null \
  || sudo useradd --system --no-create-home --shell /usr/sbin/nologin --gid phlix-hub phlix-hub
sudo mkdir -p .logs
sudo chown -R phlix-hub:phlix-hub /opt/phlix-hub
```

### 4. Environment configuration

The Hub is configured almost entirely through environment variables (see the
[reference](#configuration-reference)) — with five exceptions that live in the
merged config files only and have no env seam: `hub_signing_key_path`,
`dns_zone_dir`, `tls_certs_dir`, `acme_email` and `dns_provider`, read with
built-in defaults by `HubServicesProvider` and gating the DNS-alias/TLS
surfaces. Create an env file the service will load:

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
> you created in step 2 — and it is policed: with `HUB_DB_PASSWORD` unset the hub **refuses
> to boot** (a `RuntimeException` from `config/database.php`), rather than silently using the
> published `phlix_hub` literal. The gate's only admissions are the dev-family
> `APP_ENV`/`HUB_ENV` values (`dev`, `development`, `local`),
> the explicit escape `PHLIX_HUB_ALLOW_DEFAULT_DB_PASSWORD=1`, and PHPUnit — never set the
> escape on a reachable host.

`HUB_JWT_SECRET` is enforced before the worker fork (R14-08): a value on the placeholder denylist
(`CHANGE-ME-run-openssl-rand-hex-32`, `change-me`, `your-secret-here`, `replace-me`, … —
case/space-insensitive) aborts startup with `exit 1` rather than minting tokens under a
publicly-guessable key. An **unset** (or <32-byte) secret falls back to a random per-process key
only in development (`APP_ENV`/`HUB_ENV` = `dev|local`, or `HUB_JWT_ALLOW_DEV_SECRET=1`) — that
fallback invalidates every token on restart and differs per worker, so set a real secret in
production with `openssl rand -hex 32` and keep it stable.

### 5. Firewall the exposure you just accepted

A fresh manual install is **internet-reachable on five ports**. Set this reality
straight before step 7 starts the daemon:

- `:8800` serves the admin console and the REST API. Behind nginx + TLS you may
  keep it loopback-only; exposed directly it is the whole control plane.
- `:8802`, `:8803`, `:8804`, `:8805` (relay, client-relay, SyncPlay, federation)
  **default to binding all interfaces**. `:8802` and `:8805` hard-code it
  (`websocket://0.0.0.0:<port>` in `RelayWorker.php:352`,
  `FederationWorker.php:336`); `:8803` and `:8804` honor `HUB_WS_BIND`
  (`WsTlsContext::listenString()` at `ClientRelayWorker.php:328`,
  `SyncPlayRelayWorker.php:622`) — a proxy-fronted install can pin those two
  to loopback, which is their intended shape since both ride the shared
  frontend's `/client/` and `/syncplay/` routes. `HUB_HOST` restricts
  **only** `:8800`. Setting `HUB_HOST=127.0.0.1` behind nginx still leaves
  every relay listener on the public interface unless you firewall it or
  pin the two that take the knob.
- The installer-managed path opens `ufw` rules per-port only **when ufw is
  active** (see the ports table under *Running alongside phlix-server*); the manual path runs no
  firewall at all. If `sudo ufw status` says `inactive`, you have no host
  filtering whatsoever — either enable it with an explicit policy, or open the
  ports on your cloud security group. Decide per port: `8804` only needs to be
  open while plaintext watch-party clients dial it directly (once your TLS
  proxy routes `/syncplay/`, close it); `8805` only needs inbound dials if this
  hub acts as a federation **master**.

Example for an nginx-fronted hub that only serves its own media servers over
the direct tunnels:

```bash
sudo ufw default deny incoming && sudo ufw allow 22/tcp \
  && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp \
  && sudo ufw allow 8802/tcp && sudo ufw enable
```

### 6. Run migrations

Run the migrator as **root** — the env file is mode 600 root-owned, so an
unprivileged re-execution of the `grep | xargs` form would silently expand to an
empty environment and the runner would fall back to `config/database.php`
defaults instead of your step-2 password (the installer itself runs as root end
to end, so its own migrator calls in `scripts/install.sh` (the update path's
"Running pending migrations" step and the first-install "Database migrations" step)
inherit the real environment):

```bash
sudo bash -c 'set -a; . /etc/phlix-hub.env; set +a; php /opt/phlix-hub/scripts/run-migrations.php'
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
> (whole-line comments are stripped before hashing; an inline `-- …` after a statement still counts). Migrations that already existed before the checksum column
> was introduced are backfilled without re-running, so upgrading never mass-re-applies your existing
> schema. Because a re-applied file executes again, keep migrations re-run-safe (`CREATE TABLE IF NOT
> EXISTS`, additive/idempotent DDL) — the same contract that already governs writing migrations.

### 7. Run as a systemd service

`scripts/install.sh` is the **canonical generator** of the unit file — it writes the
full hardened definition below (service user, port gate, graceful reload, resource
limits, sandbox). If you install by hand, mirror it; the inline copy here is kept
current with the template in `scripts/install.sh` (step 7, "systemd service").

```bash
sudo tee /etc/systemd/system/phlix-hub.service >/dev/null <<'EOF'
[Unit]
Description=Phlix Hub
Documentation=https://detain.github.io/phlix-docs
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
# :8800/:8802/:8803/:8804/:8805/:2206 — six ports (the config/server.php defaults) — to be free.
# If you move HUB_PORT, HUB_RELAY_PORT, HUB_CLIENT_RELAY_PORT, HUB_SYNCPLAY_PORT,
# HUB_FEDERATION_PORT or HUB_CHANNEL_PORT, update the gate's values to match.
ExecStartPre=/usr/bin/env bash /opt/phlix-hub/scripts/wait-for-free-ports.sh -t 90 8800 8802 8803 8804 8805 2206
ExecStart=/usr/bin/php /opt/phlix-hub/start.php start
# GRACEFUL reload: Workerman arms its SIGKILL timer only for SIGUSR1, so
# SIGUSR2 (Workerman Worker::$gracefulStop — symbol cite; the vendored
# line number drifts per workerman release, so do not pin one) is the reload
# that does NOT cut live
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
# logs), var/ (Swoole HOME lock, the DI container compile dir,
# config/ needs write for the Ed25519 hub signing key — HubServicesProvider
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

### 8. Reverse proxy & TLS

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
> at them. Rotation OUTSIDE the managed certbot pipeline must respect two contracts: (1) the
> key files stay readable by the unprivileged worker — the managed install's convention is
> group `phlix-tls`, mode `640` on `privkey*.pem` (rerun the installer's fix-up, or invoke the
> deploy hook at `/etc/letsencrypt/renewal-hooks/deploy/phlix-haproxy.sh` after every delivery);
> (2) HAProxy must reload so it re-reads the live paths — `systemctl reload haproxy`. A hub
> whose TLS env points at out-of-band material logs a loud boot warning plus per-connection
> ERROR lines if the delivery ever breaks contract (1), never a silent handshake death.

## Docker

A `Dockerfile` is provided. The image builds on the shared base
(`ghcr.io/detain/phlix-base`: PHP 8.3 + Swoole + php-uv) and runs the Workerman daemon
(`start.php`) as PID 1 — **no nginx, no php-fpm, no supervisord**. The entrypoint
(`docker/docker-entrypoint.sh`) applies pending migrations at boot, then `exec`s the daemon;
the image `EXPOSE`s `8800 8802 8803 8804 8805` and its healthcheck probes `/health` on `HUB_PORT`.
Give the daemon its graceful-stop window: `docker stop -t 25 phlix-hub` (compose: `stop_grace_period: 25s`) —
the `docker run` below bakes the same value in via `--stop-timeout 25`.

```bash
docker build -t phlix-hub .

# Export real values first — the shell below refuses to start the container with them unset,
# and placeholder JWT literals are refused at boot anyway.
export HUB_DB_PASSWORD='…matching a real MySQL user…'

docker run -d --name phlix-hub \
  -p 8800:8800 -p 8802:8802 -p 8803:8803 -p 8804:8804 -p 8805:8805 \
  --add-host=host.docker.internal:host-gateway \
  --log-opt max-size=64m --log-opt max-file=4 \
  --stop-timeout 25 \
  -e HUB_DB_HOST=host.docker.internal \
  -e HUB_DB_USER=phlix_hub \
  -e HUB_DB_PASSWORD="${HUB_DB_PASSWORD:?export HUB_DB_PASSWORD before running}" \
  -e HUB_DB_NAME=phlix_hub \
  -v phlix-hub-config:/var/phlix/config \
  phlix-hub
```

> `-e VAR=value` puts the secret's VALUE into the `docker run` process argv —
> visible to any local `ps`/accounting for the life of the command. Prefer
> `--env-file` (a chmod-600 file of `KEY=value` lines; note docker does not
> expand shell syntax in env-files, so write literal values there) for every
> credential-carrying `-e` above, including the explicit-JWT form below.

`HUB_JWT_SECRET` may be passed explicitly (`-e HUB_JWT_SECRET="$(openssl rand -hex 32)"`) or
left unset: the entrypoint then generates one key **once** and persists it to
`/var/phlix/config/hub_jwt_secret` — mount that path as a volume (as above) so sessions survive
container re-creation. An ephemeral generated key without the volume invalidates all tokens on
every restart.

⚠ **The JWT volume is not the whole story — the Ed25519 identity key is state #2 and it lives
nowhere persistent in the default layout.** The hub auto-generates its hub signing key at
`/var/www/html/config/hub-signing-key.pem` on first boot, and `/var/www/html` is image-internal:
every container re-creation (every upgrade, every `docker rm`+`run`) silently generates a fresh
identity, so **all paired servers fail verification and all federation peers refuse the hub** —
the exact incident path the "three pieces of state" table above warns about, arriving in Docker
with no warning line. Preserve it with a single-file bind-mount, readable by the daemon user
(`nobody`, uid 65534 — the image runs `USER nobody`):

```bash
# once, from your persisted secret store; the key must exist before first boot to avoid a throwaway generation
docker run -d --name phlix-hub \
  … \
  -v /var/phlix/secrets/hub-signing-key.pem:/var/www/html/config/hub-signing-key.pem:ro \
  phlix-hub
# host side: chown 65534:65534 /var/phlix/secrets/hub-signing-key.pem && chmod 600 …
```

(Or point `hub_signing_key_path` — the `config/server.php` array key described above — at a path
under your mounted volume; that file is baked into the image, so the bind-mount form above is the
no-source-edit route.)

⚠ **The quick run above publishes all five surfaces in the clear.** With the TLS envs at their
documented defaults, `-p 8802:8802` exposes a plaintext listener that carries the **enrollment
JWT** in its `HELLO` frame, `:8804`/`:8805` are pre-auth token-bearing sockets, and `:8800` is
plain HTTP — and Docker publishes them on every host interface. Two honest postures:

- **Proxy-fronted (recommended):** bind publishes to loopback — `-p 127.0.0.1:8800:8800`, `-p
  127.0.0.1:8803:8803`, … — and let the host's reverse proxy be the only thing that reaches the
  hub, TLS included (see *[8. Reverse proxy & TLS](#8-reverse-proxy--tls)*). Do not publish
  `:8804` at all on a TLS-served hub: browsers cannot dial it from an HTTPS page anyway.
- **Direct-dialed:** if media servers must reach the container without a TLS-terminating proxy,
  set `HUB_RELAY_TLS=1` with `HUB_RELAY_TLS_CERT`/`HUB_RELAY_TLS_KEY` before pairing anything,
  and `HUB_RELAY_REQUIRE_TLS=1` so a misconfigured plaintext `:8802` refuses to boot instead of
  leaking enrollment credentials. Federation peers want the same treatment on `:8805`
  (`HUB_FEDERATION_TLS*` / `HUB_FEDERATION_REQUIRE_TLS`). Since SH-064 the client-facing
  pair carries the family too — `HUB_CLIENT_RELAY_TLS*` / `HUB_SYNCPLAY_TLS*` with their
  `*_REQUIRE_TLS` siblings — and `HUB_WS_BIND` can pin those two listeners to loopback
  outright. The family is tabulated in the
  [configuration reference](#configuration-reference).

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
(Workerman's internal log file is the sixth log path in that family, `HUB_WORKERMAN_LOG`,
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


**Portal asset compression.** The hub gzips its hashed (immutable) text assets —
`.css`, `.js`, `.mjs`, `.json`, `.svg` under `/assets/…` — in-process whenever the client
sends `Accept-Encoding: gzip` (replies carry `Content-Encoding: gzip` and
`Vary: Accept-Encoding`; the compressed bytes are cached per worker, keyed on path + mtime
+ size). This saves roughly 3 MB on a cold portal load. If your proxy already compresses,
it is safe to leave both on (the proxy passes an already-encoded body through), but to
compress at the edge instead, e.g. in HAProxy:

```haproxy
frontend fe_https
    compression algo gzip
    compression type text/css application/javascript application/json image/svg+xml
```

### Server ([`config/server.php`](config/server.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_HOST` | `0.0.0.0` | HTTP bind address |
| `HUB_PORT` | `8800` | HTTP listen port (the container HEALTHCHECK follows it) |
| `HUB_WORKERS` | `2` | Number of HTTP worker processes |
| `HUB_WORKERMAN_LOG` | `.logs/workerman.log` | Workerman's own log file |
| `HUB_ALEXA_APPLICATION_ID` | `null` | SH-050. The skill identity `POST /alexa/skill` pins every verified request to: the Amazon-signed envelope must carry EXACTLY this `applicationId` (copy it from the Skills Console's "Skill Endpoint Information"). The cert-chain signature proves a request came from Amazon; this proves it was addressed to THIS skill — any Echo developer can point any skill at any endpoint. Unset on a dev/test boot (incl PHPUnit) leaves the identity arm inactive; unset on any other boot the gate **rejects every request** (400, the existing verification-error envelope — the contracts registry gains no new code): a half-configured signature check is not a gate |
| `HUB_DB_WAIT_SECONDS` | `60` | Docker entrypoint: seconds to wait for the MySQL listener (TCP probe of `HUB_DB_HOST:HUB_DB_PORT`) before running migrations, so a container that wins the boot race against its database no longer burns its one migration attempt and ships an un-migrated schema behind a green DB-free `/health`. A non-resolving host aborts the wait immediately (typo ≠ slow boot); `0` = old single-attempt behavior |
| `HUB_LOG_LEVEL` | `info` | Verbosity of the primary `file` channel (`config/logger.php`); one of the eight Monolog names, invalid → boot fails loud. The old hardcoded `debug` grew one huge file per day (rotation caps by day count, not bytes) |
| `HUB_LOG_DIR` | `<install>/.logs` | Directory for all five rotating logs. The shipped systemd unit grants `ReadWritePaths` only to the install's `.logs`, so relocate carefully |
| `HUB_PUBLIC_DOMAIN` | `phlix.media` | Base domain for per-server subdomains — the `<subdomain>.<public-domain>` hosts baked into server access info and relay URLs. **The default is a placeholder domain this project does not operate:** the hub is self-hosted software with no vendor-run public instance (see the introduction), so leaving this unset hands users `<label>.phlix.media` pointers to a domain you do not control — they will never resolve to your hub. Point it at a domain you manage before relying on subdomain allocation |
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
| `HUB_RELAY_RECONNECT_DRAIN_GRACE` | `5.0` | Seconds an incumbent tunnel keeps draining in-flight requests after a validated server reconnect displaces it (`0` = hard displacement). Live twin: `server.relay.reconnect_drain_grace_seconds` |

> The six listen-port envs were wired through `config/server.php` in R14-15 — before that the
> auxiliary ports existed only as `?? <default>` fallbacks no config set (configurability
> theater). Defaults are byte-identical to the previous literals, so behavior with the envs
> unset is unchanged.

#### Security & posture envs

Verified against `config/` and the consuming classes; each default preserves shipped behavior.
Where a live `hub_settings` twin exists (admin-console override), it is named. Exactly two
twin'd keys carry `restart: true` in the vendored schema (`@phlix/shared` `hub-settings.schema.json`):
`server.metrics.enabled` and `server.rate_limit` — for those, the admin-console value is read at
boot per worker, so changing it needs a restart to take effect (all other twins are live-per-request).
The same law cuts the other way for rate limiting: `server.rate_limit` (a JSON override) merges over
the `PHLIX_HUB_RATELIMIT_*` envs below, so when it is set the env defaults are NOT the whole truth.

| Variable | Default | What it does / unblocks |
|----------|---------|-------------------------|
| `HUB_SIGNUPS_ENABLED` | `true` | `false`/`0`/`no`/`off` closes self-service registration: `POST /api/v1/auth/{register,signup}` answers **403** `auth.signups_disabled` before any hashing work. Live twin: `auth.signups_disabled` (a hub-settings row, when present, is read per signup and overrides this boot-time flag) |
| `HUB_FEDERATION_ENABLED` | `true` | `false` stops new federation dials, drops inbound DATA frames pre-verification, and rejects controller mutators with **409** `provider.not_configured`. The `:8805` listener stays bound until restart (pre-fork socket). Live twin: `federation.enabled` |
| `HUB_MAINTENANCE_MODE` | `false` | `true` answers **503** `provider_unavailable` for API traffic. Exempt by design: `/health`, `/api/v1/auth/*`, hub-settings + admin-settings GET/PUT (the off-switch), `/api/v1/admin/restart`; the SPA shell and hashed assets still serve. Live twin: `hub.maintenance_mode` |
| `HUB_RELAY_TLS` | `false` | Terminates TLS on the `:8802` server-tunnel listener (`wss://`) — what you must enable to pair servers over untrusted networks. Needs `HUB_RELAY_TLS_CERT` / `HUB_RELAY_TLS_KEY` (both default `null`) |
| `HUB_RELAY_TLS_CERT` / `HUB_RELAY_TLS_KEY` | `null` | PEM paths for the `:8802` TLS context above. A missing path aborts boot (fail-fast); an unreadable one warns loudly and is guarded per connection (`TlsMaterialWatch`) — managed installs keep the key group-readable via the `phlix-tls` / mode `640` perms legs |
| `HUB_RELAY_REQUIRE_TLS` | `false` | Fail-closed `:8802` bind mandate — the listen-side mirror of the server's `PHLIX_RELAY_REQUIRE_TLS` (R17-01): `true` makes the relay worker REFUSE to boot when TLS is off, instead of binding a cleartext listener that carries enrollment JWTs. `false` = warn-only — a plaintext bind logs one loud `SECURITY` advisory on the relay channel at boot |
| `HUB_FEDERATION_TLS` | `false` | Terminates TLS natively on the `:8805` hub-to-hub federation listener (`wss://`) — for masters reached WITHOUT a TLS-terminating upstream proxy. Needs `HUB_FEDERATION_TLS_CERT` / `HUB_FEDERATION_TLS_KEY` (both default `null`). A plaintext bind logs one loud `SECURITY` advisory on the relay channel at boot |
| `HUB_FEDERATION_TLS_CERT` / `HUB_FEDERATION_TLS_KEY` | `null` | PEM paths for the `:8805` TLS context above |
| `HUB_FEDERATION_REQUIRE_TLS` | `false` | Fail-closed `:8805` bind mandate, the federation sibling of `HUB_RELAY_REQUIRE_TLS` above: `true` makes `FederationWorker::start()` throw before constructing the listener when TLS is off — no cleartext federation socket is ever bound (federation inbound stays down, the hub serves the rest). `false` = warn-only. Keep unset on managed installs, where HAProxy fronts `:8805` and terminates TLS upstream |
| `HUB_CLIENT_RELAY_TLS` | `false` | Terminates TLS natively on the `:8803` client-mount listener (`wss://`). Needs `HUB_CLIENT_RELAY_TLS_CERT` / `HUB_CLIENT_RELAY_TLS_KEY` (both default `null`). A plaintext bind logs one loud `SECURITY` advisory on the relay channel at boot (same posture as the `HUB_FEDERATION_TLS` advisory above) — on managed installs the shared frontend's `/client/` route is the supported shape, so leave this off there |
| `HUB_CLIENT_RELAY_TLS_CERT` / `HUB_CLIENT_RELAY_TLS_KEY` | `null` | PEM paths for the `:8803` TLS context above. A missing path aborts boot (fail-fast); an unreadable one warns loudly and is guarded per connection (`TlsMaterialWatch`) |
| `HUB_CLIENT_RELAY_REQUIRE_TLS` | `false` | Fail-closed `:8803` bind mandate (SH-064): `true` makes `ClientRelayWorker::start()` throw before constructing the listener when TLS is off. Unlike the federation worker — whose refusal the boot supervisor catches and skips — this listener has no supervisor catch: the hub process refuses to boot at all (fail-closed; the mandate means "never run me with a credential-bearing cleartext listener up"). `false` = warn-only |
| `HUB_SYNCPLAY_TLS` | `false` | Terminates TLS natively on the `:8804` SyncPlay watch-party listener (`wss://`), the sibling posture of `HUB_CLIENT_RELAY_TLS` above. Needs `HUB_SYNCPLAY_TLS_CERT` / `HUB_SYNCPLAY_TLS_KEY` (both default `null`); plaintext bind logs one loud `SECURITY` advisory at boot. The managed `/syncplay/` route stays the supported browser path; this knob is for direct-dial topologies |
| `HUB_SYNCPLAY_TLS_CERT` / `HUB_SYNCPLAY_TLS_KEY` | `null` | PEM paths for the `:8804` TLS context above. Missing path aborts boot (fail-fast); unreadable warns loudly, guarded per connection (`TlsMaterialWatch`) |
| `HUB_SYNCPLAY_REQUIRE_TLS` | `false` | Fail-closed `:8804` bind mandate (SH-064): `true` refuses the plaintext bind before the socket is constructed — the hub process refuses to boot (same supervisorless fail-closed shape as `:8803` above), so group tokens are never carried in the clear. `false` = warn-only |
| `HUB_WS_BIND` | `0.0.0.0` | Bind address for the `:8803` and `:8804` listeners ONLY (SH-064) — pin them to `127.0.0.1` on proxy-fronted installs, where HAProxy's `/client/` and `/syncplay/` routes are the real entrypoints. `:8802` and `:8805` keep their hard-coded all-interface binds (their dialing is the direct-dial law); `:8800` is governed by `HUB_HOST`. Bare IPv6 literals are bracketed for the transport automatically |
| `HUB_COOKIE_INSECURE` | unset | Drops `Secure` from cookies set through `Response::cookie()`, and is the env twin of the stored `auth.cookie.insecure` override: since the CP-011 cookie-session contract the hub DOES set session cookies on browser-class issuance paths (gated by `auth.cookie.enabled`, §2.4 scheme law of that contract), and this opt-in is how a plain-HTTP deployment makes them usable — never a silent Secure downgrade (CD-3a). The CP-012 CSRF Origin guard applies to cookie-authenticated state-changing requests regardless of this flag |
| `XDEBUG_MODE` | `off` | Xdebug mode inside the container (`docker/php.ini` §[xdebug] block; formerly also cited as an installer-generated bare-metal ini — that path was REMOVED 2026-11-05: after the swoole coroutine-teardown GPF evidence the installer no longer installs php-xdebug or writes any xdebug ini, and `--update` DISARMS an enablement an older recipe left (`phlix_disarm_xdebug`; HOST-5 Q6/Q8, owner ruling)). **Never set `debug` or `trace` in production**: `start_with_request = trigger` means any request carrying the trigger value arms step-debugging/tracing, and traces land in `/tmp/xdebug` — pre-created inside the container's shared world-writable `/tmp` (`docker-entrypoint.sh` boot mkdir; `xdebug.trace_output_dir` in the same `docker/php.ini` block). On today's bare metal the installer ships no xdebug at all, so the trace directory is a container-surface concern |
| `XDEBUG_TRIGGER` | `devdebug` | Trigger value for the mode above (`docker/php.ini` §[xdebug] block). Treat it as a secret if you ever enable a debug/trace mode on a reachable host; the former `.env.example` carried it as plain text and was deleted because nothing in this repo loads `.env` files (no dotenv in the lock) |
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
env-overridable — except the two profile rows below that say so explicitly
(file-only override, no `PHLIX_HUB_RATELIMIT_*` seam); absent keys fall back to
the defaults below. HTTP surfaces that trip return
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
| `client_relay_token` (no env seam) | `10` / `60` | Client relay-token mint (`POST /me/servers/{id}/relay-token`), keyed `client_relay_token:{userId}` — attributable member; with the service-side cap of 20 active tokens per (user, server) scope this bounds both mint rate and standing credentials (M-HUB-22, relay-token limiter). Default is a `RateLimitProfiles::defaults()` literal; override ONLY by adding a `rate_limit.client_relay_token` `{max, window}` subsection to `config/server.php` (read generically by `CommonServicesProvider`) |
| `client_request_create` (no env seam) | `20` / `60` | Media-request creation (`POST /me/requests`), keyed `client_request_create:{userId}` — attributable member; paired with the pending dedup echo and the 50-pending standing cap on the member's queue (M-HUB-23). Same override route as above: a `rate_limit.client_request_create` subsection in `config/server.php` — there is no `PHLIX_HUB_RATELIMIT_*` env for these two profiles |
| `PHLIX_HUB_RATELIMIT_RELAY_CONNECT_MAX` / `_RELAY_CONNECT_WINDOW` | `10` / `60` | :8802 server relay-connect (WS), keyed by IP — `count=1` worker, truly global |
| `PHLIX_HUB_RATELIMIT_CLIENT_MOUNT_MAX` / `_CLIENT_MOUNT_WINDOW` | `30` / `60` | :8803 client-mount (WS), keyed by IP — `count=1` worker, truly global |
| `PHLIX_HUB_RATELIMIT_SYNCPLAY_MOUNT_MAX` / `_SYNCPLAY_MOUNT_WINDOW` | `30` / `60` | :8804 SyncPlay pre-auth connect (WS), keyed `syncplay_mount:<real ip>` — `count=1` worker, truly global; runs before the token lookup so a flood can't convert into DB work |
| `PHLIX_HUB_RATELIMIT_FEDERATION_CONNECT_MAX` / `_FEDERATION_CONNECT_WINDOW` | `10` / `60` | :8805 inbound federation connects (master role), keyed `federation_connect:<trusted ip>` — `count=1` worker, truly global |
| `PHLIX_HUB_RATELIMIT_FEDERATION_FRAME_MAX` / `_FEDERATION_FRAME_WINDOW` | `120` / `60` | :8805 federation frames, both roles, keyed `federation_frame:<remote peer hub id>` — `count=1` worker, truly global |
| `TRUSTED_PROXIES` | `127.0.0.0/8,::1/128` | Trusted proxy IPs/CIDRs used to resolve the real client address for the IP-keyed limiters (`src/Common/Http/TrustedProxyResolver.php`) — a forgeable leftmost `X-Forwarded-For` is never trusted |
| `PHLIX_FEDERATION_CA_BUNDLE` | unset | TLS trust-anchor selection for federation **dials** (this hub as leaf connecting to an `https://`/`wss://` master): absolute path to a PEM bundle that replaces the system store as verification anchor. Set it when the master serves a private-CA certificate; an unreadable path fails LOUD at dial (never a silent system-store fallback). Put CA/root certificates in this file; never your master's leaf/fullchain — leaves change every ~60 days, and a leaf-anchored bundle then breaks every dial silently at rotation. `src/Federation/FederationPeerManager.php` (`buildMasterDialPlan`) — see also `docs/websockets.md` |
| `PHLIX_FEDERATION_ALLOWED_HOSTS` | unset | Optional egress containment for federation **dials**: comma-separated EXACT hostnames/IPs (case-insensitive; IPv6 brackets optional) the leaf may connect to. Unset or blank = no host restriction (default — private-IP LAN masters are legitimate federation topology). Once set, any other host is refused LOUD at dial-plan build (`PHLIX...FederationPeerManager::admitEgress`); this is an operator containment hatch, not a security boundary — the dial's security is the H-4/H-5 proof-of-key ceremony |

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

#### Update checks ([`config/updates.php`](config/updates.php))

The hub polls its own published version marker so the admin console can show
"update available" — every row below is fetch-policy, none gates serving:

| Variable | Default | Notes |
|----------|---------|-------|
| `HUB_UPDATES_CHECK_ENABLED` | `true` | Update-check master switch. **On by default**: the hub fetches a version marker from `raw.githubusercontent.com` (below) once per poll interval. Disable with `false` on egress-restricted or air-gapped installs — a blocked fetch is recorded as a failed check and retried on the next interval; it never blocks boot or serves. An admin can also flip it at runtime via `PUT /api/v1/admin/updates/settings` (a `hub_settings` override, which wins over this default) |
| `HUB_UPDATES_MARKER_URL` | `https://raw.githubusercontent.com/detain/phlix-hub/master/VERSION` | The remote version marker fetched per poll; kept in lockstep with `Phlix\Hub\Version::VERSION` (pinned by `tests/VersionTest.php`) |
| `HUB_UPDATES_COMMAND` | the installer's `--update` one-liner | Copy-to-clipboard update command surfaced by `GET /api/v1/admin/updates/status` — verbatim from *Updating an existing install* above; an operator pastes it into a root shell |
| `HUB_UPDATES_POLL_SECONDS` | `86400` | Steady-state interval between marker fetches (nothing fetches on the boot path; the worker only touches the network when this interval elapsed since the last completed check) |
| `HUB_UPDATES_SWEEP_SECONDS` | `60` | How often the maintenance worker asks whether a poll is due — one `hub_settings` read, not a network call |
| `HUB_UPDATES_TIMEOUT_SECONDS` | `10` | Socket timeout for the marker fetch |
| `HUB_UPDATES_DEADLINE_SECONDS` | `20` | Backstop deadline for an in-flight fetch (NOT a duplicate of the timeout: with no route out, hooked DNS resolution can stall `stream_socket_client()` past the vendor client's own timer — measured 2026-08-10; without the deadline, an egress-filtered hub's first fetch stays in flight forever). At expiry the check is recorded as timed out and the single-flight guard released |

### Metrics ([`config/metrics.php`](config/metrics.php))

The relay-side metrics accumulators and their MySQL rollups. These knobs tune COLLECTION;
what they feed is the admin-gated REST surface `GET /api/v1/admin/metrics/`
(`snapshot` / `history` / `connections` / `routes` — see `src/Application.php`,
`registerMetricsRoutes()`) — **not** a Prometheus scrape endpoint; the **Server
Traffic** admin page (`/app/admin/metrics`) is what renders those four series. The
`enabled` flag has a
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
| `HUB_DB_PASSWORD` | *(no silent default — unset refuses boot outside the dev-family (`dev`/`development`/`local`), the escape below, or PHPUnit; `config/database.php`)* | MySQL password |
| `HUB_DB_NAME` | `phlix_hub` | MySQL database name |
| `PHLIX_HUB_ALLOW_DEFAULT_DB_PASSWORD` | unset — **refuse** | When `HUB_DB_PASSWORD` is unset, admits the published `phlix_hub` literal fallback instead of refusing boot (`config/database.php` throws `RuntimeException` naming this var). Development/testing escape only — the dev-family `APP_ENV`/`HUB_ENV` (`dev`, `development`, `local`) and PHPUnit already pass without it; never set on a reachable host |
| `DB_POOL_ENABLED` | `1` | Coroutine connection pool (each coroutine leases its own connection). `0` falls back to the single mutex-serialized socket |
| `DB_POOL_SIZE` | `8` | Pool size per connection name **per worker** — keep `workers × connection names × DB_POOL_SIZE` under MySQL `max_connections` |

### Auth ([`config/auth.php`](config/auth.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_JWT_SECRET` | _(none in prod)_ | **Required in production.** ≥32-byte HMAC secret. A denylisted placeholder literal is refused at boot with `exit 1` (R14-08 pre-flight, folded into `resolveSecret()` so console commands and workers enforce it too); unset = hard boot refusal (`MissingJwtSecretException`) outside dev, random per-process fallback inside dev (`APP_ENV`/`HUB_ENV` ∈ `dev`/`development`/`local` — the full accepted vocabulary — or `HUB_JWT_ALLOW_DEV_SECRET=1`). The placeholder policing is separately SKIPPED under PHPUnit or when `HUB_ENV`/`APP_ENV`/`PHLIX_ENV` is `test` (`AuthServicesProvider::isTestEnvironment()`) — the test env never carries a real secret. The Docker entrypoint generates + persists one to `/var/phlix/config/hub_jwt_secret` when unset |
| `HUB_JWT_ACCESS_TTL` | `3600` | Access-token lifetime (seconds) |
| `HUB_JWT_REFRESH_TTL` | `604800` | Refresh-token lifetime (seconds) |

### Sonarr / Radarr (optional, for media requests — [`config/server.php`](config/server.php))

| Variable | Default | Description |
|----------|---------|-------------|
| `HUB_SONARR_URL` | `http://localhost:8989` | Sonarr base URL |
| `HUB_SONARR_API_KEY` | _(empty)_ | Sonarr API key |
| `HUB_SONARR_ENABLED` | `0` | Enable Sonarr fulfillment |
| `HUB_RADARR_URL` | `http://localhost:7878` | Radarr base URL |
| `HUB_RADARR_API_KEY` | _(empty)_ | Radarr API key |
| `HUB_RADARR_ENABLED` | `0` | Enable Radarr fulfillment |

## Database schema

Migrations live in [`migrations/`](migrations) and are applied in filename order.
The numbering is historical, not contiguous — 013–026 were never used — and the
ledger is keyed by filename, so the gap is harmless.
The schema below is the complete inventory: the 37 tables the migrations create,
grouped by subsystem, plus the `migrations` tracking table the runner creates
for itself.

**SQL-mode posture.** Nothing pins or asserts sql_mode; the code is written for
MySQL 8's default. Two sites engage it: `MetricsRepository::history()` groups on
a SELECT alias so the default `ONLY_FULL_GROUP_BY` accepts it (a raw-expression
GROUP BY there 500s with error 1055), and `FederationLibraryShareRepository`'s
offer ingestion parses every field before store — because a non-strict venue
would silently truncate oversized bytes into bounded columns as trusted rows,
which is exactly the failure the boundary parsers exist to make mode-agnostic.
A deployment running a non-default sql_mode must re-verify both paths.

| Table | Purpose |
|-------|---------|
| **Accounts and authentication** | |
| `users` | Hub accounts (Argon2id passwords; unique email + username) |
| `invite_links` | Invite links — opaque tokens stored as SHA-256 hashes, usable until `max_uses` (1 by default) is spent or the link expires |
| `login_rate_limit` | Shared, cross-worker login rate-limit buckets (one row per key; the only DB-backed limiter) |
| `auth_signup_guard` | First-admin election mutex — a single-row INSERT serializes concurrent registrations, so exactly one first user can ever self-elect |
| `auth_revoked_refresh_tokens` | Revocation registry: logouts record the `jti` of a presented refresh JWT, and token refresh refuses anything recorded here |
| `mcp_tokens` | Personal Access Tokens for the MCP endpoint (`POST /mcp`) — user-scoped, space-delimited scopes, SHA-256 hash only at rest |
| `oauth_clients` | Long-lived registry of OAuth 2.0 clients using the hub as authorization server (operator-managed) |
| `oauth_consent_requests` | ~10-minute, single-use consent requests, consumed by the consent POST |
| `oauth_authorization_codes` | ~60-second, single-use authorization codes, consumed by the token endpoint |
| `oauth_tokens` | Access/refresh grants (1 hour / 30 days) — revocable, rotated on refresh |
| `auth_token_sessions` | Session-family revocation store for the hub's JWT auth path (migration 055) — one row per device session, enabling per-device sign-out |
| `auth_refresh_grants` | Refresh-token reuse-detection ledger (migrations 055/056) — one row per minted refresh grant with its own `expires_at`, cascade-deleted with its session |
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

Selected endpoints below. The maintained, machine-readable surface is
[`openapi.yaml`](openapi.yaml) at the repo root (Redocly-validated, kept in lockstep
with the router by `OpenApiSpecMatchesRouterTest`); [`src/Application.php`](src/Application.php)
is where routes are registered, not where they should be read from. Protected
routes require authentication via `AuthMiddleware`: a `Bearer` access token takes
absolute precedence; browser clients may instead carry the HttpOnly
`phlix_hub_token` cookie (cookie-session dual-emit, CP-011), and every cookie-lineage
state-changing request must pass the CP-012 CSRF Origin guard
(`403 csrf.invalid_origin` otherwise). The legacy SSR page paths 302-redirect
to `/app` unconditionally.

### Copy conventions

Two laws govern the human-readable legs of API responses (they generalize the
quota-family law already written into `src/Http/QuotaFrames.php`):

1. **No terminal periods.** Message legs carry no trailing period — the law is
   written verbatim in the quota frames ("No leg carries a terminal period
   (M58 API-message law)"). Several legacy arms still
   ship period-closing sentences (`AdminUpdatesController`, `HubSettingsController`,
   `RequestController`, `HubRestartController`, `McpTokenController`); they are
   grandfathered until a coordinated copy wave rotates their whole-frame pins,
   and every NEW or EDITED leg obeys the law from day one.
2. **Prose spells `ID`.** Human-readable text writes "Server ID", "Peer ID",
   "Offer ID"; machine keys stay snake_case (`server_id`, `peer_id`). Legacy
   lowercase outliers (`ServerClaimController`'s "claim id" and the MCP tools'
   "Id of a server…") are grandfathered under the same coordinated-wave rule.

Consumer duty: clients match on `code`, never on prose — prose legs are free to
be reworded, which is exactly why the whole-frame test pins exist on both sides.

### Standing laws and owner plate

Record-only rows (HUB-S51) — none of these change behavior; they name a law or an open
owner decision so the next contributor does not rediscover it.

| Row | Status | Statement |
|---|---|---|
| WS-credential law | standing law | Any WebSocket auth that accepts a **cookie or a query-string credential** must carry an `Origin` check in the same commit that adds it. A browser attaches cookies to a cross-site upgrade on its own, so without the check the socket is an ambient-authority hole. Bearer-in-header and the pairing handshakes are not affected. The existing `CookiePolicy::stateChangingRequestOriginAllowed()` SSOT is the law to reuse. |
| Empty-`Origin` quirk | disclosed, pinned | `CookiePolicy::stateChangingRequestOriginAllowed()` skips the Origin leg for a present-but-empty `Origin` (only the literal `null` is refused), identically to phlix-server's twin; the Referer leg still has to name the Host. Tightening is a both-repos step, never hub-solo. |
| TLS-mandate flip | owner-held | Whether the hub's plaintext listeners (`8800`, `8802`) stop being a supported default and TLS becomes mandatory is an owner decision, not yet taken. Until it is, the plaintext notes in the port table stay accurate and no installer default changes. |
| Typed-exception adoption | observation | Estate convention is moving toward typed exceptions over bare `\RuntimeException` / string-matched failures. Recorded as an estate-level observation only: no solo hub refactor, adoption happens where a step already touches the code. |

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
| `GET` | `/api/v1/auth/sessions` | List your active device sessions, newest-used first (protected); the row that minted the calling token is flagged `current` |
| `DELETE` | `/api/v1/auth/sessions/{id}` | Revoke one of your device sessions (protected); foreign and unknown ids both answer 404, an already-revoked own session is an idempotent 204 |
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
| `POST` | `/api/v1/admin/users/{id}/reset-password` | Generate + return one fresh random password (`new_password`) |
| `GET` | `/api/v1/admin/metrics/{snapshot,history,connections,routes}` | Relay traffic rollups — the four series the **Server Traffic** admin page renders (collectors tuned by *Metrics* in the [configuration reference](#configuration-reference)) |
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
| `GET /syncplay/{server_id}` | `8804` | SyncPlay client joins a synchronized-playback session |
| Federation link | `8805` | The leaf hub dials the master for hub-to-hub shares |

## Watching with family and friends

A **watch party** is what Phlix calls a synchronized-playback group (the protocol
messages say `group`, the internals say `room`, members see **Watch Party**):
press play on one device and every participant's player follows — start, pause,
seek, in step. The human journey lives in the user guide:
[phlix-docs — SyncPlay](https://detain.github.io/phlix-docs/advanced/syncplay)
and [Sharing with friends](https://detain.github.io/phlix-docs/hub/share-with-friends).

What the Hub adds is **cross-network reach**: viewers who are not on your home
LAN cannot see your media server's sync port directly, so their browsers dial the
hub's SyncPlay relay (port `8804`, WebSocket path `/syncplay/{server_id}`) and the
hub forwards the party's play/pause/seek messages down the tunnels it already
holds. Opening the portal's **Watch Party** page and typing a party name is the
whole member flow — the name *is* the join code.

**The limitation to know before you invite anyone:** a hub-relayed party is
**account-scoped**. The room is keyed to the media server and its owning account,
so it spans that account's own devices (TVs, phones, laptops signed into the same
hub). A friend who has *their own* hub account cannot join your party through the
hub — the relay refuses them. Friends with accounts on the media server itself can
still join through the server's own LAN/direct SyncPlay path, which does not go
through the hub at all. Cross-account watch parties are a hub capability still to
come; nothing in this repo ships them today.

Operators: for remote browsers to dial `:8804` directly it must be reachable —
see the port table under *Running alongside phlix-server* and
the `:8804` section of `docs/websockets.md` for the TLS/mixed-content caveat that decides
whether opening it helps or only exposes.

## Connecting a media server

Pairing is a two-sided handoff: the **server** requests a code from the hub, and
a **human types that code into the hub** while signed in. The hub's own pages
under *My Servers* (the SPA's claim flow, legacy path `/claim-server`) do the
typing; the server-side counterpart lives in the media server's admin UI.

1. **On the media server**, open its hub-pairing screen and enter this hub's
   URL. The server calls the hub's public `POST /api/v1/server-claims/new` and
   the screen shows a claim code like `ABCD-1234` — uppercase, four plus four,
   written so no `0`/`O`/`1`/`I` can be misread — together with an expiry
   countdown.
2. **On the hub**, sign in and open **My Servers → claim a server**, and type
   the code. The claim belongs to **your** account — whoever redeems the code
   owns the pairing (and is subject to the per-user server cap; a full quota
   fails the redeem with a clear message).
3. **Wait, both screens do the rest.** The server polls the hub until the claim
   completes, then receives its **enrollment JWT** and registers its public
   key. If the code's **10-minute window** lapses first, nothing is lost: ask
   the server for a fresh code and repeat step 2. The hub distinguishes
   "no such code", "expired", and "already redeemed" for the polling server.
4. **The server opens its own outbound relay tunnel** (:8802) to the hub and
   starts heartbeating — no inbound ports on the server's network. It then
   appears under **My Servers**, online, and remote clients can reach it
   through the hub.

The tunnel's address and credentials are configured on the **server** side
(`PHLIX_RELAY_*` — see the phlix-server repository's README); the hub needs no
per-server setup beyond the claim.

## Federation: pairing two hubs (operator walkthrough)

Federation links **two hub installations** so their users can share libraries across
hubs and (optionally) delegate admin actions. Each hub signs its handshake with the
Ed25519 key created on first boot (`config/hub-signing-key.pem`, README
*Backing up and restoring*); the paired remote hub must store the matching **public**
key before the link can ever verify. The portal — **Federation** and **Federation
Shares** pages (admin-only nav entries) — carries the peer roster (add and remove
peers) and the offer replies (view, accept or reject incoming share offers; view and
revoke outgoing ones). The steps marked **(API only)** below have no portal control at
all — hub-config reads and writes, the `leaf_hub_id` binding, the per-peer relay and
admin-delegation toggles, named delegations, and creating an outgoing offer: there the
`curl` calls are the path, not a convenience.

**Prerequisites.** The `federation.enabled` hub setting must be on —
while it is off, every federation admin mutation answers `409`, inbound
peer frames are dropped, and no dial is attempted (Configuration reference;
reads stay open, which is what lets you configure the pairing at all). Reach
runs ONE way: the **leaf dials the master**, so it is the MASTER-side
listener that the leaf must be able to reach — a pure leaf needs no inbound
federation port at all. On a managed install that reach rides the master's
shared 443 frontend (`/relay/federation/` route, TLS included, nothing extra
to open); a native `wss://` on `:8805` itself exists only when the
`HUB_FEDERATION_TLS*` set is configured (see `docs/websockets.md`,
*`:8805` — hub federation*).

**Before you start — get a session token.** Every `$…_JWT` below is the `access_token` from a login. Mint one (it lasts `HUB_JWT_ACCESS_TTL`, 3600 s = 1 hour by default — re-run the login or call `/api/v1/auth/refresh` when calls start answering 401):

```bash
HUB_JWT=$(curl -s -X POST -H "Content-Type: application/json" \
  -d '{"email": "you@example.com", "password": "..."}' \
  https://YOUR-HUB/api/v1/auth/login | jq -r .access_token)
```

Use that value as `$HUB_JWT`, `$MASTER_JWT` or `$LEAF_JWT` below, from the matching hub.

**Step 1 — each admin reads their own identity.** Two endpoints, both trivial:

```bash
curl -s https://YOUR-HUB/.well-known/jwks.json
# → the hub's Ed25519 signing key as JWK `x` (base64url of the raw 32 bytes)
curl -s -H "Authorization: Bearer $HUB_JWT" \
  https://YOUR-HUB/api/v1/me/federation/hub-config
# → {id, name, url, public_key, role, is_master, is_active}
```

`id` is this hub's hub_id (a UUID minted when the config row was first written).
The `public_key` column is operator-maintained: the handshake's `hub_hello` carries
exactly this stored value as the caller's claim of identity, so it must be set before
the peer pair can ever verify — if GET shows `null`, save the JWKS `x` value:
`PUT /api/v1/me/federation/hub-config {"public_key": "<x>"}` (the portal Federation
page does not fill this field; the API call is how).

**Step 2 — exchange identity out of band, and verify it.** The two admins swap
(hub_id, relay URL, public key) over a channel they already trust — a phone call, a
typed key fingerprint, anything but the hub link itself. This is the load-bearing
security law: the handshake verifies that the dialer *possesses* the registered key
(the proof is an Ed25519 signature checked against it), so a substituted key in
`federation_peers` collapses the whole ceremony to "paste the token." The URL and key
you register are only as strong as the channel you got them over. The stored key
accepts the usual encodings — base64url (the JWKS `x` form), standard base64, or hex;
anything that is not 32 bytes after decoding never verifies.

**Step 3 — the master registers the leaf first.** The dial direction is fixed: the
**leaf dials the master**, and the master only accepts a dialer it already knows —
the hub_id in the dial path (`/relay/federation/{hub_id}`) must match a peer row
before any connection is honored. So on the master:

```bash
curl -s -X POST -H "Authorization: Bearer $MASTER_JWT" -H "Content-Type: application/json" \
  -d '{"url": "https://leaf.example.com", "public_key": "<LEAF_PUBLIC_KEY>", "name": "Annabell hub", "leaf_hub_id": "<LEAF_HUB_ID>"}' \
  https://MASTER-HUB/api/v1/me/federation/peers
```

`url` records how a hub says it is reachable for federation — which for a
master-side row like this one is the leaf's advertised identity, never a dial
target (the master does not dial out). Prefer the managed shape (the example above; for a native-TLS listener use `wss://leaf.example.com:8805`): the public
`https://` frontend URL whose `/relay/federation/` route carries peer traffic.
A `:8805` in the URL means the NATIVE federation listener — speak `wss://`
there only when that hub has configured `HUB_FEDERATION_TLS*`; a bare
`ws://<host>:8805` is the `--no-proxy` plaintext shape, documented (with its
firewall caveats) in `docs/websockets.md`, *`:8805` — hub federation*.

`leaf_hub_id` is what the path lookup matches; it may be omitted here and bound later
with `PUT /api/v1/me/federation/peers/{id}/leaf-hub-id` — **(API only)** binding: the
portal's add-peer form carries URL, name and public key but no `leaf_hub_id` field, so
a portal-created peer always needs this call to become dialable. Until it is set, the
leaf's dials are refused, which is the safe failure.

**Step 4 — the leaf registers the master and declares its role.** On the leaf,
`POST …/federation/peers` with the master's URL and public key (portal-covered;
that row is the dial target — the handshake answers with the master's own
proof-of-key before any data is accepted), then flip this hub's role **(API only —
no portal control writes `role` or `is_active`)**:

```bash
curl -s -X PUT -H "Authorization: Bearer $LEAF_JWT" -H "Content-Type: application/json" \
  -d '{"role": "leaf", "is_active": true}' \
  https://LEAF-HUB/api/v1/me/federation/hub-config
```

Only a config with `role: "leaf"` and `is_active: true` dials (and only rows in that
state are retried on schedule after a drop); a master hub's own config stays at
`role: "master"` or unset, which is what keeps it from dialing back.

**Step 5 — enable what the link is allowed to carry.** Peer registration alone moves
no data. Per peer: `PUT /api/v1/me/federation/peers/{id}/relay {"enabled": true}`
switches on library relay **(API only — the Federation page exposes no relay
switch)**; `PUT …/peers/{id}/admin-delegation {"enabled": true}` switches on the
admin-delegation channel (further gated by named delegations,
`POST /api/v1/me/federation/admin-delegations`) **(also API only)**. Library sharing
itself is an offer flow: `POST …/library-shares/outgoing` on the offering side
**(API only — the shares page can revoke outgoing offers but not create them)**,
then the receiving user sees it under *incoming offers* and answers `accept` or
`reject` (the portal **Federation Shares** page's controls).

**Reading the link state.** `GET /api/v1/me/federation/peers` shows each peer's
status; `connected` only ever appears after a successful `hub_hello_ack` ceremony —
a row stuck at `pending`/`disconnected` with a healthy process means the dial was
refused or dropped, and the *`:8805` — hub federation* section of `docs/websockets.md` documents the refusal reasons the
audit log carries.

## Portal admin basics

The admin-facing tour of what the portal actually shows. Everything here lives
in the SPA at `/app` — no shell, no env vars required. Two roles exist:
**members** see their own servers, shares, and parties; **admins** additionally
see **Federation**, **Federation Shares**, and the whole **Admin console**. The
console is enforced server-side on every `/api/v1/admin/*` call — the menu
entry merely reflects that.

### Pages every member can use

| Page | What you do there |
|---|---|
| **My Servers** (`/app/servers`) | Pair a media server (type its claim code — see [Connecting a media server](#connecting-a-media-server)), inspect, rename, or unlink. This is also where you pick the **current** server; every media page browses that one. |
| **Server Detail** (`/app/servers/:id`) | One server's health, versions, and relay state. |
| **Search** (`/app/search`) | Cross-library search against the selected server. |
| **Shares** (`/app/shares`) | Grant other members access to your paired server's libraries. |
| **Shared With Me** (`/app/shared-with-me`) | Libraries other members have granted you. |
| **Invite Links** (`/app/invite-links`) | Create invite links — an opaque token, hashed at rest, granting a library share. The public `/invite/:token` link lands on the acceptance page, where the recipient signs in (or signs up when signups are open) and redeems: the accept call is authenticated (anonymous → 401 `auth.required`), so an invite grants the share but never creates the account. |
| **Watch Party** (`/app/watch-party`) | Host or join a watch party for synchronized viewing on your selected server. |
| **Requests** (`/app/requests`) | Jellyseerr-class request queue: ask for movies/series; admins approve. |
| **Security** (`/app/settings/security`) | Your own password change, plus the **Sessions** tab: lists your signed-in devices (backed by `GET /api/v1/auth/sessions`), revokes one device (`DELETE /api/v1/auth/sessions/{id}`), or revokes all the others (the page issues one DELETE per other session — there is no bulk endpoint). The current device's row is read-only: it carries no revoke control, so use Sign out to end it. |
| **MCP tokens** (`/app/mcp-tokens`) | Personal access tokens for MCP/API access — every user manages their own. |

The media-browsing surfaces (music `/app/music/*`, books `/app/books/*`,
audiobooks `/app/audiobooks/*`, photos `/app/photo/*`) all operate against the
**selected server**. With no server selected — or if the selected one was
unlinked — they have no data to show; pair or re-select under My Servers first.

### Admin console (`/app/admin/*`)

| Page | What it's for |
|---|---|
| **Dashboard** | Hub-wide counts: servers (total/online/offline), active relay sessions, pending requests, users — plus the recent-audit activity list. No party or traffic counters live here (traffic is its own page below). |
| **Server Traffic** (`/app/admin/metrics`) | Relay traffic charts — snapshot, history, connections, per-route — fed by the metrics collectors. Data appears only with `server.metrics.enabled` on (the toggle is restart-gated: it re-reads at worker boot). |
| **Users** | Create users (the on-ramp when signups are closed), edit, promote/demote admin, reset a member's password, delete. |
| **Logs** | Recent hub log lines in the browser — no SSH needed. |
| **Settings** | The hub settings store — the toggles are the next table. |
| **Audit Logs** | Append-only record of claims, pairings, federation, and admin actions (`GET /api/v1/me/audit-logs`). |
| **Requests (approval)** | Approve or reject the request queue; approvals dispatch to Sonarr/Radarr when configured. |

### What the Settings toggles actually do

Admin → Settings edits the hub settings store: live rows that override
config-file defaults, served by `GET/PUT /api/v1/admin/settings`. Most take
effect on the next request; the two marked **restart** only re-read at worker
boot — restart from the admin console's Restart button (`POST /api/v1/admin/restart`, a graceful reload) or `systemctl reload phlix-hub`. The store can never touch secrets, TLS paths, or listener ports — those
are denied by design and belong in the [env reference](#configuration-reference).

| Toggle (store key) | Effect | Live? |
|---|---|---|
| **Signups disabled** `auth.signups_disabled` | `POST` registration answers 403 `auth.signups_disabled`. Invite links remain the on-ramp — but only for people who already hold a hub account or gain one from an admin (the **Users** page's create is the fallback); accepts are authenticated. Before enabling, make sure at least one admin password you know works — recovery without one is `bin/phlix user:list` / `user:promote`. | live |
| **Maintenance mode** `hub.maintenance_mode` | Every API request answers 503 `provider_unavailable` EXCEPT health checks, auth endpoints (nobody gets locked out mid-maintenance), the hub/admin settings routes (so you can switch it back off), and the admin restart. Takes effect within ~1 s. | live |
| **Federation enabled** `federation.enabled` | The hub↔hub master switch. Off: outbound dials stop and their reconnect ticks park into ≤60 s probes, all 11 federation admin mutations answer 409 `provider.not_configured`, inbound peer frames are dropped. On: parked probes resume dialing within a minute. | live |
| **Auto-approve requests** `requests.auto_approve` | New requests are approved without admin review (best-effort Sonarr/Radarr dispatch). | live |
| **Invite default expiry** `invite.default_expiry_seconds` | How long a new invite link stays valid when the creator doesn't pick a lifetime. | live |
| **Per-user server cap** `server.max_servers_per_user` · **per-server user cap** `server.max_users_per_server` | Quotas enforced AT REDEEM — claiming a code or sharing past the cap fails `quota.exceeded` (resets on release, never a timer). | live |
| **Metrics** `server.metrics.enabled` / `server.metrics.retention_days` | Opt-in usage metrics kept on the hub; retention prunes per day. | enabled: **restart** · retention: live |
| **Relay rate limits** `server.rate_limit` (JSON) | The live dial for the 429 throttles — a store blob merged OVER the boot-time `PHLIX_HUB_RATELIMIT_*` env defaults (see *Rate limiting* in the [configuration reference](#configuration-reference)). | **restart** |
| **Relay drain grace** `server.relay.reconnect_drain_grace_seconds` | Seconds an incumbent tunnel keeps draining in-flight requests when a validated server reconnect displaces it (`0` = hard displacement). Live-read per displacement, clamped 0–300. | live |
| **Sonarr / Radarr** `server.arr.{sonarr,radarr}.{enabled,url}` | Request fulfillment backends. The API keys stay env-only by design (never settable through the store). | live |
| **Token lifetimes** `auth.access_ttl` · `auth.refresh_ttl` · `server.enrollment_ttl` | JWT lifetimes for sessions and server enrollment. | live |

## Troubleshooting

Symptom-first answers for the five things that actually go wrong. If none of
this fits, the log is the oracle: `.logs/app.log` (or Admin → Logs).

### My server shows offline under My Servers

Pairing is **outbound**: the server keeps a WebSocket tunnel to the hub's relay
listener on **:8802**; the hub never dials into your network. So "offline"
means that tunnel is down — check the server side first:

1. Is the media server process running its relay client? (phlix-server config:
   `PHLIX_RELAY_*` — the tunnel's address and credentials are configured
   THERE, not on the hub.)
2. Can the server reach `hub-domain:8802`? The hub's firewall must allow it —
   and note the relay listeners bind `0.0.0.0` regardless of `HUB_HOST`
   (`HUB_WS_BIND` can pin only `:8803`/`:8804`; see *Firewall the exposure*
   in the manual install).
3. If the hub runs with `HUB_RELAY_TLS`/`HUB_RELAY_REQUIRE_TLS`, the server
   must present TLS-compatible dialing — a `wss://` hub refuses plaintext
   tunnels when `REQUIRE_TLS` is on.
4. Watch the hub log for `HELLO`/enrollment-key rejections: an unregistered
   or re-keyed server is refused, and **audit-logged** — check Admin →
   Audit Logs for the reason.

### The claim code isn't going through

The code lives **10 minutes** (600 s) and is single-use. The hub answers three
distinct refusals, and each means something different:

| You see | Meaning | Fix |
|---|---|---|
| "no such code" (`claim.code_not_found`, 404) | Typo, or the code was regenerated on the server | Retype carefully (`ABCD-1234`, no `0`/`O`/`1`/`I`), or get a fresh code |
| "expired" (`claim.code_expired`, 410) | The 10-minute window lapsed | Ask the server for a fresh code |
| "already used" (`claim.code_already_claimed`, 409) | Someone redeemed it | Pair the *other* screen — the code now belongs to that pairing |
| 409 `quota.exceeded` | Redeeming would exceed your per-user server cap | Unlink a server or raise `server.max_servers_per_user` |

Guessing repeatedly rate-limits you: 429 after 60 bad attempts per minute —
the bucket is per-worker, so the true ceiling is 60 × workers per IP.

### Watch Party won't join — "relay on port 8804 is unreachable"

**:8804 is a plaintext listener.** If the portal page itself was served over
HTTPS, the browser refuses the direct `wss://…:8804` dial (mixed content) —
opening 8804 in the firewall changes nothing on a TLS hub. Two working shapes:

- **HTTP-served hub:** open `8804/tcp` and the direct dial works.
- **TLS-served hub:** front `:8804` with your reverse proxy (a `wss://`
  upgrade location), and set `HUB_WS_URL` (+ `HUB_WS_PATH=/syncplay`) so the
  portal surfaces dial the proxy instead — see [Configuration
  reference](#configuration-reference). The hub-local Watch Party page
  honors that same seam: `web-ui/`'s `buildRelayUrl` consults
  `window.__PHLIX__.wsUrl`/`wsPath` first and only then falls back to
  same-origin `:8804` — the identical law the vendored `@phlix/ui` dial
  applies, so on a correctly proxied TLS hub the hub's own page joins
  parties (the Major 38 carve-out is cured at the current pin).

Also check the party code itself: rooms are per-server, and joining requires
the caller's own access to that server (the relay gates on ownership, not
just the code).

### Too Many Requests (429) — what is and isn't configurable

HTTP endpoints answer **429** with `Retry-After` and
`{"error":"Too Many Requests","code":"rate_limited"}`; the WebSocket
listeners (:8802/:8803/:8804/:8805) reject the handshake with close code
**1013 (Try Again Later)** instead. The live dial is the `server.rate_limit`
hub-setting JSON blob (Admin → Settings) — **restart required** after edits,
because limiter profiles are per-worker singletons. That blob is merged OVER
the boot-time `PHLIX_HUB_RATELIMIT_*` env defaults, which DO exist for
fourteen of the sixteen limiter profiles (plus the shared `cap`) — the full
table lives in *Rate limiting* in the
[configuration reference](#configuration-reference). Major 8 retired exactly
two fabricated seams (`client_relay_token`, `client_request_create`): those
two profiles are literal-only, every other profile's `max`/`window` is
env-settable at boot. A
single NAT behind many clients shares one IP bucket — raise the bucket or
front-load, don't fight the throttle.

### Passwords: members and admin lockout

There is **no self-service reset** — no mail transport exists to send a
reset link. A member who forgets their password asks an admin: **Admin →
Users → reset-password** (`POST /api/v1/admin/users/{id}/reset-password`),
which GENERATES a fresh random password and returns it once in the response
body (`new_password`) — there is nothing to "set" and nothing to email; the
admin relays the value over their own trusted channel.
The admin who locked everyone else out (or was demoted) is recovered from the
shell: `bin/phlix user:list` to find the account, `bin/phlix user:promote
'username-or-email'` to restore admin (it toggles — `--revoke` demotes,
refusing to remove the last admin). If the LAST admin disables themselves behind
`auth.signups_disabled`, the CLI path remains open — it needs no web session.

### Getting help

There is no support inbox — the issue tracker at
<https://github.com/detain/phlix-hub/issues> is the only channel, and docs
confusion is a valid issue there. If a page of this README lost you, say
which page: that is the report.

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
runs the unit suite only. Point them at a throwaway database — the suite builds
its tables there and empties data between tests, so never
aim them at a database you care about:

```bash
HUB_TEST_DB_HOST=127.0.0.1 HUB_TEST_DB_PORT=3306 \
HUB_TEST_DB_USER=root HUB_TEST_DB_PASSWORD=root HUB_TEST_DB_NAME=phlix_hub_test \
  ./vendor/bin/phpunit --testsuite Integration
```

The named database must already exist (the tests create tables, not schemas).
The migration chain is applied **once per PHPUnit process** (a few seconds),
not per test: `RealDatabaseTestCase` caches the resulting schema fingerprint,
re-validates it against `information_schema` on every `setUp()` (~2 ms; any
drift — including a sibling suite legitimately emptying the database — forces
a drop-and-re-apply), and each test only gets a cheap 0.02 s chain replay plus
a data reset on top. Budget seconds for the suite, not minutes per test.

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
  exactly one: the no-swoole no-op case in `CoroutineHooksTest`, whose branch is unreachable
  on the swoole-loading CI environment), or if fewer than 31 real-database tests executed — 31 is
  the enforced MIN floor, not the suite size (the current real-DB count is printed by the
  gate itself). ⚠ A skipped test is not a neutral absence: PHPUnit exits `0` with *"OK, but
  some tests were skipped!"*, so before this gate a missing MySQL service made every
  integration test — 31 at the time — skip on every run while the check stayed green.
- **Security Audit** (`composer-audit`) — Composer dependency audit.
- **Real MCP client SSE session** (`mcp-e2e`, S329) — boots the real hub on `:8800` against
  the MySQL service, mints PATs through the real `McpTokenService`, and drives the official
  `@modelcontextprotocol/sdk` client through initialize → tools/list → one tool call → clean
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

The enumeration above is the twelve. One further build posture sits under the
same heading by habit but is not a CI job — the **browser floor** (M-HUB-10):
the portal bundle targets `build.target: 'baseline-widely-available'`
(≈Chrome/Edge 107+, Safari 16+, Firefox 104+), DECLARED in
`web-ui/vite.config.ts` rather than inherited from a Vite default. The served
shell carries a boot placeholder + `<noscript>` notice, so a below-floor
browser reads an explanation instead of a white screen. TV surfaces are
separate packaged clients and never load this bundle.

The Docker workflow (S300) builds this repo's own image on this repo's PRs: **boot first,
publish second** — `docker-boot-gate` boots the freshly built image against a throwaway
MySQL and asserts it actually serves `/health` (a `docker build` never executes
ENTRYPOINT, so build-green alone once shipped an image that exited 2 on `docker run`),
and only then does `docker-build` publish it.

## Project structure

```text
phlix-hub/
├── bin/              # bin/phlix CLI entry script (webman/console)
├── config/          # Environment-driven config (auth, database, federation, hub, invite, logger, metrics, process, requests, server, updates)
├── docker/           # docker-entrypoint.sh + php.ini (image bits; the image boots start.php directly)
├── docs/            # websockets.md (:8802-:8805 surfaces) + ui-asset-rebuild.md (@phlix/ui re-pin runbook)
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
│   ├── Console/         # bin/phlix commands (migrate, smoke:jwt, oauth:client:*, user:*)
│   ├── Http/            # Router, request/response, controllers, middleware, response sink
│   ├── Hub/             # Claims, heartbeats, sharing, DNS, TLS, relay sessions, update checks
│   ├── Federation/      # Hub-to-hub federation peer/share/session services (the :8805 worker itself lives under Relay/)
│   ├── MaintenanceWorker.php # Dedicated worker for the DB-only reapers/pruners
│   ├── ServerReaper.php  # Offline-server reaper + heartbeat/audit/federation-session retention sweeps
│   ├── Version.php       # Hub version constant
│   ├── Mcp/             # MCP protocol, tool registry + tools, PAT service, scopes
│   ├── OAuth/           # Authorization server: clients, codes, PKCE, tokens, consent, scopes
│   ├── Relay/           # Reverse-tunnel relay workers (incl. the :8805 FederationWorker listener), frame codec, tunnels, throttle token bucket
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
- [`detain/phlix-docs`](https://github.com/detain/phlix-docs) — the user-facing docs site, incl. the Hub journey pages linked from *Do I actually need a hub?*
- [`detain/phlix-shared`](https://github.com/detain/phlix-shared) — shared interfaces, DTOs, and protocol types.
- [`detain/phlix-ui`](https://github.com/detain/phlix-ui) — the Vue design system whose bundle this hub serves at `/app`.
- [`detain/phlix-contracts`](https://github.com/detain/phlix-contracts) — the frozen error-code and route-manifest contracts shared by every Phlix client and server.
- [`detain/phlix-syncplay`](https://github.com/detain/phlix-syncplay) — the watch-party wire spec and reference libraries.

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
