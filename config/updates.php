<?php

/**
 * Core (hub application) update-check settings — S75 / updates.md #48.
 *
 * The hub periodically fetches the repository's root `VERSION` marker and
 * compares it against the compiled {@see \Phlix\Hub\Version::VERSION}
 * constant, so an operator learns a newer hub exists without polling GitHub
 * by hand.
 *
 * Only `check_enabled` is admin-editable; it is exposed through
 * `PUT /api/v1/admin/updates/settings` and read as an EFFECTIVE value
 * (hub_settings override → this default) on every poll by
 * {@see \Phlix\Hub\Hub\Updates\CoreUpdateCheckService::isCheckEnabled()}.
 *
 * ⚠ S308: NOTHING here is fetched on the boot path. Setting
 * `HUB_UPDATES_CHECK_ENABLED=0` (or the admin toggle) makes an install perform
 * no outbound request at all — the supported configuration for an air-gapped or
 * egress-filtered hub. An install that leaves it on and cannot reach the marker
 * records one timeout per `poll_seconds` and logs it once; it never delays or
 * destabilises startup.
 *
 * NOTHING here ever applies an update. The status endpoint surfaces
 * `update_command` as a copy-to-clipboard string the operator runs on the
 * box themselves — the hub never shells out to git/composer/systemctl.
 *
 * @return array<string, mixed>
 */

declare(strict_types=1);

$envStr = static fn (string $k, string $d): string => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;
$envInt = static fn (string $k, int $d): int => is_numeric($v = getenv($k)) ? (int) $v : $d;
$envBool = static function (string $k, bool $d): bool {
    $v = getenv($k);
    if ($v === false || $v === '') {
        return $d;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
};

return [
    // Master switch for the periodic check. Default TRUE (S75). Overridable
    // per-install via HUB_UPDATES_CHECK_ENABLED, and at runtime by an admin
    // through PUT /api/v1/admin/updates/settings (a hub_settings override,
    // which wins over this default).
    'check_enabled' => $envBool('HUB_UPDATES_CHECK_ENABLED', true),

    // Remote version marker: the repository's root VERSION file, which this
    // repo ships and keeps in lockstep with Phlix\Hub\Version::VERSION (pinned
    // by tests/VersionTest.php).
    'marker_url' => $envStr(
        'HUB_UPDATES_MARKER_URL',
        'https://raw.githubusercontent.com/detain/phlix-hub/master/VERSION',
    ),

    // Copy-to-clipboard command surfaced by GET /api/v1/admin/updates/status.
    // VERBATIM from README.md's "Updating an existing install" one-liner — do
    // not paraphrase it, an operator pastes this into a root shell.
    'update_command' => $envStr(
        'HUB_UPDATES_COMMAND',
        'curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh'
        . ' | sudo bash -s -- --update -y',
    ),

    // Steady-state poll interval, seconds (default: daily). NOTHING fetches on
    // the boot path (S308): the worker sweeps every `sweep_seconds` and touches
    // the network only when this interval has elapsed since the last completed
    // check. That keeps the property a bare Timer::add(86400) lacks — it never
    // fires on a box that restarts more often than the interval — WITHOUT
    // making every restart a fetch.
    'poll_seconds' => $envInt('HUB_UPDATES_POLL_SECONDS', 86400),

    // How often the maintenance worker ASKS whether a poll is due, seconds.
    // A sweep is one hub_settings read; it is not a network call. 60s matches
    // IdleReaper's maintenance cadence and is what makes the boot catch-up
    // structural (the first sweep is a minute after every boot).
    'sweep_seconds' => $envInt('HUB_UPDATES_SWEEP_SECONDS', 60),

    // Socket timeout, seconds, for the marker fetch.
    'timeout_seconds' => $envInt('HUB_UPDATES_TIMEOUT_SECONDS', 10),

    // Backstop deadline, seconds: an in-flight fetch that has not called back
    // within this many seconds is recorded as a timeout and the single-flight
    // guard released.
    //
    // ⚠ This is NOT a duplicate of `timeout_seconds`. MEASURED 2026-08-10 in a
    // container with no route out: the Swoole-hooked DNS resolution inside
    // `stream_socket_client()` did not return for over 60s, so the vendor HTTP
    // client's own `timeout` option — enforced by a timer that only inspects
    // connections it has already created — never got a connection to time out.
    // Without this deadline an egress-filtered hub's first fetch stays in
    // flight forever and no later poll is ever attempted.
    'deadline_seconds' => $envInt('HUB_UPDATES_DEADLINE_SECONDS', 20),
];
