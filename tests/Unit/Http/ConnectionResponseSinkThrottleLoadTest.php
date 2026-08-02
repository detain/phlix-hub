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
use function hrtime;
use function max;
use function microtime;
use function sprintf;
use function str_repeat;
use function strlen;

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

    private ?string $previousEventLoopClass = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to exercise the production Timer::sleep() pacing path.');
        }

        $this->previousEventLoopClass = Worker::$eventLoopClass;
        Worker::$eventLoopClass = SwooleEventLoop::class;

        // Silence the per-yield TRACE spam emitted by trace-log-enabled ext-swoole
        // builds (present on the dev box, absent from the CI build). Purely
        // cosmetic — it changes no scheduling behaviour.
        Coroutine::set(['trace_flags' => 0]);
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
    public function test_throttled_proxy_body_realises_its_cap_on_a_real_clock(): void
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
