# AGENTS.md — detain/phlix-hub

Agent brief for the `phlix-hub` package. The hub is the multi-server
cloud directory + relay layer. It is **not** the media server — keep
library scanning, transcoding, FFmpeg, HLS, DLNA, and Live TV out of
this repo.

## Conventions

- **PHP 8.3+**. Modern features (readonly properties, enums, first-class
  callable syntax) are welcome where they aid clarity.
- **`declare(strict_types=1);`** at the top of every PHP file.
- **PSR-12** coding standard, enforced by phpcs.
- **PSR-4 autoload** — `Phlix\Hub\` → `src/`, `Phlix\Hub\Tests\` →
  `tests/`. Namespaces mirror directories.
- **Static analysis bar:** PHPStan level 9 and Psalm errorLevel 1 — both
  green from day 1. No baselines.
- **Database access:** only `Workerman\MySQL\Connection`. No raw PDO,
  no mysqli. Pass parameters as bound parameters; do not interpolate
  user input into SQL strings. **Always use named `:param` placeholders,
  not positional `?`.** `workerman/mysql` keys bound parameters by array
  key: `bindMore()` calls `array_keys()` on the bind array and feeds the
  result to `PDOStatement::bindParam()`, which rejects 0-based indices
  with `Argument #1 must be >= 1`. See
  `src/Common/Database/MigrationRunner.php::recordApplied()` for an
  example.
- **Logging:** always via `LoggerFactory::get(LogChannels::*)`. Channels
  defined in `src/Common/Logger/LogChannels.php`.
- **Container:** PHP-DI 7 (`Phlix\Hub\Common\Container\ContainerFactory`).
  Register new services through a `ServiceProviderInterface`
  implementation; do not call `set()` on the container directly.
- **Events:** Tukio (PSR-14). Event DTOs live in
  `Phlix\Shared\Events\*` (the `detain/phlix-shared` package).
- **Shared types:** any DTO that travels between `phlix-server` and
  `phlix-hub` (claim request/response, server info, JWT claims) lives
  in `detain/phlix-shared`. Do not duplicate.
- **PHPDoc on every public class and method.** `@package`, `@since`,
  parameter and return tags as appropriate. Static analysers depend on
  it.

## Layout (intended, fills in across v0.x)

```
src/
  Application.php
  Version.php
  Common/
    Container/    # PSR-11 container factory + providers
    Database/     # ConnectionPool wrapper around workerman/mysql
    Logger/       # LoggerFactory, LogChannels, StructuredLogger
  Http/           # Request, Response, Router
  Health/         # GET /health controller
  # (B.6+ adds Auth/, Hub/, Relay/, WebPortal/)
config/
  server.php database.php logger.php
migrations/
public/
  index.php       # Workerman HTTP entry
scripts/
  run-migrations.php
tests/
  (mirror of src/, PHPUnit 10)
```

## Layout rationale

See `plans/expansion/b.1-shared-design.md` in
[`detain/phlix`](https://github.com/detain/phlix) for the cross-repo
design context (what goes where, what stays in `phlix-server`, what
moves to `phlix-shared`). Do not re-litigate that design here —
propose changes in a new plan step against `detain/phlix` if needed.

## Before committing

1. `composer install` resolves clean.
2. `./vendor/bin/phpunit` green.
3. `./vendor/bin/phpstan analyze --no-progress` green.
4. `./vendor/bin/phpcs --standard=PSR12 src/` clean.
5. `./vendor/bin/psalm --no-progress` clean.
6. `composer validate --strict` clean.
7. `composer audit --no-dev` no advisories.

If any tool emits warnings, fix the code — do not add to a baseline.

## Versioning

[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Bump
`Phlix\Hub\Version::VERSION` in lockstep with the git tag and the
`CHANGELOG.md` heading.
