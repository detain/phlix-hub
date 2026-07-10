#!/usr/bin/env bash
#
# wait-for-free-ports.sh — systemd ExecStartPre gate for phlix-hub.
#
# Blocks (default 90 s, override with `-t SECONDS`) until every given TCP
# port on 127.0.0.1 is FREE, then exits 0 so the new Workerman master can
# start and bind cleanly.
#
# Why: on `systemctl restart phlix-hub` the old master can take several
# seconds (worst observed: minutes, 2026-07-10 incident) to release its
# listen sockets (:8800 HTTP, :8802/:8803/:8804/:8805 relay WS workers,
# :2206 channel broker). Without this gate the new master boots while the
# ports are still held, Worker::runAll() throws
# "RuntimeException: Address already in use", the unit crash-loops every
# RestartSec, and the hub stays down until the ports finally free up.
#
# On timeout we deliberately exit 0 (NOT 1): the ports may free between our
# last probe and the master's bind, and if they haven't, the bind fails fast
# and systemd's Restart=on-failure brings us back through this gate again.
# Exiting non-zero here would burn a StartLimitBurst slot for nothing extra.
#
# Probe method: bash /dev/tcp connect — no dependency on ss/netstat inside
# the hardened service sandbox. Connect SUCCEEDS => something still listens
# (a listener on 0.0.0.0:PORT accepts 127.0.0.1 connects) => port busy.

set -u

timeout=90
if [ "${1:-}" = "-t" ]; then
  timeout="${2:?wait-for-free-ports: -t requires a seconds value}"
  shift 2
fi

if [ "$#" -eq 0 ]; then
  echo "usage: $0 [-t seconds] PORT [PORT...]" >&2
  exit 2
fi

deadline=$(( $(date +%s) + timeout ))

while :; do
  busy=""
  for port in "$@"; do
    # Subshell so fd 3 closes on exit; stderr silenced (ECONNREFUSED is the
    # GOOD case — it means the port is free).
    if (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
      busy="${busy} ${port}"
    fi
  done

  if [ -z "$busy" ]; then
    exit 0
  fi

  if [ "$(date +%s)" -ge "$deadline" ]; then
    echo "wait-for-free-ports: still in use after ${timeout}s:${busy} — proceeding; the bind will fail fast and systemd retries." >&2
    exit 0
  fi

  sleep 1
done
