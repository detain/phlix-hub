# Changelog

All notable changes to `detain/phlix-hub` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
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
