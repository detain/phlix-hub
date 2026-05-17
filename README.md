# phlex-hub

Central cloud directory + reverse-tunnel relay for Phlex media servers.
Sign in once, reach any of your servers from anywhere. Self-hostable.

Status: **server claim + my-servers dashboard (C.4)**. Pairing Protocol
landed in C.3; the populated `/my-servers` dashboard and the
`/claim-server` flow land in C.4.

## Quick start (dev)

```bash
composer install
# Point the runner at a real MySQL via HUB_DB_* env vars first.
php scripts/run-migrations.php       # creates users / servers / shared_libraries / relay_sessions / webhooks
php public/index.php start
curl http://localhost:8800/health    # => {"status":"ok",...}
```

Visit `http://localhost:8800/signup` to create your first account. The
first user is auto-promoted to admin. After signing in, `/my-servers`
lists your registered servers and `/claim-server` walks through pairing
a new one.

See [`docs/dev/schema.md`](docs/dev/schema.md) for the schema
reference, [`docs/reference/env-vars.md`](docs/reference/env-vars.md)
for the full env-var list, and
[`docs/hub/my-servers.md`](docs/hub/my-servers.md) /
[`docs/hub/claim-server.md`](docs/hub/claim-server.md) for the
dashboard and claim flow.

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

DB schema landed in B.6; signup, login, the dashboard, and the JWT
auth stack (consuming `Phlex\Shared\Auth\JwtClaims`) landed in **B.7**.
The server-claim + registry endpoints (claim codes, enrollment JWT,
heartbeat) landed in **C.3**; the `/my-servers` dashboard and
`/claim-server` UI land in **C.4**.

## Configuration

All runtime options are environment variables — see
[`docs/reference/env-vars.md`](docs/reference/env-vars.md) for the full
list.

## License

MIT — see [`LICENSE`](LICENSE).
