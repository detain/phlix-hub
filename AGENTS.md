# AGENTS.md — detain/phlix-hub

Agent brief for `phlix-hub`: the multi-server cloud directory + reverse-tunnel relay. It is **not** the media server — keep library scanning, transcoding, FFmpeg, HLS, DLNA, and Live TV out of this repo. PHP 8.3 on Workerman 5.1 + Swoole coroutines.

## Commands

```bash
php start.php start            # HTTP :8800, relay :8802 (servers) / :8803 (clients)
php bin/phlix migrate          # apply migrations/*.sql via MigrationRunner
./vendor/bin/phpunit           # PHPUnit 10
./vendor/bin/phpstan analyze --no-progress   # level 9, no baseline
./vendor/bin/psalm --no-progress             # errorLevel 1
./vendor/bin/phpcs --standard=PSR12 src/     # PSR-12
composer validate --strict
php scripts/security-audit-check.php          # S246: audits runtime AND require-dev, prints the corpus
cd web-ui && npm install && npm run build     # Vite SPA -> public/assets/app/
```

Container, provisioning, and CI:

```bash
docker compose up                  # local stack: docker/docker-entrypoint.sh + docker/nginx.conf
bash scripts/install.sh            # provision PHP deps + system prerequisites
php scripts/run-migrations.php     # standalone CLI migration runner
ls .github/workflows/              # CI pipelines: phpunit + phpstan + psalm + phpcs
```

## Architecture

**Entry** `start.php` → `src/Application.php` boots routes + Workerman workers. **Container** PHP-DI 7 `src/Common/Container/ContainerFactory.php` — register services via `ServiceProviderInterface`, never `set()`.

- `src/Http/` — `Request.php` · `Response.php` · `Router.php` (regex `{id}` params) · `ConnectionResponseSink.php` (chunked relay→HTTP streaming, per-user throttle) · `Controllers/` · `Middleware/` (`AuthMiddleware`, `AdminMiddleware`, `EnrollmentJwtMiddleware`, `HubProtocolMiddleware`) · `RequestContext.php` (coroutine-local user id).
- `src/Hub/` — claim/heartbeat/renew/deregister/sharing handlers, `EnrollmentJwtService`+`Ed25519KeyManager`, `RelaySessionManager` (per-user `throttle_bps`), `DnsAliasManager`, `TlsCertificateManager`, DTOs.
- `src/Relay/` — `RelayWorker` (:8802), `ClientRelayWorker` (:8803), `Tunnel`/`TunnelManager`, `TokenBucket` (per-user byte-rate throttle), `Frame{Encoder,Decoder}`.
- `src/Federation/`, `src/Auth/` (`AuthManager`, `JwtHandler` HS256, `UserRepository`), `src/Common/{Database,Http,Logger,RateLimit}/` (`Common/Http/TrustedProxyResolver.php` resolves the real client IP via `TRUSTED_PROXIES` for IP-keyed limiters), `src/Health/`.
- `config/{server,database,logger,auth}.php` · `migrations/` · `web-ui/` (`@phlix/hub-web-ui` consuming `@phlix/ui`, built to `public/assets/app/`) · `tests/` mirror src. **The legacy Smarty page UI was removed** (`smarty/smarty` dropped, no `public/templates/` or `public/assets/js/`); the `/app` Vue SPA is the only UI and legacy page paths 302-redirect to it.
- `docker/` (`docker-entrypoint.sh`, `nginx.conf` — container image) · `scripts/` (`install.sh` provisioning, `run-migrations.php` standalone migrator) · `.github/workflows/` (CI gates) · `.opencode/` (`memory`, `skills`, `package.json`) + `.remember/` (cross-session agent context).

## Conventions

- `declare(strict_types=1);` everywhere; PSR-4 `Phlix\Hub\`→`src/`, `Phlix\Hub\Tests\`→`tests/`.
- **DB**: only `Workerman\MySQL\Connection` with named `:param` placeholders (positional `?` breaks `bindMore()`); no PDO/mysqli; no string interpolation. Example: `src/Common/Database/MigrationRunner.php`.
- **Logging**: `LoggerFactory::get(LogChannels::*)` (`src/Common/Logger/LogChannels.php`).
- **Controllers**: `final`, return `Response->json([...'error','code'])`, gate on `$request->userId` (401 `auth.required`), map handler exception codes to HTTP.
- **Migrations**: `-- migration: NNN_name` header, `CREATE TABLE IF NOT EXISTS`, `ENGINE=InnoDB` utf8mb4, `CHAR(36)` PK — enforced by `tests/Unit/Migrations/MigrationFileTest.php`. Column/index DDL must be **plain** (`ADD COLUMN …`, `CREATE INDEX …`); **never** write `IF [NOT] EXISTS` on a column/index/key — that is MariaDB-only and the MySQL 8 deploy target rejects it (1064). Idempotency comes from the `MigrationRunner` tracking table (each file applied once), not from `IF NOT EXISTS` guards. Editing an already-applied file changes its stored `checksum` (migration `041_migrations_checksum`) and the runner warns + re-applies it, so keep DDL re-run-safe.
- **Shared types**: cross-repo DTOs live in the `Phlix\Shared\*` namespace (the shared composer package); do not duplicate.
- PHPStan 9 + Psalm 1 green, **no baselines**; PHPDoc on public API.

## Before committing

`composer install` clean → `phpunit` green → `phpstan` green → `phpcs` clean → `psalm` clean → `composer validate --strict` → `php scripts/security-audit-check.php` green. Fix code, never add a baseline.

⚠ **Never audit with a development-dependency exclusion.** That flag hid CVE-2026-67434 (HIGH) in `squizlabs/php_codesniffer` from CI for as long as the advisory existed — the job reported SUCCESS against a vulnerable lock. `scripts/security-audit-check.php` audits the whole lock, labels each finding `[require]`/`[require-dev]`, and prints the number of packages it examined. An advisory that genuinely cannot be actioned goes under `config.audit.ignore` in `composer.json` with a written reason, where the gate reports it as IGNORED — there is no baseline file.

## Versioning

SemVer. Bump `Phlix\Hub\Version::VERSION` in lockstep with the git tag and the `CHANGELOG.md` heading.

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
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, read `.agents/skills/setup-caliber/SKILL.md` and follow its instructions to install Caliber.
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
If the pre-commit hook is not set up, read `.agents/skills/setup-caliber/SKILL.md` and follow the setup instructions.
<!-- /caliber:managed:sync -->
