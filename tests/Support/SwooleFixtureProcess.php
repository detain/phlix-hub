<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * S179 — run a coroutine fixture in a child process and ASSERT how it died.
 *
 * ## The defect this replaces
 *
 * Both coroutine fixtures used to be launched with
 * `shell_exec('… 2>/dev/null')`, which returns the child's stdout and
 * **discards its exit status entirely**. The fixtures print their markers before
 * PHP's request shutdown, so every assertion read good output from a process
 * that then died of SIGSEGV in `zend_observer_fcall_end_all()` (see
 * {@see SwooleShutdownIsolation} for the root cause). Measured on master:
 * `transaction_lock_smoke.php` exits **139** under CI's `coverage: xdebug`
 * shape, and the test passed anyway. `2>/dev/null` also threw away the only
 * diagnostic a broken fixture would have produced.
 *
 * ## What this class guarantees
 *
 * The child's **only** sanctioned exit is death by
 * {@see SwooleShutdownIsolation::SIGKILL}. Anything else fails the calling test:
 *
 * | how the child ended                     | verdict                                    |
 * | --------------------------------------- | ------------------------------------------ |
 * | killed by SIGKILL (9)                   | pass — isolation intact                    |
 * | killed by any other signal (e.g. SEGV)  | **fail** — names the signal                |
 * | exited normally, any code incl. 0       | **fail** — "the isolation has been neutered" |
 * | still running after its budget          | **fail** — killed, then reported           |
 * | `proc_open()` refused to start it       | **fail**                                   |
 *
 * The "exited normally" row is what makes this guard work on the **pcov dev
 * box**, where the Xdebug shutdown crash cannot manifest at all: deleting the
 * fixture's `terminateWithoutRequestShutdown()` call makes the child exit 0, and
 * exit 0 is a failure here. A guard that only noticed the segfault would be
 * invisible locally and could be removed without anything going red until CI.
 *
 * ⚠ `proc_get_status()` may be read only ONCE after the child is reaped —
 * measured on PHP 8.3.6, the first call after termination reports
 * `signaled=true, termsig=9` and every later call reports
 * `signaled=false, termsig=0, exitcode=-1`. The status is therefore captured in
 * a single variable and never re-read.
 *
 * ⚠ No shell is involved: `proc_open()` is given an argv **array**, so there is
 * no `2>/dev/null` to hide stderr and no shell in between to translate a signal
 * death into a 128+N exit code (which would be indistinguishable from a genuine
 * exit code).
 *
 * @package Phlix\Hub\Tests\Support
 */
final class SwooleFixtureProcess
{
    /**
     * Wall-clock budget for a fixture run. The slowest scenario in the repo is
     * `pool_harness.php exhaust_throw`, which waits out the pool's hardcoded
     * `idle->pop(10.0)` timeout, so ~11 s is the real high-water mark.
     */
    public const DEFAULT_BUDGET_SECONDS = 120.0;

    /**
     * Run `$script` with `$args` and return its output, having asserted that it
     * terminated the one sanctioned way.
     *
     * @param string       $script         Absolute path to the fixture script.
     * @param list<string> $args           Extra argv entries for the fixture.
     * @param float        $budgetSeconds  Wall-clock budget before the child is
     *                                     killed and the test failed.
     *
     * @return array{stdout: string, stderr: string, termsig: int}
     */
    public static function run(
        string $script,
        array $args = [],
        float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
    ): array {
        Assert::assertFileExists($script, 'the coroutine fixture script must exist');

        $command = array_merge(
            [PHP_BINARY, '-d', 'swoole.enable_library=1', $script],
            $args,
        );

        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            Assert::fail(sprintf(
                'S179: proc_open() could not launch the coroutine fixture: %s',
                implode(' ', $command),
            ));
        }

        [$stdout, $stderr, $timedOut] = self::drain($pipes, $budgetSeconds);

        // Captured exactly once — see the class docblock.
        $status = proc_get_status($process);
        $spentWaiting = 0.0;
        while ($status['running'] === true && $spentWaiting < 10.0) {
            usleep(2000);
            $spentWaiting += 0.002;
            $status = proc_get_status($process);
        }

        $stillRunning = $status['running'] === true;
        if ($timedOut || $stillRunning) {
            proc_terminate($process, SwooleShutdownIsolation::SIGKILL);
            proc_close($process);

            Assert::fail(sprintf(
                'S179: the coroutine fixture %s did not finish within its %.1f s budget '
                . "(still running: %s) and was killed. stdout:\n%s\nstderr:\n%s",
                basename($script) . ($args === [] ? '' : ' ' . implode(' ', $args)),
                $budgetSeconds,
                $stillRunning ? 'yes' : 'no',
                $stdout,
                $stderr,
            ));
        }

        $signaled = $status['signaled'] === true;
        $termsig = (int) $status['termsig'];
        $exitCode = (int) $status['exitcode'];
        proc_close($process);

        $what = basename($script) . ($args === [] ? '' : ' ' . implode(' ', $args));

        if (!$signaled) {
            Assert::fail(sprintf(
                'S179: the coroutine fixture child (%s) must terminate by signal %d (SIGKILL), '
                . 'but it exited with code %d — the Swoole/Xdebug shutdown isolation has been '
                . 'neutered. Under CI\'s `coverage: xdebug` shape PHP\'s request shutdown '
                . 'segfaults on coroutine stacks Swoole already freed '
                . '(php_request_shutdown -> zend_observer_fcall_end_all -> xdebug_execute_end), '
                . 'so the fixture MUST end with '
                . 'SwooleShutdownIsolation::terminateWithoutRequestShutdown(). '
                . "stdout:\n%s\nstderr:\n%s",
                $what,
                SwooleShutdownIsolation::SIGKILL,
                $exitCode,
                $stdout,
                $stderr,
            ));
        }

        if ($termsig !== SwooleShutdownIsolation::SIGKILL) {
            Assert::fail(sprintf(
                'S179: the coroutine fixture child (%s) died of signal %d (%s) instead of '
                . 'signal %d (SIGKILL). Signal 11 (SIGSEGV) means PHP request shutdown ran on a '
                . 'process that used Swoole coroutines — the known Xdebug observer crash — so the '
                . "isolation is missing, not merely late. stdout:\n%s\nstderr:\n%s",
                $what,
                $termsig,
                self::signalName($termsig),
                SwooleShutdownIsolation::SIGKILL,
                $stdout,
                $stderr,
            ));
        }

        return ['stdout' => $stdout, 'stderr' => $stderr, 'termsig' => $termsig];
    }

    /**
     * Read both pipes to EOF without deadlocking on either.
     *
     * A pair of blocking `stream_get_contents()` calls would hang if the child
     * filled the pipe this side is not reading (64 KiB on Linux); `stream_select`
     * takes whichever has data. The budget is enforced here because a fixture
     * that hangs holds its pipes open forever.
     *
     * @param array<int, resource> $pipes
     *
     * @return array{0: string, 1: string, 2: bool} stdout, stderr, timed-out
     */
    private static function drain(array $pipes, float $budgetSeconds): array
    {
        $stdout = '';
        $stderr = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }

        $deadline = microtime(true) + $budgetSeconds;
        $timedOut = false;

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                $timedOut = true;
                break;
            }

            $slice = min($remaining, 1.0);
            $read = array_values($open);
            $write = [];
            $except = [];

            $ready = @stream_select(
                $read,
                $write,
                $except,
                (int) $slice,
                (int) (($slice - floor($slice)) * 1_000_000),
            );

            if ($ready === false) {
                // Interrupted (e.g. a stray SIGALRM from Workerman's pcntl timer
                // fallback, which tests/bootstrap.php swallows) — retry.
                continue;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);

                if (is_string($chunk) && $chunk !== '') {
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }

                    continue;
                }

                if (feof($stream)) {
                    foreach ($open as $key => $candidate) {
                        if ($candidate === $stream) {
                            fclose($candidate);
                            unset($open[$key]);
                        }
                    }
                }
            }
        }

        foreach ($open as $stream) {
            fclose($stream);
        }

        return [$stdout, $stderr, $timedOut];
    }

    /**
     * Human-readable name for the signals a fixture can plausibly die of, so a
     * failure message says SIGSEGV rather than only `11`.
     */
    private static function signalName(int $signal): string
    {
        $names = [
            2  => 'SIGINT',
            4  => 'SIGILL',
            6  => 'SIGABRT',
            8  => 'SIGFPE',
            9  => 'SIGKILL',
            11 => 'SIGSEGV',
            13 => 'SIGPIPE',
            14 => 'SIGALRM',
            15 => 'SIGTERM',
        ];

        return $names[$signal] ?? 'signal ' . $signal;
    }
}
