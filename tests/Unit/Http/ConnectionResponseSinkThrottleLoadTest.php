<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\ConnectionResponseSink;
use Phlix\Hub\Relay\TokenBucket;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Swoole as SwooleEventLoop;
use Workerman\Worker;

use function extension_loaded;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function hrtime;
use function is_array;
use function max;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function posix_getpid;
use function posix_kill;
use function serialize;
use function sprintf;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function unserialize;
use function usleep;

use const SIGKILL;
use const WNOHANG;

/**
 * S43 acceptance-criteria LOAD test for the HTTP-over-relay proxy throttle,
 * measured against a REAL clock inside a REAL Swoole coroutine scheduler.
 *
 * The pre-existing `ConnectionResponseSinkTest` throttle cases drive a *virtual*
 * clock that advances only when the injected sleeper is called, so their
 * denominator is "the time the pacing loop asked to sleep" — self-consistent,
 * but not a wall-clock rate, and it exercises neither the production sleeper
 * ({@see \Workerman\Timer::sleep()}) nor the production clock
 * ({@see microtime()}). This test closes both gaps.
 *
 * ### How the rate is measured
 * - **Numerator** — `strlen()` of every string handed to
 *   {@see TcpConnection::send()}, counted per call inside the connection double.
 *   Not the sink's own `bytesStreamed()` counter, not the bucket balance.
 * - **Denominator** — real wall clock from {@see hrtime()} (nanoseconds,
 *   monotonic), sampled immediately before the first `body()` and immediately
 *   after the last one. The bucket is deliberately seeded EMPTY so the window
 *   contains no free burst and the figure is the *sustained* rate; the burst
 *   behaviour is covered analytically by the virtual-clock tests.
 * - **Control (A/B)** — an identical Unlimited stream of the same byte volume
 *   runs in the same scheduler; its elapsed time is the "no throttle" baseline.
 *   Without it, a slow box could make a broken throttle look correct.
 *
 * ### Why it needs a Swoole coroutine scheduler
 * {@see \Workerman\Timer::sleep()} dispatches on `Worker::$eventLoopClass`: it
 * yields the coroutine only for the Fiber and Swoole drivers and otherwise falls
 * through to `usleep()`. Production sets the Swoole driver in `start.php`, so
 * setting it here is what makes the pacing wait take its real branch.
 *
 * ### What the concurrent ticker does and does NOT discriminate (measured)
 * ext-swoole 6.2.1 hooks the sleep family inside a coroutine BY DEFAULT, so
 * swapping `Timer::sleep()` for a raw `usleep()` still yields and the ticker
 * cannot tell them apart (measured: 863 ticks either way). What the ticker DOES
 * catch, sharply, is an un-yieldable block: replacing the sleeper with a
 * `microtime()` busy-wait drops the counter from ~860 to **1**. So the ticker is
 * a genuine detector for "this wait froze the worker", which is the property the
 * cardinal rule is about — it is not a detector for "which sleep function was
 * called". The corollary worth knowing: the non-blocking guarantee is delivered
 * by the Swoole runtime, so it holds only while ext-swoole is loaded — `start.php`
 * merely warns when it is absent, and `Timer::sleep()` then blocks for real.
 *
 * ### Why the measurement runs in a forked child
 * Xdebug installs a `zend_observer` fcall begin/end pair in EVERY mode that
 * needs to know which function is executing — including `xdebug.mode=coverage`,
 * which is what CI's PHPUnit job uses (`.github/workflows/ci.yml`,
 * `coverage: xdebug`). Swoole gives each coroutine its OWN `zend_execute_data`
 * stack and frees it when the coroutine ends, so the observer's begin records
 * for those frames are never balanced by an end. At `php_request_shutdown()`
 * Zend calls `zend_observer_fcall_end_all()`, which walks the leftover frames
 * and hands each one to Xdebug — pointing at coroutine stacks that Swoole has
 * already released. Xdebug dereferences the dangling `execute_data` and the
 * process dies with SIGSEGV (exit 139) AFTER the suite has reported OK.
 *
 * Measured on ext-swoole 6.2.1 + Xdebug 3.5.1 + PHP 8.3:
 * - two concurrent coroutines that yield is the minimum reproducer; ONE
 *   coroutine that yields does not crash,
 * - the crash is NOT a leaked coroutine that could be drained: at the point of
 *   the crash `Coroutine::stats()['coroutine_num']` is `0` and
 *   `Coroutine::getCid()` is `-1`, i.e. every coroutine has already been joined
 *   and the scheduler wound down. There is no userland state left to clean up,
 *   so no amount of joining/draining inside this test can avoid it,
 * - gdb backtrace: `xdebug_execute_user_code_end` <- `xdebug_execute_end`
 *   <- `zend_observer_fcall_end_all` <- `php_request_shutdown`.
 *
 * The fix is therefore isolation, not avoidance: the whole coroutine run
 * happens in a `pcntl_fork()` child that reports its measurements back over a
 * temp file and then removes itself with `SIGKILL`, so PHP's request shutdown —
 * and with it `zend_observer_fcall_end_all()` — never runs on a process that
 * has touched a coroutine stack. Every assertion below still executes, on real
 * measured numbers, in every environment. A child that dies before reporting is
 * a hard test FAILURE, never a silent pass.
 *
 * @covers \Phlix\Hub\Http\ConnectionResponseSink
 * @covers \Phlix\Hub\Relay\TokenBucket
 */
final class ConnectionResponseSinkThrottleLoadTest extends TestCase
{
    /** Sustained cap under test: 2 Mbps = 250 000 bytes/sec. */
    private const int CAP_BPS = 2_000_000;

    /** Second, different cap proving per-stream isolation: 6 Mbps. */
    private const int OTHER_CAP_BPS = 6_000_000;

    private const int FRAGMENT_BYTES = 12_500;
    private const int FRAGMENTS = 20; // 250 000 bytes == 1.0 s at the 2 Mbps cap

    /**
     * Wall-clock budget for the forked measurement child. The run itself is
     * ~1.0 s (the 2 Mbps stream is the long pole); this is a hang guard, not a
     * timing assertion, so it is deliberately two orders of magnitude looser.
     */
    private const int CHILD_BUDGET_SECONDS = 120;

    private ?string $previousEventLoopClass = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to exercise the production Timer::sleep() pacing path.');
        }

        // The coroutine run has to happen in a forked child — see the class
        // docblock: Xdebug's zend_observer hook segfaults at request shutdown on
        // any process that has run concurrent Swoole coroutines. CI installs
        // pcntl + posix explicitly (`.github/workflows/ci.yml`), so this guard
        // is for stripped-down PHP builds only, never for the pipeline.
        foreach (['pcntl_fork', 'pcntl_waitpid', 'posix_kill', 'posix_getpid'] as $required) {
            if (!function_exists($required)) {
                $this->markTestSkipped(
                    "ext-pcntl + ext-posix are required ({$required}() is missing): the Swoole scheduler run must be "
                    . 'forked into a throwaway child so PHP request shutdown never walks a freed coroutine stack.',
                );
            }
        }

        $this->previousEventLoopClass = Worker::$eventLoopClass;
        Worker::$eventLoopClass = SwooleEventLoop::class;
    }

    protected function tearDown(): void
    {
        Worker::$eventLoopClass = $this->previousEventLoopClass;

        parent::tearDown();
    }

    /**
     * S43 AC: a throttled HTTP-proxy body stream's observed throughput matches
     * its configured cap; a concurrently-streaming connection with a DIFFERENT
     * cap realises ITS own cap; an Unlimited stream is untouched; and the pacing
     * wait never blocks the scheduler.
     */
    public function testThrottledProxyBodyRealisesItsCapOnARealClock(): void
    {
        $measurement = $this->measureInForkedChild();
        $result = $measurement['streams'];
        $ticks = $measurement['ticks'];

        $capBytes = self::CAP_BPS / 8.0;
        $otherCapBytes = self::OTHER_CAP_BPS / 8.0;
        $expected = self::FRAGMENTS * self::FRAGMENT_BYTES;

        // Every byte reached the socket on all three streams.
        foreach (['throttled', 'other', 'unlimited'] as $key) {
            $this->assertSame($expected, $result[$key]['bytes'], "{$key}: not all bytes reached the socket");
        }

        $rate = $result['throttled']['bytes'] / $result['throttled']['seconds'];
        $otherRate = $result['other']['bytes'] / $result['other']['seconds'];
        // The Unlimited control can complete inside the clock's resolution;
        // floor its window so the ratio below is a lower bound, never a divide.
        $unlimitedRate = $result['unlimited']['bytes'] / max($result['unlimited']['seconds'], 1.0e-6);

        $detail = sprintf(
            'throttled: %d B in %.4f s = %.0f B/s (cap %.0f B/s) | '
            . 'other: %d B in %.4f s = %.0f B/s (cap %.0f B/s) | '
            . 'unlimited: %d B in %.4f s = %.0f B/s | scheduler ticks during run: %d',
            $result['throttled']['bytes'],
            $result['throttled']['seconds'],
            $rate,
            $capBytes,
            $result['other']['bytes'],
            $result['other']['seconds'],
            $otherRate,
            $otherCapBytes,
            $result['unlimited']['bytes'],
            $result['unlimited']['seconds'],
            $unlimitedRate,
            $ticks,
        );

        // 1. The throttled stream tracks its own cap on a real clock.
        //    Ceiling: `awaitThrottleBudget()` gates on ANY positive budget (so an
        //    oversized fragment can never deadlock), which grants exactly ONE
        //    fragment of head start even from an empty bucket. The analytic
        //    ceiling is therefore cap x bytes/(bytes - fragment), plus 2% slack.
        //    Floor: real Timer::sleep granularity and the
        //    THROTTLE_MIN_SLEEP_SECONDS floor can only ever make a stream SLOWER
        //    than its cap, so the low side is loose (shared-CI tolerance).
        $headStartCeiling = $capBytes * ($expected / ($expected - self::FRAGMENT_BYTES)) * 1.02;
        $this->assertLessThanOrEqual($headStartCeiling, $rate, "throttle OVER-delivered — {$detail}");
        $this->assertGreaterThanOrEqual($capBytes * 0.70, $rate, "throttle UNDER-delivered — {$detail}");

        // 2. A/B control: the Unlimited stream is the "no throttle" baseline. If
        //    the box were merely slow, this would be slow too.
        $this->assertGreaterThan(
            20.0,
            $unlimitedRate / $rate,
            "the Unlimited control was not dramatically faster than the throttled stream — {$detail}",
        );

        // 3. Per-stream isolation: the 6 Mbps stream realises ~3x the 2 Mbps one,
        //    concurrently, in the same worker. Neither cap leaks onto the other.
        $otherCeiling = $otherCapBytes * ($expected / ($expected - self::FRAGMENT_BYTES)) * 1.02;
        $this->assertLessThanOrEqual($otherCeiling, $otherRate, "second stream OVER-delivered — {$detail}");
        $this->assertGreaterThan(
            2.0,
            $otherRate / $rate,
            "the 6 Mbps stream was dragged toward the 2 Mbps stream’s cap — {$detail}",
        );

        // 4. The pacing wait yielded: the scheduler kept running throughout.
        // A 1 ms ticker over a ~0.95 s paced run reaches ~860 ticks while the wait
        // keeps yielding. Measured discrimination (not assumed): replacing the
        // sleeper with an un-yieldable busy-wait drops this counter to 1. The
        // threshold sits two orders of magnitude clear of that.
        $this->assertGreaterThan(
            300,
            $ticks,
            "the pacing wait blocked the scheduler (only {$ticks} ticks) — {$detail}",
        );
    }

    /**
     * Fork a child, run the whole Swoole scheduler workload there, and bring the
     * measurements back.
     *
     * The child never reaches PHP's request shutdown: once its report is on disk
     * it SIGKILLs itself, which is what keeps `zend_observer_fcall_end_all()`
     * away from the coroutine stacks Swoole has already freed (see the class
     * docblock). The parent process therefore never runs a coroutine at all and
     * shuts down normally under any coverage driver.
     *
     * Every failure mode of the child — fork refused, child killed, child exited
     * without a report, unreadable report — is an explicit assertion failure
     * naming the wait status. None of them can be mistaken for a pass.
     *
     * @return array{streams: array<string, array{bytes:int, seconds:float}>, ticks:int}
     */
    private function measureInForkedChild(): array
    {
        $reportFile = tempnam(sys_get_temp_dir(), 'phlix-throttle-load-');
        if ($reportFile === false) {
            $this->fail('could not allocate a temp file for the forked measurement report');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            unlink($reportFile);
            $this->fail('pcntl_fork() failed — the Swoole scheduler run cannot be isolated from this process');
        }

        if ($pid === 0) {
            // ---- child ----
            // Silence the per-yield TRACE spam emitted by trace-log-enabled
            // ext-swoole builds (present on the dev box, absent from the CI
            // build). Purely cosmetic — it changes no scheduling behaviour.
            Coroutine::set(['trace_flags' => 0]);

            file_put_contents($reportFile, serialize($this->runSchedulerWorkload()));

            // Leave WITHOUT running php_request_shutdown(). Any normal exit path
            // (return, exit(), or an uncaught error) would run the Zend observer
            // teardown that segfaults on this process. SIGKILL cannot be caught,
            // so nothing below this line ever executes.
            posix_kill(posix_getpid(), SIGKILL);
            exit(1);
        }

        // ---- parent ----
        $status = 0;
        $deadlineNs = hrtime(true) + (self::CHILD_BUDGET_SECONDS * 1_000_000_000);
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (hrtime(true) > $deadlineNs) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                unlink($reportFile);
                $this->fail(
                    'the forked measurement child did not finish within '
                    . self::CHILD_BUDGET_SECONDS . 's and was killed',
                );
            }

            usleep(2000);
        }

        $raw = file_get_contents($reportFile);
        unlink($reportFile);

        // SIGKILL by our own hand is the ONLY expected end for the child. Spell
        // every other outcome out, so a child that died before writing its
        // report can never read as a skipped or passing measurement.
        $ended = 'ended with an unrecognised wait status ' . $status;
        if (pcntl_wifsignaled($status)) {
            $ended = 'was killed by signal ' . pcntl_wtermsig($status);
        } elseif (pcntl_wifexited($status)) {
            $ended = 'exited with code ' . pcntl_wexitstatus($status);
        }

        if ($raw === false || $raw === '') {
            $this->fail("the forked measurement child produced no report — it {$ended}");
        }

        /** @var mixed $decoded */
        $decoded = unserialize($raw, ['allowed_classes' => false]);
        if (
            !is_array($decoded)
            || !isset($decoded['streams'], $decoded['ticks'])
            || !is_array($decoded['streams'])
        ) {
            $this->fail("the forked measurement child wrote an unreadable report — it {$ended}");
        }

        // The isolation checks ITSELF: the child's only sanctioned exit is the
        // SIGKILL it raises on itself. If a later edit lets the child fall
        // through to PHP's request shutdown instead, it dies of SIGSEGV (11)
        // under Xdebug — the report would still have been written first, so the
        // measurement would pass while the crash quietly came back. Pin the
        // terminating signal so that regression is RED, not invisible.
        $this->assertTrue(
            pcntl_wifsignaled($status),
            "the forked measurement child must terminate by signal, but it {$ended} — "
            . 'the Swoole/Xdebug shutdown isolation has been neutered',
        );
        $this->assertSame(
            SIGKILL,
            pcntl_wtermsig($status),
            "the forked measurement child must terminate by SIGKILL, but it {$ended} — "
            . 'the Swoole/Xdebug shutdown isolation has been neutered',
        );

        /** @var array{streams: array<string, array{bytes:int, seconds:float}>, ticks:int} $decoded */
        return $decoded;
    }

    /**
     * The measurement itself: three concurrently streaming connections with
     * different caps plus a 1 ms scheduler-tick probe, all on one real Swoole
     * scheduler. Runs ONLY inside the forked child.
     *
     * @return array{streams: array<string, array{bytes:int, seconds:float}>, ticks:int}
     */
    private function runSchedulerWorkload(): array
    {
        /** @var array<string, array{bytes:int, seconds:float}> $result */
        $result = [];
        $ticks = 0;
        $done = 0;

        Coroutine\run(function () use (&$result, &$ticks, &$done): void {
            // Concurrency probe: if the pacing wait blocked the process instead
            // of yielding the coroutine, this loop could never advance.
            Coroutine::create(static function () use (&$ticks, &$done): void {
                while ($done < 3) {
                    Coroutine::sleep(0.001);
                    $ticks++;
                }
            });

            Coroutine::create(function () use (&$result, &$done): void {
                $result['throttled'] = $this->streamThrottled(self::CAP_BPS);
                $done++;
            });

            Coroutine::create(function () use (&$result, &$done): void {
                $result['other'] = $this->streamThrottled(self::OTHER_CAP_BPS);
                $done++;
            });

            Coroutine::create(function () use (&$result, &$done): void {
                $result['unlimited'] = $this->streamThrottled(0);
                $done++;
            });
        });

        return ['streams' => $result, 'ticks' => $ticks];
    }

    /**
     * Stream a fixed byte volume through a real {@see ConnectionResponseSink}
     * using the PRODUCTION sleeper and clock, and return the socket-observed
     * byte total plus the real elapsed seconds.
     *
     * The bucket is seeded EMPTY (its whole capacity pre-spent) so the measured
     * window contains no free burst and the figure is the sustained rate.
     *
     * @param int $capBps Cap in bits/sec; `0` = Unlimited (no bucket).
     *
     * @return array{bytes:int, seconds:float}
     */
    private function streamThrottled(int $capBps): array
    {
        $connection = new class extends TcpConnection {
            public int $bytes = 0;

            public function __construct()
            {
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                $this->bytes += strlen((string) $sendBuffer);

                return true;
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
            }
        };

        $bucket = TokenBucket::fromThrottleBps($capBps, microtime(true));
        $bucket?->spend($bucket->capacity());

        // No injected sleeper/clock: the sink uses Workerman\Timer::sleep() and
        // microtime() exactly as it does in production.
        $sink = new ConnectionResponseSink($connection, 'GET', $bucket);
        // Fixed-length framing on purpose: body() then writes RAW bytes, so the
        // socket byte counter is exactly the body bytes the cap governs (chunked
        // framing would add per-fragment chunk headers to the numerator).
        $sink->head(200, [
            'Content-Type' => 'video/mp2t',
            'Content-Length' => (string) (self::FRAGMENTS * self::FRAGMENT_BYTES),
        ]);

        $fragment = str_repeat('x', self::FRAGMENT_BYTES);
        $headBytes = $connection->bytes;

        $startNs = hrtime(true);
        for ($i = 0; $i < self::FRAGMENTS; $i++) {
            $sink->body($fragment);
        }
        $elapsed = (hrtime(true) - $startNs) / 1_000_000_000;
        $sink->end();

        return [
            // Subtract the head and the terminating chunk so the numerator is
            // exactly the body bytes the cap governs.
            'bytes' => $connection->bytes - $headBytes,
            'seconds' => $elapsed,
        ];
    }
}
