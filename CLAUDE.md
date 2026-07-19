# CLAUDE.md — phlix-hub

Multi-server cloud directory + reverse-tunnel relay for Phlix media servers. This repo is **not** the media server — keep scanning/transcoding/FFmpeg/HLS/DLNA out. PHP 8.3 on Workerman 5.1 + Swoole coroutines.

## Commands

```bash
composer install                              # deps
php start.php start                           # run HTTP :8800 + relay :8802/:8803
php bin/phlix migrate                         # apply migrations/*.sql
php bin/phlix smoke:jwt                        # JwtHandler<->JwtClaims roundtrip
./vendor/bin/phpunit                           # tests (PHPUnit 10)
./vendor/bin/phpstan analyze --no-progress     # level 9, no baseline
./vendor/bin/psalm --no-progress               # errorLevel 1
./vendor/bin/phpcs --standard=PSR12 src/       # PSR-12
```

SPA: `cd web-ui && npm install && npm run build` → emits to `public/assets/app/` (read via `src/Http/ViteAssets.php`).

Container + provisioning (outside Workerman):

```bash
docker compose up                              # local stack: docker/docker-entrypoint.sh + docker/nginx.conf
bash scripts/install.sh                        # provision PHP deps + system prerequisites
php scripts/run-migrations.php                 # standalone CLI migration runner
```

CI mirrors the local gates:

```bash
ls .github/workflows/                          # CI pipelines: phpunit + phpstan + psalm + phpcs
```

## Architecture

**Entry**: `start.php` (sets Swoole event loop) → `src/Application.php` (`boot()` registers routes + workers). **Container**: PHP-DI 7 `src/Common/Container/ContainerFactory.php` — register via `ServiceProviderInterface`, never `set()`.

**HTTP** (`src/Http/`): `Request.php` · `Response.php` · `Router.php` (regex `{id}` params) · controllers in `Http/Controllers/` · middleware `AuthMiddleware`/`AdminMiddleware`/`EnrollmentJwtMiddleware`/`HubProtocolMiddleware`. Per-request user id via `Http/RequestContext.php` (coroutine-local `support\Context`).

**Domain** (`src/Hub/`): claim/enroll/heartbeat/relay/sharing handlers + `EnrollmentJwtService` (Ed25519), `Ed25519KeyManager`, `RelaySessionManager`, `DnsAliasManager`, `TlsCertificateManager`, DTOs (`LibraryShare`, `InviteLink`, `SharedLibraryDto`). **Relay** (`src/Relay/`): `RelayWorker` (:8802 servers) · `ClientRelayWorker` (:8803 clients) · `Tunnel`/`TunnelManager` · `RelayProxyManager`/`RelayProxyBridge`/`IdleReaper` · `ClientConnection` · `Frame{Encoder,Decoder}`. **Federation** (`src/Federation/`). **Auth** (`src/Auth/`): `AuthManager` · `JwtHandler` (HS256) · `UserRepository` · `RateLimitException` (429).

**DB**: `src/Common/Database/` — `PhlixMySQLConnection`, `ConnectionPool`, `PooledMySQLConnection`, `MigrationRunner`. **Rate limiting** (`src/Common/RateLimit/`): per-surface `RateLimiter` + shared DB-backed `DbRateLimiter` (login), `RateLimitProfiles`. **Relay metrics** (`src/Stats/Metrics/`): `MetricsRegistry`/`MetricsCollector`/`MetricsFlushService`. Logging: `src/Common/Logger/LoggerFactory::get(LogChannels::*)`. **Web UI**: the `/app` Vue SPA (`@phlix/hub-web-ui` consuming `@phlix/ui`, built to `public/assets/app/`, shell served via `src/Http/ViteAssets.php`) is the **only** UI. The legacy Smarty stack (`src/Common/WebPortal/PageRenderer.php`, `PageController`, `CsrfMiddleware`, `public/templates/`, `public/assets/js|css/`) and the `smarty/smarty` dependency were **removed**; legacy page paths 302-redirect to `/app` in `src/Application.php`.

**Ops & tooling**: `docker/` holds `docker-entrypoint.sh` and `nginx.conf` for the container image; `scripts/` holds `install.sh` (provisioning) and `run-migrations.php` (standalone migrator outside the app loop); `.github/workflows/` runs the CI gates (PHPUnit/PHPStan/Psalm/phpcs). Agent context lives in `.opencode/` (`memory`, `skills`, `package.json`) and `.remember/`.

**Config**: `config/{server,database,logger,auth}.php`. **Shared types**: cross-repo DTOs live in the `Phlix\Shared\*` namespace (the shared composer package) — do not duplicate.

## Conventions

- `declare(strict_types=1);` top of every file; PSR-4 `Phlix\Hub\`→`src/`, `Phlix\Hub\Tests\`→`tests/`.
- DB: only `Workerman\MySQL\Connection`, **named `:param` placeholders** (positional `?` breaks `bindMore()`). No PDO/mysqli. See `src/Common/Database/MigrationRunner.php`.
- Controllers return `(new Response())->json([...])` with `error`/`code`; gate on `$request->userId`, 401 `auth.required`.
- IDs: 8-4-4-4-12 hex UUID helper; tables use `CHAR(36)` PK.
- PHPStan 9 + Psalm 1 green, no baselines; PHPDoc on public API.
- Bump `src/Version.php` with the git tag and `CHANGELOG.md`.

@./AGENTS.md

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ AGENTS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
