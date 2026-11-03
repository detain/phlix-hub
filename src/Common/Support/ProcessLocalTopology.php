<?php

/**
 * Phlix hub component: Support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Support;

use RuntimeException;

/**
 * Fail-loud guard for process-local worker topology.
 *
 * Every WS worker in this hub keeps its authoritative connection topology in
 * PHP statics that live inside ONE process:
 *
 *  - RelayWorker::$connTunnels (:8802 server-tunnel registry),
 *  - ClientRelayWorker::$connServerIds / $liveConnections (:8803),
 *  - SyncPlayRelayWorker::$clients / $rooms / $roomHosts / $roomPlayback (:8804),
 *  - FederationWorker::$connHubIds plus FederationRelayController and
 *    ClientMountController statics (:8805 and the mount controllers).
 *
 * Workerman forks `count` children that each inherit a COPY of these statics;
 * after fork, two children disagree about which connections exist, a tunnel
 * can be routed by a process that never accepted its socket, and per-worker
 * rate limiters silently become per-N-th-of-global limits (the
 * RateLimitProfiles docblock law: one worker process == the global budget).
 * A >1 topology is therefore not a tuning knob — it is a corrupt state, and
 * every call site in this repo constructs these workers with count 1.
 *
 * Refusing it in the constructor means a future config surface, test harness,
 * or hand-edited boot path that tries to scale out gets a loud boot failure
 * naming the worker instead of a split-brain relay under production load.
 */
final class ProcessLocalTopology
{
    /** The only process count that preserves the static-registry topology law. */
    public const SINGLE_PROCESS = 1;

    /**
     * Assert that $count keeps the worker single-process, or refuse to build it.
     *
     * @throws RuntimeException if $count is not exactly 1
     */
    public static function assertSingleProcess(int $count, string $workerName): void
    {
        if ($count === self::SINGLE_PROCESS) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Worker %s keeps its connection topology in process-local statics and cannot run with count=%d; '
            . 'forked children would each hold a divergent view of every tunnel, mount, and watch group. '
            . 'Keep count=1 and scale by sharding hub instances, not worker processes.',
            $workerName,
            $count,
        ));
    }
}
