<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\Support\ProcessReloaderInterface;
use Phlix\Hub\Common\Support\WorkermanProcessReloader;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Throwable;

/**
 * Graceful hub reload via SIGUSR2.
 *
 * `POST /api/v1/admin/restart` reads the Workerman master PID from the pid
 * file (`config/server.php` → `pid_file`, the same value `start.php` assigns
 * to `Worker::$pidFile`) and asks the master to cycle its workers, so every
 * worker re-runs `onWorkerStart` and re-reads `config/*.php`.
 *
 * ## The OS limb is injected, not inherited
 *
 * Probe, /proc witness, and the deferred graceful signal (whose SIGUSR2
 * rationale and one-shot Timer deferral law live in
 * {@see WorkermanProcessReloader}) all cross the
 * {@see ProcessReloaderInterface} boundary. Review hub__srphp #5 (SH-070):
 * this used to be the estate's one non-final controller because a test
 * subclass intercepted the protected signal limbs; AGENTS.md demands `final`
 * controllers, and the injected port closes the class while keeping every
 * limb testable with a recording fake.
 *
 * ## Ordering — ack first, signal after the flush
 *
 * plan_settings.md §3.35 requires the JSON ack to reach the client *before*
 * the restart begins. The controller therefore only ever *schedules* the
 * signal; {@see ProcessReloaderInterface::scheduleGracefulReload()} is the
 * deferred leg. Never `sleep()`: this is a resident-memory Workerman worker.
 *
 * Route is gated by {@see \Phlix\Hub\Http\Middleware\AdminMiddleware}.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   Phase 10
 */
final class HubRestartController
{
    /** @var string Absolute path to the PID file, sourced from config. */
    private string $pidFile;

    private ProcessReloaderInterface $reloader;

    /**
     * @param string                        $pidFile   Absolute path to the PID file (from config/server.php's
     *                                                 `pid_file`, which start.php also uses for
     *                                                 `Worker::$pidFile` — single source of truth).
     * @param StructuredLogger              $logger    Hub-channel log. Injected (house controller pattern):
     *                                                 {@see restart()} records the real failure detail the
     *                                                 constant wire message deliberately withholds.
     * @param ProcessReloaderInterface|null $reloader  OS-process boundary; null (production wiring) builds the
     *                                                 Workerman/POSIX adapter — the same defaulting posture as
     *                                                 RequestManager's logger.
     *
     * @since Phase 10
     */
    public function __construct(
        string $pidFile,
        private readonly StructuredLogger $logger,
        ?ProcessReloaderInterface $reloader = null,
    ) {
        $this->pidFile = $pidFile;
        $this->reloader = $reloader ?? new WorkermanProcessReloader();
    }

    /**
     * Acknowledge, then gracefully reload the hub's workers.
     *
     * POST /api/v1/admin/restart
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON `{ success: bool, message?: string, error?: string }`.
     *
     * @since Phase 10
     */
    public function restart(Request $request, array $params): Response
    {
        try {
            if (!is_file($this->pidFile)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'pid_file_not_found',
                    'message' => 'Hub may not be running, or pid_file is misconfigured.',
                ]);
            }

            $raw = file_get_contents($this->pidFile);
            if ($raw === false) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'pid_file_read_failed',
                    'message' => 'Hub may not be running, or pid_file is misconfigured.',
                ]);
            }

            $pid = trim($raw);
            if ($pid === '' || !is_numeric($pid)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'invalid_pid',
                    'message' => 'The pid_file contains an invalid value.',
                ]);
            }

            // Liveness check BEFORE acking (probe semantics live in the
            // reloader): a stale pid file must yield a real error response,
            // never a cheerful ack the deferred signal would contradict.
            if (!$this->reloader->isSignallable((int) $pid)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'signal_send_failed',
                    'message' => sprintf('Process %d is not running or is not signallable.', (int) $pid),
                ]);
            }

            // Minor 55 — identity BEFORE authority: signal 0 only proves SOME
            // process holds this pid. After a master crash + PID recycle the
            // SIGUSR2 would land on an unrelated tenant while the hub answered
            // 200 and never reloaded. /proc's argv is the cheap, unspoofable
            // (by the hub's own standards) witness: the Workerman master
            // always carries start.php within its first two argv slots.
            $cmdline = $this->reloader->readCmdline((int) $pid);
            if ($cmdline === null || !self::cmdlineIdentifiesHub($cmdline)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'pid_identity_mismatch',
                    'message' => sprintf(
                        'Process %d exists but is not this hub\'s Workerman master '
                        . '(stale pid file with a recycled PID?). Refusing to signal it.',
                        (int) $pid
                    ),
                ]);
            }

            $this->reloader->scheduleGracefulReload((int) $pid);

            // The ack names what the operator just caused (review
            // hub__webux): SIGUSR2 is graceful but not invisible — workers
            // finish in-flight work and cycle, so live relay tunnels drop.
            // The reconnect itself belongs to the servers' own backoff
            // ladder, so no timing is promised here.
            return (new Response())->json([
                'success' => true,
                'message' => 'Restart signal sent — workers are cycling; active relay tunnels '
                . 'will drop and servers reconnect on their own.',
            ]);
        } catch (Throwable $e) {
            // Constant wire message: raw exception text can carry pid-file paths
            // and environment detail. Operators get the detail from the log.
            $this->logger->error('Hub restart failed', [
                'exception' => $e::class,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return (new Response())->status(500)->json([
                'success' => false,
                'error'   => 'restart_failed',
                'message' => 'Restart could not be scheduled',
            ]);
        }
    }

    /**
     * Pure identity law (Minor 55): a /proc cmdline (NUL-separated argv)
     * belongs to this hub's master when the interpreter or its script
     * argument — the first two argv slots — is named start.php. An
     * unrelated process merely holding the string deeper in its arguments
     * (`tail start.php`, `grep start.php …`) does NOT pass.
     */
    public static function cmdlineIdentifiesHub(string $cmdline): bool
    {
        $argv = array_values(array_filter(explode("\0", $cmdline), static fn (string $a): bool => $a !== ''));
        foreach (array_slice($argv, 0, 2) as $slot) {
            if (basename($slot) === 'start.php') {
                return true;
            }
        }

        return false;
    }
}
