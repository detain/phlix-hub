# Environment variables — `phlix-hub`

Every runtime setting `phlix-hub` reads is exposed as an environment
variable prefixed with `HUB_`. The configuration files under `config/`
are thin wrappers that fall back to safe development defaults when a
variable is unset.

## HTTP worker (`config/server.php`)

| Variable             | Default                       | Description                                                                  |
| -------------------- | ----------------------------- | ---------------------------------------------------------------------------- |
| `HUB_HOST`           | `0.0.0.0`                     | Bind address for the Workerman HTTP worker.                                  |
| `HUB_PORT`           | `8800`                        | TCP port the worker listens on.                                              |
| `HUB_WORKERS`        | `2`                           | Number of worker processes Workerman should fork.                            |
| `HUB_WORKERMAN_LOG`  | `<repo>/.logs/workerman.log`  | Path Workerman writes its master-log to. Directory must exist or be writable. |

## Database (`config/database.php`)

| Variable          | Default       | Description                                       |
| ----------------- | ------------- | ------------------------------------------------- |
| `HUB_DB_HOST`     | `127.0.0.1`   | MySQL host the hub connects to.                   |
| `HUB_DB_PORT`     | `3306`        | MySQL port.                                       |
| `HUB_DB_USER`     | `phlix_hub`   | MySQL username.                                   |
| `HUB_DB_PASSWORD` | `phlix_hub`   | MySQL password. **Override in any non-dev env.**  |
| `HUB_DB_NAME`     | `phlix_hub`   | Database name.                                    |

## Auth (`config/auth.php`)

| Variable               | Default   | Description                                                                                                                                  |
| ---------------------- | --------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `HUB_JWT_SECRET`       | (dev fallback) | HMAC-SHA256 secret for issuing JWTs. **Required in production** — must be ≥32 bytes. Falls back to a random per-process secret in dev. |
| `HUB_JWT_ACCESS_TTL`   | `3600`    | Access-token lifetime in seconds (default 1 hour).                                                                                           |
| `HUB_JWT_REFRESH_TTL`  | `604800`  | Refresh-token lifetime in seconds (default 7 days).                                                                                          |

When `HUB_JWT_SECRET` is unset, `AuthServicesProvider` generates a
random secret at container-build time. Tokens issued with that secret
are valid only for the lifetime of the current PHP process — restarting
the worker invalidates every existing session. Always set this var
explicitly in production.

## Container caching

| Variable                       | Default | Description                                                                                                                       |
| ------------------------------ | ------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `PHLIX_HUB_CONTAINER_COMPILE`  | unset   | When truthy (`1`, `true`, `yes`, `on`), PHP-DI writes compiled definitions to `var/cache/container/` for faster cold-start. Off for dev. |

## Logging

The logger config (`config/logger.php`) is currently file-based only —
no env knobs. Channels: `application`, `http`, `error`, `hub`, `relay`
(see `src/Common/Logger/LogChannels.php`).

## Examples

```bash
# Local dev with default ports, default MySQL creds
php public/index.php start

# Different port and DB
HUB_PORT=9000 HUB_DB_HOST=mysql.internal php public/index.php start

# Production with compiled container
PHLIX_HUB_CONTAINER_COMPILE=1 \
  HUB_DB_HOST=db.prod \
  HUB_DB_PASSWORD="$(cat /run/secrets/hub-db)" \
  HUB_WORKERS=8 \
  php public/index.php start
```
