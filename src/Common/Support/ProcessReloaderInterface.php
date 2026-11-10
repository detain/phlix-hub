<?php

/**
 * Phlix hub component: Support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Support;

/**
 * The OS-process boundary the graceful-restart endpoint needs.
 *
 * Review hub__srphp #5 (SH-070): HubRestartController was the estate's one
 * non-final controller, held open only so a test subclass could intercept
 * `posix_kill`/`Timer::add`. AGENTS.md says controllers are `final`; the fix
 * for an untestable limb is an injected boundary, not an open class. This is
 * that boundary — three limbs, each exactly one OS interaction:
 *
 *  - liveness/signallability probe (signal 0),
 *  - the /proc identity witness,
 *  - the DEFERRED graceful reload (the ack must flush before the signal).
 *
 * Tests substitute a recording fake; production uses
 * {@see WorkermanProcessReloader}.
 *
 * @package Phlix\Hub\Common\Support
 */
interface ProcessReloaderInterface
{
    /**
     * Whether the pid exists and may be signalled by this process.
     *
     * Implements the probe as a signal-0 send: POSIX performs the existence
     * AND permission checks without delivering anything, so a stale pid file
     * fails here before the endpoint can promise a reload whose deferred
     * signal would then silently no-op.
     *
     * @param int $pid Candidate Workerman master pid.
     */
    public function isSignallable(int $pid): bool;

    /**
     * Read /proc/<pid>/cmdline (NUL-separated argv) for the identity gate.
     *
     * @return string|null Raw argv blob, or null when it cannot be read —
     *         callers must treat null as refusal (fail closed: an
     *         unverifiable pid never receives the reload on the hub's behalf).
     */
    public function readCmdline(int $pid): ?string;

    /**
     * Arm the one-shot deferred graceful reload for the pid.
     *
     * Implementations MUST deliver the GRACEFUL signal (SIGUSR2 — Workerman
     * sets `gracefulStop` only for SIGUSR2, `Worker.php:1385-1391`) and MUST
     * defer it until after the current response has flushed (the hub is a
     * resident-memory worker; a sync signal would cut the ack mid-write).
     *
     * @param int $pid Workerman master pid, already identity-gated.
     */
    public function scheduleGracefulReload(int $pid): void;
}
