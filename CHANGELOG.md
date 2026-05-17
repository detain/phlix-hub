# Changelog

All notable changes to `detain/phlex-hub` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- User signup, login, logout, and `/my-servers` dashboard MVP. Routes: `GET /signup`, `POST /signup`, `GET /login`, `POST /login`, `POST /logout`, `GET /my-servers`, plus JSON variants `POST /api/v1/auth/{signup,login,logout,refresh}` and `GET /api/v1/me`.
- **Step C.4 — My Servers dashboard**: `GET /api/v1/me/servers` (JSON list of claimed servers), `DELETE /api/v1/me/servers/{id}` (remove a claimed server), `GET /api/v1/me/servers/{id}/access-info` (best direct/relay URL). SSR pages: `GET /my-servers` (server cards with status badges, last-seen, version, hostnames), `GET /claim-server` (claim-code entry form). Smarty templates: `home/my-servers.tpl`, `home/claim-server.tpl`, `partials/server-card.tpl`. Client-side `my-servers.js` handles remove-with-confirmation. CSS in `app.css` covers server cards, status badges, empty states, claim form.
- JWT auth using the shared `Phlex\Shared\Auth\JwtClaims` shape. `JwtHandler::validateAccessToken()` returns a hydrated `JwtClaims` instance — the cross-repo wire is now live.
- `AuthMiddleware` (Bearer or cookie; redirects to `/login` for HTML, 401 for JSON) and `AdminMiddleware` (gates routes on `users.is_admin`).
- `AuditLogger` writing to a new `audit` log channel (`.logs/audit.log` by default) for signup, login, logout, permission-denied, and generic auth-failure events.
- PSR-14 dispatch for `UserCreated`, `UserLoggedIn`, `UserLoggedOut` events via the shared FQCNs in `Phlex\Shared\Events\Auth\*`.
- First-user auto-promotion to admin during signup (matches phlex-server's bootstrap policy from SESSION_HANDOFF.md decision #7).
- Smarty templates under `public/templates/{layouts,auth,home}/` for the SSR pages.
- `config/auth.php` plus `HUB_JWT_SECRET`, `HUB_JWT_ACCESS_TTL`, `HUB_JWT_REFRESH_TTL` env vars.
- Two new service providers in the container: `AuthServicesProvider`, `HttpServicesProvider`.
- `scripts/smoke-jwt-roundtrip.php` — minimal smoke proving the JwtHandler ↔ JwtClaims round-trip.
- `docs/hub/signup-login.md` — end-user guide for the signup/login flow.
- Expanded `docs/dev/architecture-hub.md` with request lifecycle, auth flow Mermaid diagrams, and the JwtClaims wire description.
- `docs/reference/api/hub-auth.yaml` — OpenAPI 3.0 spec for `/api/v1/auth/*` and `/api/v1/me`.
- Database schema: `users`, `servers`, `server_claims`, `server_heartbeats`, `shared_libraries`, `relay_sessions`, `webhooks` (migrations `001_users.sql` through `005_webhooks.sql`).
- `Phlex\Hub\Common\Database\MigrationRunner` — idempotent runner backed by a `migrations` tracking table; replaces the placeholder migration runner.
- `tests/Common/Database/MigrationRunnerTest.php` — unit coverage for the runner (file discovery, idempotency, statement splitting, error wrapping).
- `tests/unit/Migrations/MigrationFileTest.php` — static checks on every migration file (header comment, InnoDB + utf8mb4 declaration, balanced parens, `CHAR(36)` PKs).
- `tests/integration/Migrations/MigrationRunnerIntegrationTest.php` — live-DB integration test driven by `HUB_TEST_DB_*` env vars; skipped automatically when env is missing or the cluster runs Group Replication multi-primary.
- `docs/dev/schema.md` — canonical schema reference with mermaid ER diagram and per-table documentation.
- `migrations/006_server_heartbeats_sent_at.sql` — adds nullable `sent_at DATETIME` column to `server_heartbeats` for clock-skew detection against `received_at`. Persists `HeartbeatDto::$timestamp` ahead of the C.3 heartbeat handler.

### Removed
- `migrations/001_placeholder.sql` — superseded by the real migrations.

## [0.1.0] — 2026-05-17

### Added
- Initial scaffolding: Workerman 5 HTTP application, PSR-11 container (PHP-DI 7), structured logger (Monolog 3), `/health` endpoint.
- Composer dependency on `detain/phlex-shared:^0.2` consumed via Composer VCS repository (Packagist publication deferred to v1.0).
- 5-check CI workflow (composer-validate, phpcs PSR-12, phpstan 2.x level 9, psalm v5, security audit) + phpunit.
- `migrations/` directory with placeholder; real schema lands in B.6.
- `docs/reference/env-vars.md` listing every `HUB_*` env var.
- `docs/dev/architecture-hub.md` placeholder pointing at the cross-repo design docs in `detain/phlex`.

### Notes
- DB schema and migrations land in B.6. Signup/login MVP lands in B.7.
