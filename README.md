# phlex-hub

Central cloud directory + reverse-tunnel relay for Phlex media servers.
Sign in once, reach any of your servers from anywhere. Self-hostable.

Status: **scaffolding (v0.1.0 + B.6 schema)**. B.7 (signup/login
MVP) lands next.

## Quick start (dev)

```bash
composer install
# Point the runner at a real MySQL via HUB_DB_* env vars first.
php scripts/run-migrations.php       # creates users / servers / shared_libraries / relay_sessions / webhooks
php public/index.php start
curl http://localhost:8800/health    # => {"status":"ok",...}
```

See [`docs/dev/schema.md`](docs/dev/schema.md) for the schema
reference, and [`docs/reference/env-vars.md`](docs/reference/env-vars.md)
for the full env-var list.

## Related repos

- [`detain/phlex`](https://github.com/detain/phlex) (a.k.a. `phlex-server`) — local media server.
- [`detain/phlex-shared`](https://github.com/detain/phlex-shared) — shared interfaces, DTOs.

## What is shipped in v0.1.0

- Workerman 5 HTTP application bootstrap.
- PSR-11 container (PHP-DI 7), structured logger (Monolog 3), MySQL
  connection pool (Workerman MySQL).
- `GET /health` endpoint returning service + version metadata.
- 5-check CI workflow (composer-validate, phpcs PSR-12, phpstan 2.x
  level 9, psalm v5, security audit) + phpunit.

DB schema and the migrations land in **B.6** (this release). Signup,
login, and the web portal MVP land in **B.7**.

## Configuration

All runtime options are environment variables — see
[`docs/reference/env-vars.md`](docs/reference/env-vars.md) for the full
list.

## License

MIT — see [`LICENSE`](LICENSE).
