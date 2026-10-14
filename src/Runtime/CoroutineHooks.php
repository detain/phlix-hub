<?php

/**
 * Phlix hub component: Runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Runtime;

use Workerman\Worker;

/**
 * The single worker-start step that installs the curated coroutine-hook
 * allowlist and proves it landed — the hub's equivalent of phlix-server's
 * `$applyCuratedCoroutineHooks` closure in `start.php`.
 *
 * ## Why a class here instead of a start.php closure there
 *
 * The server registers every `onWorkerStart` inline in `start.php`, so one
 * closure captured by each worker covers them all. The hub registers its
 * `onWorkerStart` callbacks across {@see \Phlix\Hub\Application::boot()} (HTTP
 * + maintenance closures) and inside the relay worker classes' `start()`
 * methods (relay :8802, client-relay :8803, syncplay :8804, federation :8805),
 * several of which live outside this lane's writable set. So the hub exposes
 * the enforcement as two composable statics:
 *
 *  - {@see apply()} — the statement to run FIRST inside an onWorkerStart the
 *    caller owns (HTTP, maintenance);
 *  - {@see wrap()} — prepends that statement to an onWorkerStart the caller
 *    does NOT own, by reassigning the Worker's public `$onWorkerStart` property
 *    (invoked by Workerman as `($this->onWorkerStart)($this)`) before
 *    `Worker::runAll()` forks the child.
 *
 * ## The delivery law it enforces (R14-01 / R16-01)
 *
 * `Workerman\Events\Swoole::__construct()` re-installs `SWOOLE_HOOK_ALL` in
 * every worker just before `onWorkerStart`, silently re-enabling the
 * FILE(io_uring)/PROC/CURL/blocking-function hooks behind the SIGSEGV class
 * crashes measured on this estate (200+ worker crashes a day without the
 * allowlist). {@see HookDelivery} re-installs the configured mask via the only
 * physically-effective API and PROVES delivery behaviourally with a
 * sibling-tick probe — never a `Coroutine::getOptions()` read, which reports
 * the curated mask whether or not it landed (the S433 silent-success trap).
 *
 * A worker whose configured mask did not land throws
 * {@see HookDeliveryException} out of this method — the caller's `try` (the
 * maintenance worker keeps one for its best-effort rebuild) MUST NOT wrap the
 * apply call, and {@see HookEnforcementGuardTest} pins the ordering. The loud
 * crash-loop is the designed fail mode: running undelivered is the site-down
 * state this whole mitigation exists to prevent.
 *
 * ## Testing seams
 *
 * `setEnforcerForTesting()` / `setLogSinkForTesting()` exist because PHPUnit
 * runs outside any worker: the real enforcer would mutate this process's
 * physical hook set and the real sink would write to STDOUT (red under
 * `beStrictAboutOutputDuringTests`). Composition tests inject; production
 * never does. {@see resetForTesting()} restores the real path afterwards.
 *
 * @package Phlix\Hub\Runtime
 * @since 1.3.0
 */
final class CoroutineHooks
{
    /**
     * Test seam replacing the whole enforce+verify step; null = the real
     * {@see HookDelivery::enforceAndVerify()}.
     *
     * @var (callable(int $mask): array{ticks: int, blocked_ms: float})|null
     */
    private static $enforcerOverride = null;

    /**
     * Test seam replacing `Worker::log()` for the delivery ACK; null = STDOUT.
     *
     * @var (callable(string $message): void)|null
     */
    private static $logSinkOverride = null;

    /**
     * Run the curated-hook enforcement + behavioural delivery proof for one
     * worker start, then log the ACK. FIRST statement of every onWorkerStart.
     *
     * No-ops when ext-swoole is absent — the same graceful-degradation rule
     * the bootstrap follows, so install scripts and suites boot on hosts
     * without the extension.
     *
     * @param mixed $config The full server config array (reads `coroutine.*`).
     *
     * @throws HookDeliveryException the configured mask did not land, or the
     *                               probe could not decide (never downgrade
     *                               this to a warning — see the class docblock).
     */
    public static function apply(mixed $config): void
    {
        if (!extension_loaded('swoole')) {
            return;
        }

        $mask = SwooleRuntime::runtimeHookMask($config);
        $sample = self::deliver($mask);

        self::log(sprintf(
            '[phlix] hub worker #%d coroutine hook allowlist DELIVERED: mask=0x%04x, probe %d ticks / %.0f ms',
            (int) (getmypid() ?: -1),
            $mask,
            $sample['ticks'],
            $sample['blocked_ms']
        ));
    }

    /**
     * Prepend {@see apply()} to a worker's existing onWorkerStart callback.
     *
     * Use for workers whose callback is set inside a class this file may not
     * edit — the wrap happens in the MASTER before `Worker::runAll()` forks, so
     * every child inherits the composed callback and Workerman's own dispatch
     * (`($this->onWorkerStart)($this)`) sees no difference.
     *
     * @param Worker $worker The worker returned by the class's start().
     * @param mixed  $config The full server config array.
     */
    public static function wrap(Worker $worker, mixed $config): void
    {
        $original = $worker->onWorkerStart;

        $worker->onWorkerStart = static function (Worker $w) use ($original, $config): void {
            self::apply($config);

            if (is_callable($original)) {
                $original($w);
            }
        };
    }

    /**
     * The configured delivery step: the injected test enforcer when present,
     * else the real install-and-prove.
     *
     * @return array{ticks: int, blocked_ms: float}
     */
    private static function deliver(int $mask): array
    {
        $enforcer = self::$enforcerOverride;
        if ($enforcer !== null) {
            return $enforcer($mask);
        }

        return HookDelivery::enforceAndVerify($mask);
    }

    /**
     * Route the delivery ACK to the injected test sink or `Worker::log()`.
     */
    private static function log(string $message): void
    {
        $sink = self::$logSinkOverride;
        if ($sink !== null) {
            $sink($message);

            return;
        }

        Worker::log($message);
    }

    /**
     * @param (callable(int $mask): array{ticks: int, blocked_ms: float})|null $enforcer
     */
    public static function setEnforcerForTesting(?callable $enforcer): void
    {
        self::$enforcerOverride = $enforcer;
    }

    /**
     * @param (callable(string $message): void)|null $sink
     */
    public static function setLogSinkForTesting(?callable $sink): void
    {
        self::$logSinkOverride = $sink;
    }

    /**
     * Restore the production enforcement + logging path.
     */
    public static function resetForTesting(): void
    {
        self::$enforcerOverride = null;
        self::$logSinkOverride = null;
    }
}
