<?php

/**
 * Phlix hub component: Health.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Health;

use Phlix\Hub\Version;
use Phlix\Shared\Version as SharedVersion;

/**
 * Liveness/readiness endpoint for `phlix-hub`.
 *
 * Returns a small JSON-serializable array describing service identity, package
 * versions, the current Unix timestamp, and — since S312 — the state of the
 * dedicated maintenance worker.
 *
 * ## Why a health probe answered by the HTTP workers reports on ANOTHER fork
 *
 * Until S312 this controller had no DB and no filesystem dependency at all, on
 * purpose, so the endpoint stayed answerable while the rest of the stack was
 * starting up. That property was also the defect. Measured on master @ 65763eb
 * under `docker run --network none`: the maintenance worker was being killed
 * and re-forked every 60 seconds (its process `etime` 0:39 against the
 * container master's 4:41) while this endpoint answered `{"status":"ok"}` and
 * `docker inspect` reported `healthy` with `RestartCount=0`. The probe was
 * green because it is answered by the HTTP workers, **a different fork**, which
 * were genuinely fine.
 *
 * So the probe now reads one small file that the maintenance worker writes for
 * itself — see {@see MaintenanceHeartbeat}, which explains why that file is
 * stamped by the SWEEP rather than by a timer of its own, and why a re-forking
 * worker cannot renew its own grace period.
 *
 * ⚠ This is deliberately NOT "is any worker alive". That would trade one
 * uninformative green for another: every arm of the measured failure had six
 * healthy workers. The assertion is specifically that the maintenance worker
 * completed a maintenance sweep recently.
 *
 * ## Why `status` stays two-valued
 *
 * `status` is the PROBE VERDICT — HAProxy and the container `HEALTHCHECK` both
 * consume it, and a third value would silently read as "not ok" to one and "not
 * unhealthy" to the other. It is `ok` on 200 and `unhealthy` on 503. Everything
 * finer-grained lives in `maintenance.status`, which carries `degraded` for the
 * alive-but-failing case (an unreachable database, most often) precisely so
 * that condition is visible WITHOUT pulling a reachable hub out of a pool.
 *
 * The read is failure-proof by construction: {@see MaintenanceHeartbeat} never
 * throws, and an absent or malformed record is reported as `down`, not as an
 * exception. A missing heartbeat dependency (a container built without one)
 * degrades to the pre-S312 payload rather than to a 500.
 *
 * @package Phlix\Hub\Health
 */
final class HealthController
{
    /**
     * @param MaintenanceHeartbeat|null $maintenance The maintenance worker's liveness record. Nullable so
     *                                               a bare `new HealthController()` still answers, which is
     *                                               what several boot paths and tests do.
     */
    public function __construct(private readonly ?MaintenanceHeartbeat $maintenance = null)
    {
    }

    /**
     * Build the health payload.
     *
     * @return array{
     *     status: string,
     *     service: string,
     *     version: string,
     *     phlixShared: string,
     *     timestamp: int,
     *     maintenance: array{
     *         status: string,
     *         reason: string,
     *         task: string|null,
     *         pid: int|null,
     *         incarnations: int|null,
     *         age_seconds: int|null,
     *         consecutive_failures: int|null,
     *         last_error: string|null,
     *         stale_after_seconds: int
     *     }
     * }
     *
     */
    public function __invoke(): array
    {
        $maintenance = $this->maintenance?->snapshot() ?? [
            'status' => MaintenanceHeartbeat::STATUS_DISABLED,
            'reason' => 'no_heartbeat_configured',
            'task' => null,
            'pid' => null,
            'incarnations' => null,
            'age_seconds' => null,
            'consecutive_failures' => null,
            'last_error' => null,
            'stale_after_seconds' => MaintenanceHeartbeat::DEFAULT_STALE_AFTER_SECONDS,
        ];

        return [
            'status' => MaintenanceHeartbeat::isProbeFailure($maintenance) ? 'unhealthy' : 'ok',
            'service' => 'phlix-hub',
            'version' => Version::VERSION,
            'phlixShared' => SharedVersion::VERSION,
            'timestamp' => time(),
            'maintenance' => $maintenance,
        ];
    }

    /**
     * HTTP status for a payload built by {@see __invoke()}.
     *
     * 503, not 500: the hub is reachable and the process is up, but a component
     * it needs is not serving. `curl -fsS` — which is what the image's
     * `HEALTHCHECK` runs — fails on 503, so `docker inspect` flips to
     * `unhealthy`, which is the whole point of S312.
     *
     * @param array{status: string, ...} $payload A {@see __invoke()} result.
     */
    public static function statusCodeFor(array $payload): int
    {
        return $payload['status'] === 'ok' ? 200 : 503;
    }
}
