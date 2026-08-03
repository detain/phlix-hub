<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

/**
 * S179 — end a coroutine fixture process BEFORE PHP's request shutdown runs.
 *
 * ## The crash this avoids (root cause, established by gdb — not guessed)
 *
 * CI's PHPUnit job runs `coverage: xdebug`. In coverage mode Xdebug installs a
 * `zend_observer` fcall begin/end pair. Swoole gives every coroutine its OWN
 * `zend_execute_data` stack and frees it when the coroutine ends, so the
 * observer's *begin* records for those frames are never balanced by an *end*.
 * At `php_request_shutdown()` Zend calls `zend_observer_fcall_end_all()`, which
 * walks the leftover frames and hands each to Xdebug — pointing into coroutine
 * stacks Swoole already released:
 *
 * ```
 * #0 xdebug_execute_user_code_end (base.c:811)   <- dangling execute_data
 * #1 xdebug_execute_end (base.c:1053)
 * #2 zend_observer_fcall_end_all ()
 * #3 php_request_shutdown ()
 * ```
 *
 * Measured on this repo's fixtures (PHP 8.3.6, ext-swoole 6.2.1, Xdebug 3.5.1):
 * `transaction_lock_smoke.php` exits **139 (SIGSEGV)** under
 * `-d xdebug.mode=coverage` and **0** without Xdebug; `pool_harness.php` exits
 * 139 for the three scenarios that hold two coroutines concurrently
 * (`distinct`, `exhaust_handoff`, `exhaust_throw`) and 0 for the four
 * sequential ones. The markers are printed *before* shutdown, which is why
 * `shell_exec('… 2>/dev/null')` — a call that returns stdout and discards the
 * exit status — read good output from a process that then died.
 *
 * ## Why it cannot be fixed in userland
 *
 * At the moment of the crash `Coroutine::stats()['coroutine_num']` is 0,
 * `Coroutine::getCid()` is -1 and `gc_collect_cycles()` runs clean: every
 * coroutine is already joined. The dangling pointer lives in Zend's observer
 * bookkeeping, which PHP code cannot reach, so no drain/join helps. Two other
 * plausible fixes were measured and are FALSE:
 *
 *  - `#[RunClassInSeparateProcess]` — PHPUnit's child is launched with Xdebug
 *    too and segfaults identically, and PHPUnit ignores a crashed child's exit
 *    code, so the suite goes green while the process dies.
 *  - excluding the file from coverage instrumentation — a reproducer that never
 *    calls `xdebug_start_code_coverage()` still dies; `xdebug.mode=coverage`
 *    alone installs the observer.
 *
 * ## The isolation
 *
 * The fixture kills itself with an uncatchable SIGKILL after flushing its
 * markers, so request shutdown — and therefore
 * `zend_observer_fcall_end_all()` — never runs in a process that touched a
 * coroutine stack. {@see SwooleFixtureProcess} asserts that this is exactly
 * how the child died, so removing this call fails the test on **both** coverage
 * drivers, including the pcov dev box where the crash itself cannot manifest.
 *
 * @package Phlix\Hub\Tests\Support
 */
final class SwooleShutdownIsolation
{
    /**
     * The signal a fixture must die of. SIGKILL (9) is uncatchable and cannot be
     * handled, so no shutdown function, destructor or observer callback can run
     * after it — which is the whole point.
     *
     * A literal rather than the `SIGKILL` constant: that constant is defined by
     * ext-pcntl, and this class must not fatal on a build without it (it reports
     * the missing mechanism instead, see {@see NO_SIGNAL_MECHANISM_EXIT_CODE}).
     */
    public const SIGKILL = 9;

    /**
     * Exit code used when the process has no way to signal itself (neither
     * ext-posix nor Swoole's process API). Deliberately NOT 0: a fixture that
     * cannot isolate itself must fail its test loudly rather than look normal.
     */
    public const NO_SIGNAL_MECHANISM_EXIT_CODE = 79;

    /**
     * Flush every byte the parent still has to read, then SIGKILL this process.
     *
     * Ordering matters: the markers are written to a pipe the parent reads after
     * the child dies (the pipe holds the data; EOF arrives only once it has been
     * consumed), so they must be out of PHP's output buffer *before* the signal.
     */
    public static function terminateWithoutRequestShutdown(): never
    {
        // Bounded by the level snapshot, and stops early if a buffer refuses to
        // be flushed — `while (ob_get_level() > 0)` would spin forever on an
        // unremovable buffer.
        for ($level = ob_get_level(); $level > 0; $level--) {
            if (ob_end_flush() === false) {
                break;
            }
        }

        flush();

        if (defined('STDOUT')) {
            fflush(STDOUT);
        }

        if (defined('STDERR')) {
            fflush(STDERR);
        }

        $pid = getmypid();

        if ($pid !== false) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, self::SIGKILL);
            } elseif (class_exists('\Swoole\Process')) {
                /** @psalm-suppress UndefinedClass */
                \Swoole\Process::kill($pid, self::SIGKILL);
            }
        }

        // Reached only when the signal could not be delivered — an uncatchable
        // SIGKILL to self never returns. Say so on stderr; the parent surfaces
        // it verbatim.
        fwrite(
            STDERR,
            "S179: this fixture could not SIGKILL itself (getmypid()="
            . var_export($pid, true) . ', posix_kill='
            . (function_exists('posix_kill') ? 'yes' : 'no')
            . '). PHP request shutdown is about to run on a process that used Swoole '
            . "coroutines, which segfaults under xdebug.mode=coverage.\n",
        );

        exit(self::NO_SIGNAL_MECHANISM_EXIT_CODE);
    }
}
