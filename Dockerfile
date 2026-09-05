# ============================================================================
# Phlix Hub — application image (Alpine)
# ----------------------------------------------------------------------------
# Builds on the shared base image (ghcr.io/detain/phlix-base) which already
# contains PHP + Swoole + UV + Composer. Only the cheap application layers live
# here, so editing this file does NOT recompile the PHP extensions.
#
# The base image is built and published by the phlix-server repository's
# .github/workflows/docker.yml (the `docker-base` job). To build the hub image
# locally without recompiling extensions, either pull the published base or
# build it once from phlix-server/docker/Dockerfile.base and tag it
# ghcr.io/detain/phlix-base:latest. PHLIX_BASE_IMAGE overrides the reference.
#
# ============================================================================
# SERVING MODEL (S300): this image runs the Workerman daemon (`start.php`) and
# NOTHING else — no nginx, no php-fpm, no supervisord.
# ============================================================================
# What this file used to do, and why NONE of it could ever have worked:
#
#   * `CMD ["sh", "/docker-entrypoint.sh"]` named a path nothing wrote.
#     `COPY . /var/www/html/` lands the script at
#     /var/www/html/docker/docker-entrypoint.sh, so every container ever built
#     from this file died in under a second with
#         sh: can't open '/docker-entrypoint.sh': No such file or directory
#     and exit code 2. MEASURED on 2026-08-10 against master @ 4e8828d, built
#     from ghcr.io/detain/phlix-base:latest. (Identical to the S159 finding-4
#     defect in phlix-server, which fixed its own copy and left this one.)
#   * The entrypoint it could not reach ran `php public/index.php start`.
#     **`public/index.php` does not exist in this repository** — `public/` holds
#     `assets/` and nothing else. The daemon is `start.php`.
#   * `COPY docker/supervisord.conf` installed a supervisord config declaring
#     `[program:php-fpm]` and `[program:nginx]`. The shared base is
#     `php:8.3-cli-alpine` and deliberately ships NEITHER binary
#     (phlix-server/docker/Dockerfile.base says so in as many words, and names
#     this image as the consequence). Nothing in the CMD path started
#     supervisord anyway.
#   * `COPY docker/nginx.conf` installed a vhost that `fastcgi_pass`es to
#     127.0.0.1:9000 — a port nothing in this image listens on, for an nginx
#     that is not installed.
#   * The entrypoint gated migrations on `PHLIX_DATABASE_HOST`, a variable NO
#     php in this repository reads: config/database.php reads `HUB_DB_*`.
#   * `EXPOSE 80 443` described that absent nginx. The daemon binds 8800/8802/
#     8803/8804.
#   * `chown -R nobody:nobody /var/www/html` ran BEFORE `COPY . /var/www/html/`,
#     so every application file landed root-owned and the `nobody` daemon could
#     not create `.logs/` (Worker::$logFile) or `var/` (Worker::$pidFile).
#
# All of that was invisible because **`docker build` never executes CMD,
# ENTRYPOINT or HEALTHCHECK**, and the only job that built this image lived in
# the OTHER repository (phlix-server/.github/workflows/docker.yml, `ref: master`)
# where it reported "Build and Push phlix-hub: pass" on unrelated PRs.
#
# The gate that now covers the runtime path is `scripts/docker-boot-smoke.sh`,
# run by the `docker-boot-gate` job in THIS repository's
# .github/workflows/docker.yml on every pull request, against the PR's own ref.
# ============================================================================
ARG PHLIX_BASE_IMAGE=ghcr.io/detain/phlix-base:latest
FROM ${PHLIX_BASE_IMAGE}

# PHP overrides (Alpine layout — the official php:* image scans
# /usr/local/etc/php/conf.d/ for EVERY SAPI, including the CLI the daemon uses).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-phlix.ini

# NB: written out, not brace-expanded. Docker runs `RUN` through /bin/sh, which
# on Alpine is busybox ash and does NOT do brace expansion — the previous
# `/var/phlix/{config,data,logs,backups}` created ONE directory with that
# literal name. (Same trap phlix-server documented in its own Dockerfile.)
RUN mkdir -p /var/www/html \
        /var/phlix/config /var/phlix/data /var/phlix/logs /var/phlix/backups \
    && chown -R nobody:nobody /var/www/html /var/phlix

WORKDIR /var/www/html

# Composer install in two stages so the vendor layer caches across builds —
# only invalidated when composer.{json,lock} change, not on every source edit.
#
# `--ignore-platform-reqs` is deliberately NOT passed. composer.json's only
# platform requirement today is `php: ^8.3`, so the flag hid nothing — but it
# would hide the FIRST `ext-*` requirement anyone adds, exactly as it hid
# ext-ldap from every phlix-server image until S163. Without it a missing
# extension fails the BUILD instead of the running container.
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader

COPY . /var/www/html/

# chown AFTER the COPY, not before: `COPY` writes root-owned files, and the
# daemon runs as `nobody`. `.logs/` is Worker::$logFile's directory and `var/`
# holds Worker::$pidFile / $statusFile (config/server.php `pid_file`), both of
# which start.php creates with @mkdir — an @-suppressed failure that leaves
# Workerman unable to save its master pid.
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p /var/www/html/.logs /var/www/html/var \
    && chown -R nobody:nobody /var/www/html

# THE line whose absence made every container exit 2 (see the header). The CMD
# at the bottom of this file names `/docker-entrypoint.sh`; this is what puts a
# file there. tests/Unit/Docker/DockerImageContractTest.php asserts the pair.
COPY docker/docker-entrypoint.sh /docker-entrypoint.sh

# The ports the DAEMON actually binds (src/Application.php + config/server.php):
#   8800 HTTP (REST + the /app SPA + static assets)
#   8802 relay WebSocket, server side   (RelayWorker)
#   8803 relay WebSocket, client side   (ClientRelayWorker::DEFAULT_PORT)
#   8804 SyncPlay relay                 (SyncPlayRelayWorker::DEFAULT_PORT)
# The previous `EXPOSE 80 443` described an nginx that is not in the image.
EXPOSE 8800 8802 8803 8804

# Without this, a container in which the application never started reports
# `Up` forever. /health is served by the daemon itself (Application::
# registerRoutes) and answers `{"status":"ok","service":"phlix-hub",…}`, so it
# is only reachable when the app is genuinely up.
#
# start-period is 90s, matching phlix-server: long enough that a first boot's
# migration chain does not flap the container to `unhealthy`, and SHORT enough
# that `unhealthy` is actually reachable while a gate is watching. A start
# period that outlives the observer makes the state decorative — phlix-server
# shipped 180s and its boot gate could never see a bad one (S163 review F1).
# scripts/docker-boot-smoke.sh asserts this stays <= MAX_START_PERIOD.
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8800/health || exit 1

USER nobody

RUN false  # S429 scratch break — runner-side Boot gate RED proof — DO NOT MERGE

CMD ["sh", "/docker-entrypoint.sh"]
