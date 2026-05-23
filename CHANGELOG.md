# Changelog

All notable changes to `detain/phlix-hub` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- `Phlix\Hub\Hub\TlsCertificateManager::provisionCertificate()` (and
  the underlying `runAcmeChallenge()` flow) now throws a
  `\RuntimeException` with a stable, machine-grep-able message —
  `'ACME certificate provisioning is not implemented in this build.
  Provision certs out-of-band — see docs/hub-admin/tls.md.'` — instead
  of silently shelling out to `openssl`, generating an account key
  and CSR, and then returning `file_exists(...fullchain.pem)` as if
  it had actually issued anything. Read-side helpers
  (`getCertificatePath`, `getPrivateKeyPath`, `needsRenewal`) still
  tell the truth from on-disk material. New `isProvisioned(string
  $subdomain): bool` helper exposes that truth directly. Shell-safety
  pass: the cert-expiry openssl call is now routed through
  `proc_open` with an argv array (no shell, no `escapeshellcmd`),
  and the temp-file cleanup in CSR generation no longer needs to
  swallow `@unlink` errors because that code path is gone.
  `DnsAliasManager::allocateSubdomain()` catches the new exception
  and logs a warning so subdomain (DNS) allocation still succeeds —
  TLS is now an out-of-band step. `DnsAliasManager::refreshCertificate()`
  lets the exception propagate. `SubdomainController::allocate()` no
  longer invokes cert provisioning at all — DNS allocation succeeds
  unconditionally and the cert paths come back as empty strings until
  material is installed out-of-band, so clients can distinguish "DNS
  wired, TLS pending" from a fully provisioned state. The explicit
  cert-refresh entry point `SubdomainController::refreshCertificate()`
  (not yet routed publicly) catches `\RuntimeException` and returns
  **HTTP 501 Not Implemented** with
  `{"error":"NOT_IMPLEMENTED","code":"tls.acme_not_implemented",...}`
  and a `Link: </docs/hub-admin/tls.md>; rel="help"` header.
  Previously the manager looked complete on paper but in practice
  silently failed every provisioning attempt.
- `Phlix\Hub\Http\Controllers\RelayController::handle()` — the
  post-auth, post-`Upgrade: websocket` "no implementation" path now
  returns **HTTP 501 Not Implemented** (RFC 9110 §15.6.2) instead of
  HTTP 500. The body carries a stable machine-readable shape:
  `{"error":"NOT_IMPLEMENTED","code":"relay.ws_not_implemented",
  "message":"…","docs":"https://detain.github.io/phlix-docs/dev/relay-protocol"}`
  and a `Link: <docs-url>; rel="help"` header. Auth gates (401, 426)
  are unchanged — only the terminal "no impl" status code and body
  shape changed. Previously the 500 misled clients into retrying as
  if this were a transient server fault.

### Known Limitations
- **Step C.8 ACME / Let's Encrypt provisioning is not implemented in
  this build.** `TlsCertificateManager::provisionCertificate()` throws
  `\RuntimeException` with the stable message
  `'ACME certificate provisioning is not implemented in this build.
  Provision certs out-of-band — see docs/hub-admin/tls.md.'`, and the
  cert-refresh path of `POST /api/v1/servers/{id}/subdomain` returns
  HTTP 501 `code=tls.acme_not_implemented`. Subdomain allocation (DNS
  record + DB row) still works; operators install TLS material out-of-
  band — see [`docs/hub-admin/tls.md`](docs/hub-admin/tls.md).
- **WebSocket relay multiplex tunnel is not implemented in this
  build.** `POST /api/v1/servers/{id}/relay` validates the
  enrollment JWT and the `Upgrade: websocket` header, then returns
  HTTP 501 with `code=relay.ws_not_implemented`. The architectural
  design is documented in
  [`docs/dev/architecture-hub.md`](docs/dev/architecture-hub.md#relay-tunnel-design)
  ("Relay tunnel design") and remains the target shape, but no WS
  upgrade handler, `TunnelManager` multiplexer, or client-side relay
  endpoint exists yet. Earlier entries describing Step C.6 / the
  relay tunnel as shipped refer to scaffolding (auth, session table,
  router, subdomain allocation) — not the live tunnel itself.

### Fixed
- `Phlix\Hub\Http\Controllers\ServerManageController::accessInfo()` now
  populates `relay_url` when the relay tunnel is active and the server has
  been allocated a subdomain (via migration 008's `servers.subdomain`).
  The URL is built as `https://{subdomain}.{public_domain}` using the new
  `public_domain` key in `config/server.php` (default `phlix.media`,
  overridable via the `HUB_PUBLIC_DOMAIN` env var). Previously the field
  was hardcoded to `null`, so even with a live relay tunnel clients were
  never told how to reach the server. The response shape (`relay_url`
  key) is unchanged.
- `migrations/012_enrolled_at_and_last_frame_at.sql` — creates the
  `servers.enrolled_at` and `relay_sessions.last_frame_at` columns
  that `ClaimRequestHandler` and `RelaySessionManager` write to.
  Without this migration a fresh database could not complete a
  server claim or record relay frame activity. The same migration
  also back-fills `enrolled_at` from `created_at` for any rows that
  already exist. Migration `007_server_claims_and_servers.sql` was
  also updated to (a) drop its forward reference to `enrolled_at`
  in an `AFTER` clause (column position is cosmetic) and (b) use
  `ADD COLUMN IF NOT EXISTS` so a re-run on a partly-patched
  database is a no-op instead of an error.
- `Phlix\Hub\Hub\ServerInfoHandler::getServerInfo()` and `getServersForUser()`
  now populate `ServerInfoDto::relayActive` from the actual database state
  (an `EXISTS` subquery against `relay_sessions` for rows where
  `closed_at IS NULL`). The field was previously hardcoded to `false`,
  so `GET /api/v1/me/servers/{id}/access-info` and the My Servers dashboard
  always reported the relay tunnel as down regardless of its real state.
  The DTO contract is unchanged; only the value source was fixed.

### Added
- **Step D.5 — Invite-Link Sharing**: Single-use invite link sharing for library access.
  - `InviteLink` DTO — represents an invite link with expiry, max uses, and status checks (`isExpired()`, `isExhausted()`, `canUse()`).
  - `InviteLinkHandler` — business logic for creating, redeeming, listing, and revoking invite links.
  - `InviteLinkController` — API controller with endpoints:
    - `POST /api/v1/me/invite-links` — create a new invite link
    - `GET /api/v1/me/invite-links` — list all invite links for the authenticated user
    - `DELETE /api/v1/me/invite-links/{id}` — revoke an invite link
    - `GET /invite/{token}` — public invite acceptance page
  - `migrations/009_invite_links.sql` — creates `invite_links` table with token hash, max uses, and expiry tracking.
  - Smarty templates: `home/invite-link.tpl` (link display card), `home/accept-invite.tpl` (acceptance page).
  - Tests: `InviteLinkTest` (13 tests), `InviteLinkHandlerTest` (7 tests), `InviteLinkControllerTest` (11 tests).
  - `HubServicesProvider` registration for `InviteLinkHandler` and `InviteLinkController`.
  - `docs/hub/invite-links.md` — end-user guide for invite link sharing.
  - `docs/reference/api/hub-invite-links.md` — API reference documentation.

- **Step C.9 — Shared Libraries (Friends/Family)**: Library sharing between Hub users.
  - `LibraryShare` DTO — represents a library share record with permission levels (read/readwrite), expiry, and revocation state.
  - `SharedLibraryDto` DTO — represents a library shared with the current user, including access URLs and permission level.
  - `LibrarySharingHandler` — business logic for share creation, revocation, permission updates, and listing outgoing/incoming shares.
  - `LibraryShareController` — API controller with endpoints:
    - `POST /api/v1/me/shares` — create a new library share
    - `GET /api/v1/me/shares` — list outgoing and incoming shares
    - `DELETE /api/v1/me/shares/{id}` — revoke a share
    - `PATCH /api/v1/me/shares/{id}` — update share permission
  - `migrations/009_library_shares.sql` — creates `library_shares` table with permission levels, expiry, and proper indexes.
  - SSR pages: `GET /shared-with-me` (libraries shared with you), `GET /manage-shares` (libraries you've shared).
  - Smarty templates: `home/shared-with-me.tpl`, `home/manage-shares.tpl`.
  - Tests: `LibraryShareTest` (13 tests), `LibrarySharingHandlerTest` (12 tests), `SharedLibraryDtoTest` (4 tests), `LibraryShareControllerTest` (12 tests).
  - `HubServicesProvider` registration for `LibrarySharingHandler` and `LibraryShareController`.
  - `docs/hub/shared-with-friends.md` — end-user guide for library sharing.

- **Step C.8 — Public Hostname (`*.phlix.media`)**: Subdomain allocation for enrolled servers.
  - `DnsAliasManager` — allocates deterministic 8-char subdomains (sha256 of server_id), stores in `servers.subdomain`, creates DNS records via pluggable `StaticZoneManager`.
  - `TlsCertificateManager` — provisions TLS certificates via Let's Encrypt ACME v2, stores in configurable directory, auto-renews before expiry.
  - `RelayRouter` — routes inbound requests by Host header to the correct relay session based on subdomain.
  - `SubdomainController` — `POST /api/v1/servers/{id}/subdomain` (allocate/retrieve) and `DELETE /api/v1/servers/{id}/subdomain` (revoke).
  - `StaticZoneManager` — static zone file writer for DNS record management.
  - `migrations/008_subdomain_allocation.sql` — adds `subdomain` column to `servers`, creates `dns_challenges` table for ACME DNS-01.
  - Tests: `DnsAliasManagerTest` (8 tests), `TlsCertificateManagerTest` (6 tests), `RelayRouterTest` (11 tests), `SubdomainControllerTest` (6 tests).
  - `HubServicesProvider` registration for `StaticZoneManager`, `TlsCertificateManager`, `DnsAliasManager`, `RelayRouter`, `SubdomainController`.
  - `config/hub.php` keys: `dns_zone_dir`, `tls_certs_dir`, `acme_email`, `dns_provider`.

- User signup, login, logout, and `/my-servers` dashboard MVP. Routes: `GET /signup`, `POST /signup`, `GET /login`, `POST /login`, `POST /logout`, `GET /my-servers`, plus JSON variants `POST /api/v1/auth/{signup,login,logout,refresh}` and `GET /api/v1/me`.
- **Step C.4 — My Servers dashboard**: `GET /api/v1/me/servers` (JSON list of claimed servers), `DELETE /api/v1/me/servers/{id}` (remove a claimed server), `GET /api/v1/me/servers/{id}/access-info` (best direct/relay URL). SSR pages: `GET /my-servers` (server cards with status badges, last-seen, version, hostnames), `GET /claim-server` (claim-code entry form). Smarty templates: `home/my-servers.tpl`, `home/claim-server.tpl`, `partials/server-card.tpl`. Client-side `my-servers.js` handles remove-with-confirmation. CSS in `app.css` covers server cards, status badges, empty states, claim form.
- JWT auth using the shared `Phlix\Shared\Auth\JwtClaims` shape. `JwtHandler::validateAccessToken()` returns a hydrated `JwtClaims` instance — the cross-repo wire is now live.
- `AuthMiddleware` (Bearer or cookie; redirects to `/login` for HTML, 401 for JSON) and `AdminMiddleware` (gates routes on `users.is_admin`).
- `AuditLogger` writing to a new `audit` log channel (`.logs/audit.log` by default) for signup, login, logout, permission-denied, and generic auth-failure events.
- PSR-14 dispatch for `UserCreated`, `UserLoggedIn`, `UserLoggedOut` events via the shared FQCNs in `Phlix\Shared\Events\Auth\*`.
- First-user auto-promotion to admin during signup (matches phlix-server's bootstrap policy from SESSION_HANDOFF.md decision #7).
- Smarty templates under `public/templates/{layouts,auth,home}/` for the SSR pages.
- `config/auth.php` plus `HUB_JWT_SECRET`, `HUB_JWT_ACCESS_TTL`, `HUB_JWT_REFRESH_TTL` env vars.
- Two new service providers in the container: `AuthServicesProvider`, `HttpServicesProvider`.
- `scripts/smoke-jwt-roundtrip.php` — minimal smoke proving the JwtHandler ↔ JwtClaims round-trip.
- `docs/hub/signup-login.md` — end-user guide for the signup/login flow.
- Expanded `docs/dev/architecture-hub.md` with request lifecycle, auth flow Mermaid diagrams, and the JwtClaims wire description.
- `docs/reference/api/hub-auth.yaml` — OpenAPI 3.0 spec for `/api/v1/auth/*` and `/api/v1/me`.
- Database schema: `users`, `servers`, `server_claims`, `server_heartbeats`, `shared_libraries`, `relay_sessions`, `webhooks` (migrations `001_users.sql` through `005_webhooks.sql`).
- `Phlix\Hub\Common\Database\MigrationRunner` — idempotent runner backed by a `migrations` tracking table; replaces the placeholder migration runner.
- `tests/Common/Database/MigrationRunnerTest.php` — unit coverage for the runner (file discovery, idempotency, statement splitting, error wrapping).
- `tests/Unit/Migrations/MigrationFileTest.php` — static checks on every migration file (header comment, InnoDB + utf8mb4 declaration, balanced parens, `CHAR(36)` PKs).
- `tests/Integration/Migrations/MigrationRunnerIntegrationTest.php` — live-DB integration test driven by `HUB_TEST_DB_*` env vars; skipped automatically when env is missing or the cluster runs Group Replication multi-primary.
- `docs/dev/schema.md` — canonical schema reference with mermaid ER diagram and per-table documentation.
- `migrations/006_server_heartbeats_sent_at.sql` — adds nullable `sent_at DATETIME` column to `server_heartbeats` for clock-skew detection against `received_at`. Persists `HeartbeatDto::$timestamp` ahead of the C.3 heartbeat handler.

### Removed
- `migrations/001_placeholder.sql` — superseded by the real migrations.

## [0.1.0] — 2026-05-17

### Added
- Initial scaffolding: Workerman 5 HTTP application, PSR-11 container (PHP-DI 7), structured logger (Monolog 3), `/health` endpoint.
- Composer dependency on `detain/phlix-shared:^0.2` consumed via Composer VCS repository (Packagist publication deferred to v1.0).
- 5-check CI workflow (composer-validate, phpcs PSR-12, phpstan 2.x level 9, psalm v5, security audit) + phpunit.
- `migrations/` directory with placeholder; real schema lands in B.6.
- `docs/reference/env-vars.md` listing every `HUB_*` env var.
- `docs/dev/architecture-hub.md` placeholder pointing at the cross-repo design docs in `detain/phlix`.

### Notes
- DB schema and migrations land in B.6. Signup/login MVP lands in B.7.
