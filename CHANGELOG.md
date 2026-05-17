# Changelog

All notable changes to `detain/phlex-hub` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
