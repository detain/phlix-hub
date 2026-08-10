#!/usr/bin/env bash
#
# S300 — the phlix-hub Docker BOOT gate.
#
# =============================================================================
# WHY THIS EXISTS
# =============================================================================
# Until S300 this repository had ONE workflow (.github/workflows/ci.yml) and it
# contained no image build at all. The hub image was built by a job in the OTHER
# repository — phlix-server/.github/workflows/docker.yml, "Build and Push
# phlix-hub" — which checks out `detain/phlix-hub` at a hardcoded `ref: master`.
# Three consequences, all measured:
#
#   1. a phlix-hub PR never built its own image, so the Dockerfile could break
#      and every hub check stayed green through merge;
#   2. the image was only ever built from hub `master`, triggered by unrelated
#      phlix-server activity, so the green tick described a commit the
#      triggering PR had nothing to do with (phlix-server PR #662, a DLNA
#      change, shows "Build and Push phlix-hub: pass" — evidence about hub
#      master @ 96b39eb);
#   3. a hub defect reddened a phlix-server PR: misattribution by construction.
#
# And the build was green while the image was DEAD. Measured 2026-08-10 against
# master @ 4e8828d, built from ghcr.io/detain/phlix-base:latest:
#
#     $ docker run -d --name x phlix-hub:master && docker logs x
#     sh: can't open '/docker-entrypoint.sh': No such file or directory
#     $ docker inspect -f '{{.State.ExitCode}}' x
#     2
#
# **`docker build` never executes CMD, ENTRYPOINT or HEALTHCHECK**, so a build
# that succeeds proves nothing whatsoever about the artefact. Nor does an open
# TCP port: Docker's userland proxy `accept()`s on a published host port BEFORE
# it dials the container, so a raw `/dev/tcp` connect succeeds with nothing
# listening inside. Every HTTP probe below therefore uses curl, which
# discriminates (exit 52 = alive but no response, 56 = nothing behind the
# mapping, 7 = no mapping) and reads the RESPONSE BODY.
#
# =============================================================================
# USAGE
# =============================================================================
#   scripts/docker-boot-smoke.sh [image-tag]
#
# Environment:
#   DOCKER            docker command (default `docker`; use `sudo docker` on the
#                     dev box, where the daemon needs root)
#   PHLIX_BASE_IMAGE  base the hub image builds FROM
#                     (default ghcr.io/detain/phlix-base:latest)
#   SKIP_BUILD=1      reuse an already-built <image-tag> (local iteration only)
#   BOOT_PUBLISH=0    do NOT publish a host port; probe the container's bridge
#                     IP instead. LOCAL ESCAPE HATCH ONLY — see the note at
#                     "PUBLISH MODE" below. It DROPS a check from the registry
#                     and says so, loudly, twice.
#   BOOT_NETWORK=default
#                     do NOT create a private bridge; put both containers on
#                     the default bridge and address MySQL by IP. LOCAL ESCAPE
#                     HATCH ONLY, for a host whose iptables cannot program the
#                     DOCKER-FORWARD chain a user-defined network needs. 3306 is
#                     still never published.
#   PROBE_MODE=sidecar
#                     run every curl inside a throwaway container on the same
#                     network (using the image's own curl) instead of on the
#                     host. LOCAL ESCAPE HATCH ONLY, for a host whose firewall
#                     OUTPUT chain drops traffic to container ports. The curl
#                     exit code is preserved either way.
#   KEEP=1            leave the containers up for inspection
#
# Networking rules this script obeys (the dev box is a LIVE server):
#   * MySQL is reachable only on a private bridge network and 3306 is NEVER
#     published;
#   * the app port is published on 127.0.0.1 at a port DOCKER chooses
#     (`-p 127.0.0.1::8800`), never one this script picks — a hand-rolled
#     $RANDOM port loses races against the kernel's ephemeral range.
#
# Everything it creates is suffixed with a per-run token and torn down by exact
# name, so it can never collide with, or delete, an unrelated container.
# =============================================================================

set -euo pipefail

IMAGE_TAG="${1:-phlix-hub-boot:smoke}"
KEEP="${KEEP:-0}"
DOCKER="${DOCKER:-docker}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

RUN_ID="s300$(date +%s)$$"
NET="phlix-hub-boot-net-${RUN_ID}"
MYSQL_NAME="phlix-hub-boot-mysql-${RUN_ID}"
APP_NAME="phlix-hub-boot-app-${RUN_ID}"
CONTROL_NAME="phlix-hub-boot-control-${RUN_ID}"
MYSQL_PASSWORD="hub_boot_${RUN_ID}"
DB_NAME=phlix_hub

BOOT_TIMEOUT="${BOOT_TIMEOUT:-180}"
STABILITY_WINDOW="${STABILITY_WINDOW:-60}"
STABILITY_SAMPLE="${STABILITY_SAMPLE:-15}"
HEALTHY_TIMEOUT="${HEALTHY_TIMEOUT:-120}"
CONTROL_TIMEOUT="${CONTROL_TIMEOUT:-120}"

# A HEALTHCHECK whose start period outlives the gate can never report
# `unhealthy`, which makes the state decorative. phlix-server shipped 180s and
# its own gate could not see a bad one (S163 review F1). Assert ours stays under
# this.
MAX_START_PERIOD="${MAX_START_PERIOD:-120}"

# The ports the daemon binds inside the container (Dockerfile EXPOSE, and
# config/server.php + the DEFAULT_PORT constants).
HTTP_IN_CONTAINER=8800
RELAY_PORTS="8802 8803 8804"

FAILURES=0

# ===========================================================================
# THE CHECK REGISTRY — the gate's guard against ITSELF.
# ===========================================================================
# Adapted from phlix-server/scripts/docker-boot-smoke.sh, which added it after
# an assertion silently stopped running: under `set -euo pipefail` bash abandons
# the remainder of an enclosing block on an arithmetic error and carries on
# after `fi` with the tally UNCHANGED. The run printed "ALL ASSERTIONS PASSED"
# with one check never evaluated.
#
# So every verdict this gate is REQUIRED to reach is named here. At the end, a
# check that produced NO verdict — for any reason at all — is itself a FAILURE,
# and a check that produced TWO is a bug in this script and is reported.
EXPECTED_CHECKS='
image-built
entrypoint-installed
health
health-published
migrations
schema
daemon-process
no-cgi
relay-ports
spa-shell
stability
healthcheck-declared
healthcheck-healthy
healthcheck-start-period
platform-reqs
failure-exit-nonzero
'
RECORDED_CHECKS=''

# PUBLISH MODE ---------------------------------------------------------------
# `health-published` is the only check that exercises the port mapping an
# operator actually uses, so it is in the registry by default and its absence is
# a failure. On a host whose iptables NAT chain cannot program a DNAT rule
# (this dev box: "Unable to enable DNAT rule … No chain/target/match by that
# name"), `docker run -p` fails before a single assertion runs. BOOT_PUBLISH=0
# trades that check away EXPLICITLY — it is removed from the registry, named in
# a warning at the start AND in the summary at the end, and CI never sets it.
BOOT_PUBLISH="${BOOT_PUBLISH:-1}"
if [ "$BOOT_PUBLISH" != "1" ]; then
    # `|| true`: grep -v exits 1 when it emits NOTHING, and this is an
    # ASSIGNMENT — under `set -e` that would abort the gate before it started.
    # (It cannot happen with today's registry, which is exactly why it would go
    # unnoticed the day the registry shrinks.)
    EXPECTED_CHECKS="$(printf '%s\n' "$EXPECTED_CHECKS" | grep -v '^health-published$' || true)"
fi

# ---------------------------------------------------------------------------
say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
pass() { RECORDED_CHECKS="${RECORDED_CHECKS} $1"; printf '   \033[32mPASS\033[0m [%s] %s\n' "$1" "$2"; }
fail() {
    RECORDED_CHECKS="${RECORDED_CHECKS} $1"
    printf '   \033[31mFAIL\033[0m [%s] %s\n' "$1" "$2"
    FAILURES=$(( FAILURES + 1 ))
}

# Any number this gate compares MUST be a number. A value read out of
# `docker inspect`, `wc -l` or `grep -c` that is not a plain unsigned integer is
# a FAILURE, never a skip.
is_uint() {
    case "${1:-}" in
        '' | *[!0-9]*) return 1 ;;
        *) return 0 ;;
    esac
}

for _tunable in BOOT_TIMEOUT STABILITY_WINDOW STABILITY_SAMPLE MAX_START_PERIOD \
                HEALTHY_TIMEOUT CONTROL_TIMEOUT; do
    eval "_tv=\${${_tunable}:-}"
    if [ -n "$_tv" ] && ! is_uint "$_tv"; then
        printf '   \033[31mFAIL\033[0m %s must be an unsigned integer, got %s\n' "$_tunable" "$_tv" >&2
        exit 1
    fi
done
unset _tunable _tv

# Every HTTP probe goes through here. curl, never /dev/tcp or nc: Docker's
# userland proxy accepts on a published host port BEFORE dialling the container,
# so a bare TCP connect succeeds with nothing listening inside. curl
# discriminates — 52 alive-but-empty, 56 nothing behind the mapping, 7 no
# mapping — and gives us the response BODY to assert on.
#
# PROBE_MODE=sidecar runs the same curl inside a throwaway container on the same
# network, using the image's own curl binary, and `docker run` propagates its
# exit code unchanged.
PROBE_MODE="${PROBE_MODE:-host}"
probe_curl() {
    if [ "$PROBE_MODE" = "sidecar" ]; then
        # shellcheck disable=SC2086
        $DOCKER run --rm $NET_ARG --entrypoint curl "$IMAGE_TAG" "$@"
    else
        curl "$@"
    fi
}

# ===========================================================================
# NO PIPELINE IN THIS SCRIPT MAY HAVE AN EARLY-EXITING CONSUMER.
# ===========================================================================
# `set -euo pipefail` is on. `producer | head -N`, `producer | grep -q` and
# `producer | grep -m1` all stop reading before the producer stops writing; the
# producer then dies on SIGPIPE (or, like `docker buildx`, exits non-zero on
# EPIPE), and `pipefail` hands that status to the whole pipeline. Two distinct
# harms, both of which this script had:
#
#   * in a CONDITION (`if printf … | grep -q X`) the pipeline reports FAILURE
#     even though grep matched, so the assertion silently takes the WRONG
#     BRANCH. It only bites once the payload outgrows the 64 KiB pipe buffer —
#     i.e. exactly when a container log is long, i.e. exactly when something is
#     already wrong;
#   * in a plain statement it ABORTS the script mid-diagnostic, skipping the
#     remaining assertions and the check registry.
#
# This is not hypothetical: run 1 of .github/workflows/docker.yml died with
# exit 255 on `docker buildx imagetools inspect … | head -20` (26 lines of
# output), which SKIPPED this gate — and a skipped job reads as SUCCESS.
#
# So: matching is done in bash (`[[ … == *x* ]]` / `=~`), and truncation is done
# by the two helpers below. Both are pipeline-free. Keep it that way; the audit
# is one grep:
#
#   grep -nE '\|[[:space:]]*(head|grep[^|]*-q|grep[^|]*-m)' scripts/docker-boot-smoke.sh
#
# `| wc -l`, `| grep -c`, `| tr`, `| sort`, `| tail` and `| awk` (with no
# `exit`) all consume their whole input and are therefore safe.
# ---------------------------------------------------------------------------

# Print at most $1 lines of $2, prefixed, and SAY how many there were — a
# truncated dump must not read as a short one. `mapfile` consumes the entire
# here-string before the loop runs, so the bound cannot close anything.
print_lines() {
    local max="$1" text="$2" i
    local -a lines=()
    mapfile -t lines <<< "$text"
    printf '   (%s line(s), showing up to %s)\n' "${#lines[@]}" "$max"
    for i in "${!lines[@]}"; do
        [ "$i" -ge "$max" ] && break
        printf '   | %s\n' "${lines[$i]}"
    done
}

# The first $1 characters of $2, for one-line summaries. Pure parameter
# expansion — the `head -c` it replaces was a pipeline.
clip() { printf '%s' "${2:0:$1}"; }

dump_diagnostics() {
    say "DIAGNOSTICS — ${APP_NAME}"
    echo "--- docker ps ---"
    $DOCKER ps -a --filter "name=^${APP_NAME}$" --format '{{.Names}}\t{{.Status}}\t{{.Ports}}' || true
    echo "--- docker inspect .State ---"
    $DOCKER inspect --format '{{json .State}}' "$APP_NAME" 2>/dev/null || true
    echo "--- container logs (last 200) ---"
    $DOCKER logs --tail 200 "$APP_NAME" 2>&1 || true
    echo "--- process table ---"
    print_lines 40 "$($DOCKER exec "$APP_NAME" ps -eo pid,user,args 2>&1 || true)"
    echo "--- /var/phlix/logs + .logs ---"
    $DOCKER exec "$APP_NAME" sh -c \
        'ls -la /var/phlix/logs /var/www/html/.logs 2>&1; tail -n 60 /var/www/html/.logs/*.log 2>&1' || true
}

cleanup() {
    if [ "$KEEP" = "1" ]; then
        say "KEEP=1 — leaving ${APP_NAME} / ${MYSQL_NAME} / ${CONTROL_NAME} / ${NET} up"
        return
    fi
    say "Teardown (exact names only)"
    # List before removing: a bare --filter has matched an unrelated container
    # before, and this box runs four long-lived MySQL containers that must
    # survive every run.
    $DOCKER ps -a --filter "name=^${APP_NAME}$" --filter "name=^${MYSQL_NAME}$" \
        --filter "name=^${CONTROL_NAME}$" \
        --format 'removing {{.Names}} ({{.Image}})' || true
    $DOCKER rm -f "$APP_NAME"     >/dev/null 2>&1 || true
    $DOCKER rm -f "$CONTROL_NAME" >/dev/null 2>&1 || true
    $DOCKER rm -f "$MYSQL_NAME"   >/dev/null 2>&1 || true
    $DOCKER network rm "$NET"     >/dev/null 2>&1 || true
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
say "S300 hub boot gate — Dockerfile -> ${IMAGE_TAG}"
info "run id     : ${RUN_ID}"
info "base image : ${PHLIX_BASE_IMAGE:-ghcr.io/detain/phlix-base:latest}"
if [ "$BOOT_PUBLISH" = "1" ]; then
    info "publish    : 127.0.0.1:<docker-allocated> -> ${HTTP_IN_CONTAINER}"
else
    printf '   \033[33m%s\033[0m\n' \
        "WARNING: BOOT_PUBLISH=0 — probing the container's bridge IP directly."
    printf '   \033[33m%s\033[0m\n' \
        "The [health-published] check is REMOVED from this run's registry: the"
    printf '   \033[33m%s\033[0m\n' \
        "port mapping an operator uses is NOT exercised. CI never sets this."
fi
info "assertions : $(printf '%s\n' "$EXPECTED_CHECKS" | grep -c .) registered"

# ---------------------------------------------------------------------------
# 1. Build. Uncached, into the LOCAL daemon, so the image booted below is
#    exactly the image built here.
# ---------------------------------------------------------------------------
BASE_TAG="${PHLIX_BASE_IMAGE:-ghcr.io/detain/phlix-base:latest}"
# BUILD_NETWORK is a LOCAL escape hatch: on the dev box the default bridge
# cannot reach packagist, so `composer install` inside the build needs
# `--network host`. CI leaves it unset and builds on the default network.
BUILD_NET_ARG=''
[ -n "${BUILD_NETWORK:-}" ] && BUILD_NET_ARG="--network ${BUILD_NETWORK}"
if [ "${SKIP_BUILD:-0}" != "1" ]; then
    say "Building ${IMAGE_TAG} (base=${BASE_TAG})"
    # shellcheck disable=SC2086
    $DOCKER build $BUILD_NET_ARG -f "${REPO_ROOT}/Dockerfile" \
        --build-arg "PHLIX_BASE_IMAGE=${BASE_TAG}" \
        -t "$IMAGE_TAG" "${REPO_ROOT}"
else
    say "SKIP_BUILD=1 — reusing ${IMAGE_TAG}"
fi

IMAGE_ID="$($DOCKER image inspect -f '{{.Id}}' "$IMAGE_TAG" 2>/dev/null || true)"
if [ -n "$IMAGE_ID" ]; then
    pass image-built "${IMAGE_TAG} = ${IMAGE_ID} ($($DOCKER image inspect -f '{{.Size}}' "$IMAGE_TAG") bytes, $($DOCKER image inspect -f '{{len .RootFS.Layers}}' "$IMAGE_TAG") layers)"
else
    fail image-built "no such image after the build: ${IMAGE_TAG}"
    exit 1
fi

# The single byte of Dockerfile that was missing for the whole life of this
# image. Asserted POSITIVELY and before boot so the diagnosis is one line rather
# than an exit code.
say "ASSERT — /docker-entrypoint.sh is IN the image (the CMD names it)"
if $DOCKER run --rm --entrypoint sh "$IMAGE_TAG" -c 'test -r /docker-entrypoint.sh' >/dev/null 2>&1; then
    ENTRY_BYTES="$($DOCKER run --rm --entrypoint sh "$IMAGE_TAG" -c 'wc -c < /docker-entrypoint.sh' 2>/dev/null | tr -d ' \r\n' || true)"
    is_uint "$ENTRY_BYTES" || ENTRY_BYTES=0
    if [ "$ENTRY_BYTES" -lt 100 ]; then
        fail entrypoint-installed "/docker-entrypoint.sh is only ${ENTRY_BYTES} bytes"
    else
        pass entrypoint-installed "/docker-entrypoint.sh present (${ENTRY_BYTES} bytes)"
    fi
else
    fail entrypoint-installed "/docker-entrypoint.sh is NOT in the image — the CMD names a path nothing wrote (the original S300 defect: every container exits 2)"
fi

# ---------------------------------------------------------------------------
# 2. Throwaway MySQL on a private bridge. 3306 is NEVER published.
# ---------------------------------------------------------------------------
say "Starting throwaway MySQL (${MYSQL_NAME}, unpublished)"
# NET_ARG is the `--network` flag every container in this run gets. On the
# default bridge there is no embedded DNS, so the app is pointed at MySQL's IP
# rather than its name; see BOOT_NETWORK in the usage block.
BOOT_NETWORK="${BOOT_NETWORK:-}"
if [ "$BOOT_NETWORK" = "default" ]; then
    NET_ARG=''
    info "BOOT_NETWORK=default — using the default bridge, creating no network"
else
    $DOCKER network create "$NET" >/dev/null
    NET_ARG="--network ${NET}"
fi
# shellcheck disable=SC2086
$DOCKER run -d --name "$MYSQL_NAME" $NET_ARG \
    -e MYSQL_ROOT_PASSWORD="root_${MYSQL_PASSWORD}" \
    -e MYSQL_DATABASE="$DB_NAME" \
    -e MYSQL_USER=phlix_hub \
    -e MYSQL_PASSWORD="$MYSQL_PASSWORD" \
    mysql:8.0 >/dev/null

# `--no-defaults` on every mysql* invocation: the dev box's ~/.my.cnf points at
# the PRODUCTION database.
#
# ⚠ Readiness MUST be probed over TCP, not the unix socket. `mysql:8.0` runs a
# TEMPORARY server during first-init that answers on the socket while TCP :3306
# is still refused; phlix-server's gate booted the app too early on exactly that
# and then passed every assertion against a container with NO SCHEMA.
MYSQL_READY=0
PINGOUT=''
for attempt in $(seq 1 80); do
    PINGOUT="$($DOCKER exec "$MYSQL_NAME" mysqladmin --no-defaults \
        --protocol=TCP -h 127.0.0.1 -P 3306 \
        -uroot -p"root_${MYSQL_PASSWORD}" ping 2>&1 || true)"
    if [[ "$PINGOUT" == *'mysqld is alive'* ]]; then
        # Second gate: the application's OWN user must reach the application's
        # OWN database over TCP. root-alive != app-can-connect.
        if $DOCKER exec "$MYSQL_NAME" mysql --no-defaults --protocol=TCP \
                -h 127.0.0.1 -P 3306 -uphlix_hub -p"$MYSQL_PASSWORD" \
                -e 'SELECT 1' "$DB_NAME" >/dev/null 2>&1; then
            info "mysql ready over TCP after ${attempt} attempt(s)"
            MYSQL_READY=1
            break
        fi
    fi
    sleep 3
done
if [ "$MYSQL_READY" != "1" ]; then
    # The gate's own fixture failing before any assertion could run.
    printf '   \033[31mFAIL\033[0m throwaway MySQL never became TCP-ready: %s\n' \
        "${PINGOUT##*$'\n'}"
    $DOCKER logs --tail 20 "$MYSQL_NAME" 2>&1 || true
    exit 1
fi

# ---------------------------------------------------------------------------
# 3. Boot the image. Deliberately NO HUB_JWT_SECRET: `docker run` out of the box
#    has to work, and the hub refuses to mint tokens without a key, so the
#    entrypoint has to generate and persist one.
# ---------------------------------------------------------------------------
say "Booting ${APP_NAME}"
PUBLISH_ARGS=''
[ "$BOOT_PUBLISH" = "1" ] && PUBLISH_ARGS="-p 127.0.0.1::${HTTP_IN_CONTAINER}"
# On a user-defined network the container NAME resolves; on the default bridge
# it does not, so address MySQL by the IP docker gave it.
DB_HOST_FOR_APP="$MYSQL_NAME"
if [ "$BOOT_NETWORK" = "default" ]; then
    DB_HOST_FOR_APP="$($DOCKER inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$MYSQL_NAME" 2>/dev/null || true)"
    if [ -z "$DB_HOST_FOR_APP" ]; then
        printf '   \033[31mFAIL\033[0m could not read the MySQL container IP\n'
        exit 1
    fi
    info "db host    : ${DB_HOST_FOR_APP} (default bridge, by IP)"
fi
RUN_RC=0
# shellcheck disable=SC2086
RUN_OUT="$($DOCKER run -d --name "$APP_NAME" $NET_ARG $PUBLISH_ARGS \
    -e HUB_DB_HOST="$DB_HOST_FOR_APP" \
    -e HUB_DB_PORT=3306 \
    -e HUB_DB_NAME="$DB_NAME" \
    -e HUB_DB_USER=phlix_hub \
    -e HUB_DB_PASSWORD="$MYSQL_PASSWORD" \
    "$IMAGE_TAG" 2>&1)" || RUN_RC=$?
if [ "$RUN_RC" -ne 0 ]; then
    printf '   \033[31mFAIL\033[0m docker run exited %s — the container never started\n' "$RUN_RC"
    printf '%s\n' "$RUN_OUT"
    exit 1
fi

CONTAINER_IP="$($DOCKER inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$APP_NAME" 2>/dev/null || true)"
if [ "$BOOT_PUBLISH" = "1" ]; then
    # `docker port` prints e.g. `127.0.0.1:49153`, one line per binding.
    # Captured whole, then sliced in bash: the old `| head -1 | sed | tr` chain
    # was three chances to lose a race with pipefail for a value the gate then
    # depends on.
    PORT_RAW="$($DOCKER port "$APP_NAME" "${HTTP_IN_CONTAINER}/tcp" 2>/dev/null || true)"
    PORT_FIRST="${PORT_RAW%%$'\n'*}"
    HTTP_PORT="${PORT_FIRST##*:}"
    HTTP_PORT="${HTTP_PORT//[[:space:]]/}"
    if ! is_uint "${HTTP_PORT:-}"; then
        printf '   \033[31mFAIL\033[0m could not read the published port (got %s)\n' "${HTTP_PORT:-<empty>}"
        $DOCKER port "$APP_NAME" 2>&1 || true
        dump_diagnostics
        exit 1
    fi
    PUBLISHED_URL="http://127.0.0.1:${HTTP_PORT}"
    info "published  : ${PUBLISHED_URL} -> container ${HTTP_IN_CONTAINER}"
else
    HTTP_PORT=''
    PUBLISHED_URL=''
fi
# A container that has ALREADY exited reports "invalid IP" from this template
# (not an empty string), which would otherwise flow into a malformed URL and
# make curl say `rc=3` about a container that is simply dead — a bad diagnosis
# of the commonest failure this gate exists to catch. Name it here instead.
case "$CONTAINER_IP" in
    [0-9]*.[0-9]*.[0-9]*.[0-9]*) ;;
    *)
        printf '   \033[31mFAIL\033[0m [health] the container has no usable address (%s): state=%s exit=%s\n' \
            "${CONTAINER_IP:-<empty>}" \
            "$($DOCKER inspect -f '{{.State.Status}}' "$APP_NAME" 2>/dev/null || echo '?')" \
            "$($DOCKER inspect -f '{{.State.ExitCode}}' "$APP_NAME" 2>/dev/null || echo '?')"
        echo "--- container logs ---"
        $DOCKER logs --tail 40 "$APP_NAME" 2>&1 || true
        dump_diagnostics
        exit 1 ;;
esac
DIRECT_URL="http://${CONTAINER_IP}:${HTTP_IN_CONTAINER}"
info "bridge     : ${DIRECT_URL}"

# The URL the functional assertions use. When publishing is on, prefer it —
# it is the operator's path — and let `health-published` be the check that says
# the mapping works. A SIDECAR probe cannot see the host's loopback mapping, so
# it always uses the container address.
PROBE_URL="${PUBLISHED_URL:-$DIRECT_URL}"
if [ "$PROBE_MODE" = "sidecar" ]; then
    PROBE_URL="$DIRECT_URL"
    info "probe mode : sidecar container (curl from ${IMAGE_TAG} on the same network)"
fi

# ---------------------------------------------------------------------------
# 4. ASSERT — the application SERVES, and it is THIS application.
#
# `"status":"ok"` alone would also be satisfied by any other Phlix service
# behind the mapping, so the discriminator is `"service":"phlix-hub"`, which
# only HealthController emits.
# ---------------------------------------------------------------------------
say "ASSERT — GET /health returns the hub's own healthy body"
HEALTH_BODY=''
CURL_RC=0
deadline=$(( $(date +%s) + BOOT_TIMEOUT ))
while [ "$(date +%s)" -lt "$deadline" ]; do
    CURL_RC=0
    HEALTH_BODY="$(probe_curl -fsS --max-time 5 "${PROBE_URL}/health" 2>/dev/null)" || CURL_RC=$?
    [ "$CURL_RC" -eq 0 ] && break
    if [ "$($DOCKER inspect -f '{{.State.Running}}' "$APP_NAME" 2>/dev/null)" != "true" ]; then
        info "container exited early"
        break
    fi
    HEALTH_BODY=''
    sleep 3
done

HEALTH_FLAT="$(printf '%s' "$HEALTH_BODY" | tr -d '\n')"
if [[ "$HEALTH_FLAT" =~ \"status\"[[:space:]]*:[[:space:]]*\"ok\" ]] \
   && [[ "$HEALTH_FLAT" =~ \"service\"[[:space:]]*:[[:space:]]*\"phlix-hub\" ]]; then
    pass health "/health -> $(clip 200 "$HEALTH_FLAT")"
else
    fail health "/health never returned the hub's healthy body within ${BOOT_TIMEOUT}s (last curl rc=${CURL_RC}; 7=no mapping, 56=nothing behind it, 52=alive but empty)"
    echo "--- verbose curl ---"
    print_lines 20 "$(probe_curl -sS -i --max-time 5 "${PROBE_URL}/health" 2>&1 || true)"
    dump_diagnostics
    exit 1
fi

if [ "$BOOT_PUBLISH" = "1" ]; then
    # The SAME body, over the published mapping, stated as its own verdict:
    # Docker's userland proxy accepts on the host port before dialling the
    # container, so only a real response distinguishes a working mapping from
    # an empty one.
    PUB_RC=0
    PUB_BODY="$(curl -fsS --max-time 5 "${PUBLISHED_URL}/health" 2>/dev/null)" || PUB_RC=$?  # host-side ON PURPOSE: this check IS the host mapping
    PUB_FLAT="${PUB_BODY//$'\n'/}"
    if [ "$PUB_RC" -eq 0 ] && [[ "$PUB_FLAT" =~ \"service\"[[:space:]]*:[[:space:]]*\"phlix-hub\" ]]; then
        pass health-published "the published mapping ${PUBLISHED_URL} reaches the daemon"
    else
        fail health-published "curl exited ${PUB_RC} against ${PUBLISHED_URL}/health — the port mapping does not reach the daemon"
    fi
fi

# ---------------------------------------------------------------------------
# 5. ASSERT — the migration step ran and SUCCEEDED.
#
# /health is DB-free by design (HealthController touches neither DB nor
# filesystem), so the check above cannot tell "the app serves" from "the app
# serves against no schema whatsoever". Read the entrypoint's own banners.
# ---------------------------------------------------------------------------
say "ASSERT — the migration step reported success"
BOOT_LOG="$($DOCKER logs "$APP_NAME" 2>&1 || true)"
# ⚠ These matches are pure bash, NOT `printf … | grep -q`. $BOOT_LOG is a whole
# container log: past the 64 KiB pipe buffer an early grep match SIGPIPEs the
# printf, pipefail fails the pipeline, and the `if` reports "no match" for a
# marker that IS there — so a long, i.e. already-unhealthy, boot log would be
# mis-verdicted. See the rule near print_lines().
if [[ "$BOOT_LOG" == *'PHLIX-HUB-MIGRATION-FAILURE'* ]]; then
    fail migrations "the entrypoint printed PHLIX-HUB-MIGRATION-FAILURE — the schema is absent or half-applied"
    print_lines 10 "$(printf '%s\n' "$BOOT_LOG" | grep -aE 'PHLIX-HUB-MIGRATION-FAILURE|SQLSTATE|exited [0-9]+' || true)"
elif [[ "$BOOT_LOG" == *'PHLIX-HUB-MIGRATIONS-NOT-RUN'* ]]; then
    fail migrations "migrations were skipped entirely (PHLIX-HUB-MIGRATIONS-NOT-RUN)"
elif [[ "$BOOT_LOG" == *'Skipping database migrations'* ]]; then
    fail migrations "migrations were skipped — this gate always configures HUB_DB_HOST, so that is a defect in the entrypoint's env handling (it read PHLIX_DATABASE_HOST for years, a name no php here reads)"
elif [[ "$BOOT_LOG" == *'PHLIX-HUB-MIGRATIONS-OK'* ]]; then
    MIGRATION_DETAIL='(count not found in the log)'
    if [[ "$BOOT_LOG" =~ Migrations\ complete\ \([0-9]+\ applied\) ]]; then
        MIGRATION_DETAIL="${BASH_REMATCH[0]}"
    elif [[ "$BOOT_LOG" == *'All migrations already applied'* ]]; then
        MIGRATION_DETAIL='All migrations already applied'
    fi
    pass migrations "migrations ran to completion (${MIGRATION_DETAIL})"
else
    fail migrations "no migration outcome found in the boot log at all"
    print_lines 20 "$BOOT_LOG"
fi

# ---------------------------------------------------------------------------
# 6. ASSERT — the SCHEMA really is there, reached with the APPLICATION's own
#    credentials from inside the container. The log says what the entrypoint
#    believed; this says what is in the database.
# ---------------------------------------------------------------------------
say "ASSERT — the application can reach its database and the schema exists"
DB_PROBE_PHP=$(cat <<'PHP_PROBE'
$h = getenv("HUB_DB_HOST"); $p = getenv("HUB_DB_PORT") ?: "3306";
$d = getenv("HUB_DB_NAME"); $u = getenv("HUB_DB_USER"); $w = getenv("HUB_DB_PASSWORD");
try {
    $pdo = new PDO("mysql:host={$h};port={$p};dbname={$d}", $u, $w,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // The ledger table is `migrations` (MigrationRunner::TRACKING_TABLE),
    // NOT `schema_migrations` — that is phlix-server's name.
    $m = (int) $pdo->query("SELECT COUNT(*) FROM `migrations`")->fetchColumn();
    $t = (int) $pdo->query("SHOW TABLES")->rowCount();
    $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $pdo->query("SELECT COUNT(*) FROM servers")->fetchColumn();
    echo "DBPROBE_OK migrations={$m} tables={$t}\n";
} catch (Throwable $e) {
    echo "DBPROBE_FAIL " . $e->getMessage() . "\n";
}
PHP_PROBE
)
DB_PROBE_OUT="$($DOCKER exec "$APP_NAME" php -r "$DB_PROBE_PHP" 2>&1 || true)"
info "probe: $(clip 300 "${DB_PROBE_OUT//$'\n'/}")"
DB_MIGRATIONS="$(printf '%s' "$DB_PROBE_OUT" | sed -n 's/.*migrations=\([0-9]*\).*/\1/p')"
DB_TABLES="$(printf '%s' "$DB_PROBE_OUT" | sed -n 's/.*tables=\([0-9]*\).*/\1/p')"
MIGRATION_FILES="$(find "${REPO_ROOT}/migrations" -maxdepth 1 -name '*.sql' | grep -c . || true)"
is_uint "$MIGRATION_FILES" || MIGRATION_FILES=0
if [[ "$DB_PROBE_OUT" != *'DBPROBE_OK'* ]]; then
    fail schema "the application cannot reach its database, or core tables are missing"
elif ! is_uint "${DB_MIGRATIONS:-}" || ! is_uint "${DB_TABLES:-}"; then
    # DBPROBE_OK without parseable counters means the probe changed shape. Do
    # NOT fall through to the numeric comparison and do NOT skip.
    fail schema "DBPROBE_OK but the counters are unparseable (migrations='${DB_MIGRATIONS}' tables='${DB_TABLES}')"
elif [ "$DB_MIGRATIONS" -lt "$MIGRATION_FILES" ] || [ "$DB_TABLES" -lt 20 ]; then
    fail schema "schema incomplete: ${DB_MIGRATIONS} of ${MIGRATION_FILES} migration files applied, ${DB_TABLES} tables"
else
    pass schema "schema present: ${DB_MIGRATIONS} applied migrations (${MIGRATION_FILES} files on disk), ${DB_TABLES} tables, users+servers queryable"
fi

# ---------------------------------------------------------------------------
# 7. ASSERT — the WORKERMAN DAEMON is what is running.
#
# Stated positively (start.php is there) AND negatively (no php-fpm, no nginx,
# no public/index.php). The negative half matters because the image used to
# carry a supervisord config declaring php-fpm and nginx programs whose
# binaries the shared base does not contain, and an entrypoint that exec'd
# `public/index.php` — a file that does not exist in this repository.
# ---------------------------------------------------------------------------
say "ASSERT — start.php (Workerman master + workers) is the running process"
PS_RC=0
PSOUT="$($DOCKER exec "$APP_NAME" ps -eo pid,args 2>&1)" || PS_RC=$?
if [ "$PS_RC" -ne 0 ] || [ -z "$PSOUT" ]; then
    # BOTH ids must report, or the completeness check at the end fires — which
    # is the point: a check that cannot be evaluated is a FAILURE, not a skip.
    fail daemon-process "could not read the process table (docker exec rc=${PS_RC})"
    fail no-cgi "could not read the process table (docker exec rc=${PS_RC}) — this check would pass vacuously"
else
    print_lines 20 "$(printf '%s\n' "$PSOUT" | grep -E 'php|start\.php' || true)"
    WORKER_COUNT="$(printf '%s\n' "$PSOUT" | grep -c 'WorkerMan: worker process' || true)"
    is_uint "$WORKER_COUNT" || WORKER_COUNT=0
    if [[ "$PSOUT" != *'start.php'* ]]; then
        fail daemon-process "no start.php process — the daemon is not what is running"
    elif [ "$WORKER_COUNT" -lt 1 ]; then
        fail daemon-process "start.php is present but forked NO worker processes"
    else
        pass daemon-process "start.php running with ${WORKER_COUNT} Workerman worker process(es)"
    fi
    if [[ "$PSOUT" =~ public/index\.php|php-fpm|nginx|supervisord ]]; then
        fail no-cgi "a CGI/FPM/nginx/supervisord process is running — the image ships more than one serving model"
    else
        pass no-cgi "no public/index.php, php-fpm, nginx or supervisord process"
    fi
fi

# ---------------------------------------------------------------------------
# 8. ASSERT — the relay workers bound their ports INSIDE the container.
#
# The HTTP worker answering says nothing about RelayWorker (:8802),
# ClientRelayWorker (:8803) or SyncPlayRelayWorker (:8804): a hub that serves
# /health but never accepts a tunnel is the hub's version of a total outage,
# and nothing else in this gate would notice.
# ---------------------------------------------------------------------------
say "ASSERT — relay workers are listening on ${RELAY_PORTS}"
RELAY_OK=1
RELAY_REPORT=''
RELAY_CHECKED=0
for port in $RELAY_PORTS; do
    RELAY_CHECKED=$(( RELAY_CHECKED + 1 ))
    PROBE="$($DOCKER exec "$APP_NAME" php -r \
        "\$f=@fsockopen('127.0.0.1',${port},\$e,\$s,5); echo \$f?'OPEN':'CLOSED:'.\$s;" 2>&1 || true)"
    RELAY_REPORT="${RELAY_REPORT}${port}=$(clip 40 "${PROBE//$'\n'/}") "
    [[ "$PROBE" == *OPEN* ]] || RELAY_OK=0
done
info "probes: ${RELAY_REPORT}"
if [ "$RELAY_CHECKED" -lt 3 ]; then
    fail relay-ports "only probed ${RELAY_CHECKED} port(s) — the loop inspected less than it claims"
elif [ "$RELAY_OK" = "1" ]; then
    pass relay-ports "${RELAY_CHECKED}/${RELAY_CHECKED} relay ports accepting connections in-container (${RELAY_REPORT})"
else
    fail relay-ports "a relay worker never bound its port: ${RELAY_REPORT}"
fi

# ---------------------------------------------------------------------------
# 9. ASSERT — the SPA shell is served. `public/assets/app/` is committed and
#    src/Http/ViteAssets.php reads its manifest; a build that dropped the
#    directory (a .dockerignore entry, say) would still pass every check above.
# ---------------------------------------------------------------------------
say "ASSERT — GET /app serves the SPA shell"
SPA_RC=0
SPA_BODY="$(probe_curl -fsS --max-time 10 "${PROBE_URL}/app" 2>/dev/null)" || SPA_RC=$?
SPA_BYTES="$(printf '%s' "$SPA_BODY" | wc -c | tr -d ' ')"
is_uint "$SPA_BYTES" || SPA_BYTES=0
if [ "$SPA_RC" -ne 0 ]; then
    fail spa-shell "curl exited ${SPA_RC} for /app"
elif [[ "${SPA_BODY,,}" == *'<div id="app"'* || "$SPA_BODY" == *'/assets/app/'* ]]; then
    pass spa-shell "/app served ${SPA_BYTES} bytes of SPA shell referencing the built bundle"
else
    fail spa-shell "/app returned ${SPA_BYTES} bytes that do not look like the SPA shell"
    # NOT `| head -c 300`: SPA_BODY is kilobytes, head would close the pipe and
    # pipefail would ABORT the script here — inside a FAIL diagnostic, before
    # the remaining assertions and the check registry ever ran.
    printf '   | %s\n' "$(clip 300 "$SPA_BODY")"
fi

# ---------------------------------------------------------------------------
# 10. ASSERT — it STAYS up.
#
# The most important assertion here, and the one a single sample cannot make.
# A crash-restart loop shows RUNNING most of the time; phlix-server's gate
# passed a container that restarted every ~27s forever. Require: the container
# never leaves Running, PID 1 never changes (the daemon IS pid 1 in this image,
# so a restart is a NEW CONTAINER PROCESS and Docker would report a restart
# count), the restart counter stays 0, and the worker pids hold.
# ---------------------------------------------------------------------------
say "ASSERT — the daemon stays up (${STABILITY_WINDOW}s window, sampled every ${STABILITY_SAMPLE}s)"
STAB_OK=1
STAB_REASON=''
STAB_SAMPLES=0
PID1_START="$($DOCKER inspect -f '{{.State.Pid}}' "$APP_NAME" 2>/dev/null || echo '')"
WORKER_PIDS_T0="$($DOCKER exec "$APP_NAME" ps -eo pid,args 2>/dev/null | awk '/WorkerMan: worker process/ {print $1}' | sort | tr '\n' ' ' || true)"
WORKER_N_T0="$(printf '%s' "$WORKER_PIDS_T0" | wc -w | tr -d ' ')"
is_uint "$WORKER_N_T0" || WORKER_N_T0=0
info "t=0s   pid1=${PID1_START} workers=${WORKER_N_T0}"

stab_end_at=$(( $(date +%s) + STABILITY_WINDOW ))
while [ "$STAB_OK" = "1" ] && [ "$(date +%s)" -lt "$stab_end_at" ]; do
    sleep "$STABILITY_SAMPLE"
    STAB_SAMPLES=$(( STAB_SAMPLES + 1 ))
    STAB_ELAPSED=$(( STABILITY_WINDOW - (stab_end_at - $(date +%s)) ))

    RUNNING="$($DOCKER inspect -f '{{.State.Running}}' "$APP_NAME" 2>/dev/null || echo 'unknown')"
    PID1_NOW="$($DOCKER inspect -f '{{.State.Pid}}' "$APP_NAME" 2>/dev/null || echo '')"
    RESTARTS="$($DOCKER inspect -f '{{.RestartCount}}' "$APP_NAME" 2>/dev/null || echo '')"
    WORKER_PIDS_NOW="$($DOCKER exec "$APP_NAME" ps -eo pid,args 2>/dev/null | awk '/WorkerMan: worker process/ {print $1}' | sort | tr '\n' ' ' || true)"
    WORKER_N_NOW="$(printf '%s' "$WORKER_PIDS_NOW" | wc -w | tr -d ' ')"
    is_uint "$WORKER_N_NOW" || WORKER_N_NOW=0
    info "t=${STAB_ELAPSED}s   running=${RUNNING} pid1=${PID1_NOW} restarts=${RESTARTS} workers=${WORKER_N_NOW}"

    if [ "$RUNNING" != "true" ]; then
        STAB_OK=0; STAB_REASON="the container left the Running state (${RUNNING}); exit=$($DOCKER inspect -f '{{.State.ExitCode}}' "$APP_NAME" 2>/dev/null || echo '?')"
        break
    fi
    if [ -n "$PID1_START" ] && [ "$PID1_NOW" != "$PID1_START" ]; then
        STAB_OK=0; STAB_REASON="the container's main pid changed ${PID1_START} -> ${PID1_NOW}: it RESTARTED"
        break
    fi
    if [ "$RESTARTS" != "0" ]; then
        STAB_OK=0; STAB_REASON="docker reports RestartCount=${RESTARTS}"
        break
    fi
    if [ "$WORKER_N_NOW" -lt "$WORKER_N_T0" ]; then
        STAB_OK=0; STAB_REASON="the worker count fell from ${WORKER_N_T0} to ${WORKER_N_NOW}"
        break
    fi
    if [ "$WORKER_PIDS_NOW" != "$WORKER_PIDS_T0" ]; then
        STAB_OK=0; STAB_REASON="the worker pids changed ('${WORKER_PIDS_T0}' -> '${WORKER_PIDS_NOW}'): workers are REFORKING under a stable master"
        break
    fi
done

# A window that took no samples proves nothing, and would otherwise report PASS.
if [ "$STAB_SAMPLES" -lt 2 ]; then
    fail stability "only ${STAB_SAMPLES} sample(s) taken in ${STABILITY_WINDOW}s — the window did not actually run"
elif [ "$STAB_OK" = "1" ]; then
    pass stability "stable across ${STAB_SAMPLES} samples over ${STABILITY_WINDOW}s: pid1=${PID1_START}, 0 restarts, ${WORKER_N_T0} workers holding their pids"
else
    fail stability "NOT STABLE after ${STAB_SAMPLES} sample(s) — ${STAB_REASON}"
    dump_diagnostics
fi

# ---------------------------------------------------------------------------
# 11. ASSERT — the HEALTHCHECK is declared, reaches `healthy`, and its start
#     period is short enough that `unhealthy` is REACHABLE.
# ---------------------------------------------------------------------------
say "ASSERT — HEALTHCHECK declared, healthy, and observably so"
HC_TEST="$($DOCKER image inspect -f '{{json .Config.Healthcheck.Test}}' "$IMAGE_TAG" 2>/dev/null || echo 'null')"
if [ "$HC_TEST" = "null" ] || [ -z "$HC_TEST" ]; then
    fail healthcheck-declared "the image declares no HEALTHCHECK — a container in which the app never started reports 'Up' forever"
    fail healthcheck-healthy "no HEALTHCHECK to become healthy"
    fail healthcheck-start-period "no HEALTHCHECK to read a start period from"
else
    pass healthcheck-declared "HEALTHCHECK ${HC_TEST}"

    HC_STATUS=''
    hdeadline=$(( $(date +%s) + HEALTHY_TIMEOUT ))
    while [ "$(date +%s)" -lt "$hdeadline" ]; do
        HC_STATUS="$($DOCKER inspect -f '{{.State.Health.Status}}' "$APP_NAME" 2>/dev/null || echo '')"
        [ "$HC_STATUS" = "healthy" ] && break
        [ "$HC_STATUS" = "unhealthy" ] && break
        sleep 5
    done
    if [ "$HC_STATUS" = "healthy" ]; then
        pass healthcheck-healthy "docker reports the container healthy"
    else
        fail healthcheck-healthy "container health is '${HC_STATUS:-<unreadable>}' after ${HEALTHY_TIMEOUT}s"
        printf '   | %s\n' "$(clip 800 "$($DOCKER inspect -f '{{json .State.Health}}' "$APP_NAME" 2>&1 || true)")"
    fi

    # `{{.Config.Healthcheck.StartPeriod}}` renders a Go time.Duration through
    # String() — "1m30s" — which fed to `$(( ))` aborts the enclosing block
    # under `set -e` and leaves the check UNRECORDED. That exact bug shipped in
    # phlix-server and printed ALL PASSED. The `{{json …}}` form is nanoseconds.
    HC_SP_NS="$($DOCKER image inspect -f '{{json .Config.Healthcheck.StartPeriod}}' "$IMAGE_TAG" 2>/dev/null | tr -d ' \r\n' || true)"
    if is_uint "$HC_SP_NS"; then
        HC_SP_S=$(( HC_SP_NS / 1000000000 ))
        if [ "$HC_SP_S" -le "$MAX_START_PERIOD" ]; then
            pass healthcheck-start-period "start period ${HC_SP_S}s <= ${MAX_START_PERIOD}s, so 'unhealthy' is reachable while anyone is watching"
        else
            fail healthcheck-start-period "start period ${HC_SP_S}s > ${MAX_START_PERIOD}s — failures inside it are not counted, so the health state is decorative"
        fi
    else
        fail healthcheck-start-period "start period is unreadable ('${HC_SP_NS}') — refusing to skip the check"
    fi
fi

# ---------------------------------------------------------------------------
# 12. ASSERT — the image satisfies its own composer platform requirements.
#     `composer install` ran WITHOUT --ignore-platform-reqs, so this should be
#     redundant — assert it anyway, from inside the running image, because the
#     flag's return is a one-word edit.
# ---------------------------------------------------------------------------
say "ASSERT — composer check-platform-reqs inside the image"
PLAT_RC=0
PLAT_OUT="$($DOCKER exec "$APP_NAME" sh -c 'cd /var/www/html && composer check-platform-reqs --no-dev --no-interaction 2>&1')" || PLAT_RC=$?
PLAT_LINES="$(printf '%s\n' "$PLAT_OUT" | grep -c . || true)"
is_uint "$PLAT_LINES" || PLAT_LINES=0
if [ "$PLAT_RC" -eq 0 ] && [ "$PLAT_LINES" -gt 0 ]; then
    pass platform-reqs "all platform requirements satisfied (${PLAT_LINES} lines of report)"
else
    fail platform-reqs "composer check-platform-reqs exited ${PLAT_RC} (${PLAT_LINES} lines)"
    print_lines 20 "$PLAT_OUT"
fi

# ---------------------------------------------------------------------------
# 13. THE POSITIVE CONTROL, and it runs LAST because it is destructive to its
#     own container.
#
# Everything above is a green tick. A gate that has never been seen to go red
# on the same image is not yet evidence: if `docker run` of this image always
# exited 0 and served nothing, several of the checks above would look identical.
# So boot a SECOND container from the SAME image, configured to fail
# (HUB_MIGRATIONS_STRICT=1 against a database host that does not resolve), and
# require it to DIE with a NON-ZERO exit code.
#
# It proves two things at once: the strict-mode refusal path works, and this
# image can express failure through its exit code at all — i.e. the boot
# assertions above are discriminating, not vacuous.
# ---------------------------------------------------------------------------
say "CONTROL — a deliberately misconfigured container must DIE non-zero"
# shellcheck disable=SC2086
$DOCKER run -d --name "$CONTROL_NAME" $NET_ARG \
    -e HUB_DB_HOST="no-such-host-${RUN_ID}" \
    -e HUB_DB_PORT=3306 \
    -e HUB_DB_NAME="$DB_NAME" \
    -e HUB_DB_USER=phlix_hub \
    -e HUB_DB_PASSWORD=wrong \
    -e HUB_MIGRATIONS_STRICT=1 \
    "$IMAGE_TAG" >/dev/null 2>&1 || true

CTRL_RUNNING=true
cdeadline=$(( $(date +%s) + CONTROL_TIMEOUT ))
while [ "$(date +%s)" -lt "$cdeadline" ]; do
    CTRL_RUNNING="$($DOCKER inspect -f '{{.State.Running}}' "$CONTROL_NAME" 2>/dev/null || echo 'unknown')"
    [ "$CTRL_RUNNING" = "true" ] || break
    sleep 3
done
CTRL_EXIT="$($DOCKER inspect -f '{{.State.ExitCode}}' "$CONTROL_NAME" 2>/dev/null || echo '')"
CTRL_LOG="$($DOCKER logs --tail 20 "$CONTROL_NAME" 2>&1 || true)"
info "control exit=${CTRL_EXIT} running=${CTRL_RUNNING}"
if [ "$CTRL_RUNNING" = "true" ]; then
    fail failure-exit-nonzero "the misconfigured container was STILL RUNNING after ${CONTROL_TIMEOUT}s — a failed boot that keeps the container alive is exactly the 'Up while serving nothing' state this gate exists to catch"
    print_lines 10 "$CTRL_LOG"
elif ! is_uint "${CTRL_EXIT:-}"; then
    fail failure-exit-nonzero "could not read the control container's exit code ('${CTRL_EXIT}')"
elif [ "$CTRL_EXIT" -eq 0 ]; then
    fail failure-exit-nonzero "the misconfigured container exited 0 — a total failure is indistinguishable from a clean stop, and every restart policy and exit-code alert reads it as success"
    print_lines 10 "$CTRL_LOG"
elif [[ "$CTRL_LOG" == *'PHLIX-HUB-MIGRATION'* ]]; then
    pass failure-exit-nonzero "the misconfigured container died with exit ${CTRL_EXIT} and printed its refusal banner — this gate's green above is therefore discriminating"
else
    fail failure-exit-nonzero "the container exited ${CTRL_EXIT} but printed no PHLIX-HUB-MIGRATION banner — it may have died for an unrelated reason, which is not the control this check needs"
    print_lines 10 "$CTRL_LOG"
fi

# ---------------------------------------------------------------------------
# COMPLETENESS — every registered check must have produced EXACTLY ONE verdict.
# ---------------------------------------------------------------------------
say "Check registry"
REGISTERED=0
for check in $EXPECTED_CHECKS; do
    REGISTERED=$(( REGISTERED + 1 ))
    seen=0
    for recorded in $RECORDED_CHECKS; do
        [ "$recorded" = "$check" ] && seen=$(( seen + 1 ))
    done
    if [ "$seen" -eq 0 ]; then
        fail "$check" "NEVER RAN — a check that reaches no verdict is a failure, not a skip"
    elif [ "$seen" -gt 1 ]; then
        printf '   \033[31mFAIL\033[0m [%s] recorded %s verdicts — bug in this script\n' "$check" "$seen"
        FAILURES=$(( FAILURES + 1 ))
    fi
done
RECORDED_N="$(printf '%s' "$RECORDED_CHECKS" | wc -w | tr -d ' ')"
info "registered: ${REGISTERED}   verdicts recorded: ${RECORDED_N}   failures: ${FAILURES}"
if [ "$BOOT_PUBLISH" != "1" ]; then
    printf '   \033[33m%s\033[0m\n' \
        "NOTE: BOOT_PUBLISH=0 — [health-published] was NOT part of this run."
fi

say "RESULT"
if [ "$FAILURES" -eq 0 ]; then
    printf '   \033[32mALL %s ASSERTIONS PASSED\033[0m for %s\n' "$REGISTERED" "$IMAGE_TAG"
    exit 0
fi
printf '   \033[31m%s of %s ASSERTIONS FAILED\033[0m for %s\n' "$FAILURES" "$REGISTERED" "$IMAGE_TAG"
exit 1
