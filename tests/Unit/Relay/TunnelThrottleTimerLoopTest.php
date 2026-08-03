<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\TokenBucket;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Timer;

use function count;
use function fwrite;
use function getenv;
use function max;
use function microtime;
use function min;
use function sprintf;
use function str_repeat;
use function strlen;

/**
 * S42: the throttle drain timer, measured while a REAL Workerman event loop runs.
 *
 * ## Why this exists — the two blind spots it closes
 *
 * Every other S42 test drives the drain by calling the private
 * `Tunnel::drainThrottled()` through reflection, i.e. it simulates what the
 * `Workerman\Timer` callback would do. None of them ever EXECUTES the closure
 * `Tunnel::armThrottleDrain()` hands to `Timer::add()`. That closure is the only
 * thing that releases a throttled client's backlog once its bucket runs dry, so:
 *
 * 🔴 Measured on master (2026-08-03): replacing the closure body with an empty
 * one — which stalls every throttled stream permanently after its first burst —
 * left the whole suite green (2532 tests, 19894 assertions, exit 0, byte-identical
 * to baseline). `armThrottleDrain()` swallows the `Timer::add()` failure that
 * happens outside a Workerman runtime, so off-loop tests cannot see the callback
 * at all.
 *
 * Here a real {@see Select} event driver is installed into `Timer::$event`, so
 * `Timer::add()` takes its real dispatch branch and the loop invokes the
 * PRODUCTION closure on the PRODUCTION 50 ms cadence. After the initial prefill
 * nothing else can deliver a byte: every byte counted during `run()` was released
 * by that callback. `deliveredDuringRun > 0` therefore asserts, directly, that the
 * timer callback RAN — and the release-batch count asserts it ran REPEATEDLY
 * (a one-shot timer produces exactly one batch).
 *
 * ## The rate measurement, and why each window edge is where it is
 *
 * - **Numerator** — bytes counted inside the client {@see TcpConnection::send()}
 *   double: `strlen()` of the exact wire string handed to the socket, every call,
 *   nothing sampled. `frames × frameBytes === bytes` is asserted so the counter is
 *   provably complete.
 * - **LEFT edge `t0`** — the instant the bucket is emptied, which is also the
 *   bucket's own `updatedAt` origin. The bucket starts FULL by design (a fresh
 *   stream may burst one {@see TokenBucket::THROTTLE_BURST_SECONDS} window at line
 *   rate), and a window containing that burst measures the burst, not the cap. So
 *   the burst is spent BEFORE `t0` and every byte after `t0` was granted by refill
 *   at exactly the configured rate. The window therefore cannot start before the
 *   first token grant: at `t0` the balance is exactly zero.
 * - **RIGHT edge `tLast`** — the timestamp of the LAST byte released. At that
 *   instant the drain loop had stopped because the balance went non-positive (the
 *   backlog is asserted non-empty at the end, so it did not stop for want of
 *   frames). Ending on a token-exhaustion event means the window contains no
 *   post-drain idle tail, which would deflate the figure into "the throughput of
 *   nothing".
 *
 * `Tunnel::drainThrottled()` samples `microtime(true)` ONCE per firing and reuses
 * it for every frame in that batch, so the bucket's clock at the final release,
 * `nowLast`, is *earlier* than the wall-clock `tLast` by however long the batch
 * took to hand its frames to the socket. That gives an exact ONE-SIDED bound over
 * `[t0, tLast]` — with `R` the cap in bytes/sec and `W' = tLast − t0`:
 *
 *     delivered ≤ R·(nowLast − t0) + frameBytes ≤ R·W' + frameBytes
 *     ⇒ realised ≤ R + frameBytes/W'          (asserted: over-delivery is impossible)
 *
 * The matching floor is NOT exact on that window, and assuming it was cost one
 * false red here: at 1 Mbps the final batch's own mock-call latency (~2.4 ms of a
 * ~0.95 s window) inflated the denominator and produced 0.9971 × cap against a
 * `≥ cap` assertion — a measurement artefact, not under-delivery. So the pacing
 * band is measured on a second, artefact-free window instead:
 *
 * - **BATCH-TO-BATCH window** — from the first release of the first batch during
 *   `run()` to the first release of the LAST batch, counting only the whole batches
 *   in between. Both edges are instants at which the balance had just been found
 *   positive, and a batch begins with a balance in `(0, R·τ]` (one drain interval's
 *   worth of grant, since the previous batch left it non-positive). Therefore
 *
 *     delivered ∈ (R·W'' − R·τ, R·W'' + R·τ)  ⇒  realised ∈ R·(1 ± τ/W'')
 *
 *   with `τ` read from the production constant. That band is derived, symmetric,
 *   and immune to how long a batch takes to execute.
 *
 * The exact figures both windows produce are reported per run.
 *
 * ## Not a single run
 * The loop is timing-dependent, so the whole measurement is repeated
 * {@see ITERATIONS} times and EVERY run must land in the band; the spread across
 * runs is reported (set `PHLIX_THROTTLE_REPORT=1` to print it to STDERR) rather
 * than averaged away.
 *
 * @covers \Phlix\Hub\Relay\Tunnel
 * @covers \Phlix\Hub\Relay\TokenBucket
 * @covers \Phlix\Hub\Relay\ClientConnection
 */
final class TunnelThrottleTimerLoopTest extends TestCase
{
    /** Cap under test: 1 Mbps = 125 000 B/s (an S41 allow-list level). */
    private const int CAP_BPS = 1_000_000;

    /**
     * Small frames on purpose: the bucket releases frames while the balance is
     * positive, so a run overshoots by at most ONE frame. At 1 000 B that is
     * 0.8 % of a one-second window at 1 Mbps, which is what makes the derived band
     * tight enough to be a real claim.
     */
    private const int PAYLOAD_BYTES = 1_000;

    /** Wall-clock measurement window per iteration. */
    private const float WINDOW_SECONDS = 1.0;

    /** Independent repeats of the whole timed measurement. */
    private const int ITERATIONS = 5;

    /**
     * Frames queued before the loop starts. Deliberately below
     * `Tunnel::MAX_CLIENT_QUEUE` (256) so the run never trips the overflow
     * channel-close, and comfortably more than one window's worth of cap
     * (≈124 frames) so the backlog is still non-empty when the window closes —
     * the precondition for the right-edge argument above. Prefilling once, rather
     * than feeding from a second timer, means a scheduling stall cannot starve the
     * queue and fake an under-delivery.
     */
    private const int PREFILL_FRAMES = 245;

    private FrameDecoder $codec;
    private StructuredLogger $logger;
    private StructuredLogger $clientLogger;
    private RelaySessionManager $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameDecoder();
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->clientLogger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->sessionManager->method('registerServer')->willReturn('session-loop');
    }

    /**
     * The production drain-timer callback runs under a real event loop, keeps
     * running, and paces the stream to its configured cap.
     */
    public function test_drain_timer_callback_runs_under_a_real_loop_and_paces_to_the_configured_cap(): void
    {
        $tick = $this->drainIntervalSeconds();
        $capBytesPerSecond = self::CAP_BPS / 8.0;

        /**
         * @var list<array{
         *     realised: float, ratio: float, window: float, bytes: int, frames: int,
         *     batches: int, duringRun: int, loopRate: float, backlog: int,
         *     batchWindow: float, batchBytes: int, batchRealised: float, batchRatio: float
         * }> $runs
         */
        $runs = [];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $runs[] = $this->measureOneWindow($tick, $capBytesPerSecond);
        }

        $this->report($runs, $capBytesPerSecond, $tick);

        foreach ($runs as $index => $run) {
            $detail = sprintf(
                'run %d/%d: cap=%d bps (%.0f B/s) | delivered=%d B in %d frames | '
                . 't0-anchored window=%.4f s -> %.0f B/s (%.4f x cap) | '
                . 'batch-to-batch window=%.4f s over %d B -> %.0f B/s (%.4f x cap) | '
                . 'release batches=%d | bytes during run()=%d | backlog left=%d frames',
                $index + 1,
                self::ITERATIONS,
                self::CAP_BPS,
                $capBytesPerSecond,
                $run['bytes'],
                $run['frames'],
                $run['window'],
                $run['realised'],
                $run['ratio'],
                $run['batchWindow'],
                $run['batchBytes'],
                $run['batchRealised'],
                $run['batchRatio'],
                $run['batches'],
                $run['duringRun'],
                $run['backlog'],
            );

            // (1) The timer callback RAN. After the prefill nothing else touches
            // this channel, so a byte delivered during run() can only have come
            // from the closure Tunnel::armThrottleDrain() gave to Timer::add().
            $this->assertGreaterThan(
                0,
                $run['duringRun'],
                'the drain timer callback never delivered a byte — it did not run: ' . $detail,
            );

            // (2) It ran REPEATEDLY. ~20 ticks fit in a 1 s window at the 50 ms
            // production cadence; a one-shot timer yields exactly one batch.
            $this->assertGreaterThan(
                5,
                $run['batches'],
                'the drain released in too few batches to have been a repeating timer: ' . $detail,
            );

            // (3) The backlog was still non-empty at the end, so the right window
            // edge is a token-exhaustion event and not a drained queue.
            $this->assertGreaterThan(
                0,
                $run['backlog'],
                'the backlog emptied, so the measured window is not steady state: ' . $detail,
            );

            // (4) The byte counter is complete.
            $this->assertSame(
                $run['frames'] * $this->frameBytes(),
                $run['bytes'],
                'byte counter must be complete: frames x frameSize === bytes: ' . $detail,
            );

            // (5) EXACT CEILING on the t0-anchored window: the bucket cannot have
            // granted more than R x W', and the drain overshoots by at most the one
            // frame that took the balance non-positive. A drain that skips the
            // bucket consult, or treats bits as bytes, cannot fit under this.
            $ceiling = $capBytesPerSecond + ($this->frameBytes() / $run['window']);
            $this->assertLessThanOrEqual(
                $ceiling * 1.001,
                $run['realised'],
                'throttle OVER-delivered beyond cap + one frame: ' . $detail,
            );

            // (6) DERIVED SYMMETRIC BAND on the batch-to-batch window: each batch
            // starts with a balance in (0, R x tick], so the two residuals bound the
            // error at +/- R x tick over the window. This is the pacing assertion,
            // and it fails in BOTH directions (too fast and too slow).
            $this->assertGreaterThan(
                1,
                $run['batches'],
                'need at least two batches to measure batch-to-batch: ' . $detail,
            );
            $bandHalfWidth = ($capBytesPerSecond * $tick) / $run['batchWindow'];
            $this->assertGreaterThanOrEqual(
                $capBytesPerSecond - $bandHalfWidth,
                $run['batchRealised'],
                'throttle UNDER-delivered against its own cap: ' . $detail,
            );
            $this->assertLessThanOrEqual(
                $capBytesPerSecond + $bandHalfWidth,
                $run['batchRealised'],
                'throttle OVER-delivered against its own cap: ' . $detail,
            );

            // (7) The whole-loop window (which includes the idle tail after the
            // final tick) must also sit under the ceiling, and no lower than the
            // cap minus that tail — derived from the tick interval, not guessed.
            $this->assertLessThanOrEqual(
                $ceiling * 1.001,
                $run['loopRate'],
                'the full-loop rate exceeded cap + one frame: ' . $detail,
            );
            $this->assertGreaterThanOrEqual(
                $capBytesPerSecond * ((self::WINDOW_SECONDS - (3 * $tick)) / self::WINDOW_SECONDS),
                $run['loopRate'],
                'the full-loop rate fell more than three drain intervals below the cap: ' . $detail,
            );
        }

        // The spread across independent runs is bounded by the per-run band above;
        // assert it explicitly so a regression that only shows up as instability
        // is still a failure.
        $ratios = [];
        foreach ($runs as $run) {
            $ratios[] = $run['batchRatio'];
        }
        $spread = max($ratios) - min($ratios);
        $this->assertLessThan(
            0.05,
            $spread,
            sprintf(
                'batch-to-batch realised/cap ratio varied by %.4f across %d runs '
                . '(min %.4f, max %.4f) — the pacing is not stable',
                $spread,
                self::ITERATIONS,
                min($ratios),
                max($ratios),
            ),
        );
    }

    /**
     * Run ONE timed window against a real event loop and return its measurements.
     *
     * @param float $tick               Production drain interval (seconds).
     * @param float $capBytesPerSecond  Configured cap in bytes/sec.
     *
     * @return array{
     *     realised: float, ratio: float, window: float, bytes: int, frames: int,
     *     batches: int, duringRun: int, loopRate: float, backlog: int,
     *     batchWindow: float, batchBytes: int, batchRealised: float, batchRatio: float
     * }
     */
    private function measureOneWindow(float $tick, float $capBytesPerSecond): array
    {
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);
        // A per-user throttle must never pause the tunnel every user shares.
        $serverWs->expects($this->never())->method('pauseRecv');

        $tunnel = new Tunnel(
            'server-loop',
            $serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = 'session-loop';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $meter = new class {
            public int $bytes = 0;
            public int $frames = 0;
            /** @var list<float> */
            public array $sentAt = [];
        };

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturnCallback(
            static function (mixed $data) use ($meter): bool {
                $meter->bytes += strlen((string) $data);
                $meter->frames++;
                $meter->sentAt[] = microtime(true);
                return true;
            },
        );

        $client = new ClientConnection(
            $clientWs,
            'server-loop',
            'client-loop',
            $this->clientLogger,
            '',
            self::CAP_BPS,
        );

        // LEFT WINDOW EDGE: seed the bucket on an explicit clock base and spend the
        // whole burst capacity, so the balance is exactly zero at t0 and every byte
        // measured afterwards was granted by refill at the configured rate.
        $t0 = microtime(true);
        $bucket = TokenBucket::fromThrottleBps(self::CAP_BPS, $t0);
        $this->assertNotNull($bucket);
        $bucket->spend($bucket->capacity());
        $client->throttleBucket = $bucket;

        $tunnel->registerClient($client);

        $bytesBeforeRun = 0;
        $framesBeforeRun = 0;
        $backlog = 0;
        $loopEnd = $t0;

        $event = new Select();
        $eventProp = new ReflectionProperty(Timer::class, 'event');
        /** @var \Workerman\Events\EventInterface|null $previousEvent */
        $previousEvent = $eventProp->getValue();
        $eventProp->setValue(null, $event);

        try {
            // Prefill through the PRODUCTION ingress path. The bucket is empty, so
            // the frames queue instead of going out, and the production
            // armThrottleDrain() arms its repeating timer on the real driver.
            $payload = str_repeat('P', self::PAYLOAD_BYTES);
            for ($i = 0; $i < self::PREFILL_FRAMES; $i++) {
                $tunnel->sendToClient(
                    $client->channelId,
                    new RelayFrame(RelayFrameType::DATA, $client->channelId, $payload),
                );
            }

            $this->assertNotNull(
                $client->throttleDrainTimerId,
                'the production drain timer was not armed on the real event driver',
            );

            $bytesBeforeRun = $meter->bytes;
            $framesBeforeRun = $meter->frames;

            // Bound the loop: a one-shot stop timer ends run() after the window.
            $event->delay(self::WINDOW_SECONDS, static function () use ($event): void {
                $event->stop();
            });

            $event->run();
            $loopEnd = microtime(true);

            $backlog = count($this->pendingFor($tunnel, $client->channelId));
        } finally {
            $eventProp->setValue(null, $previousEvent);
        }

        // RIGHT WINDOW EDGE: the last release, i.e. the instant the balance went
        // non-positive with frames still queued.
        $tLast = $meter->sentAt === [] ? $t0 + self::WINDOW_SECONDS : $meter->sentAt[count($meter->sentAt) - 1];
        $window = max($tLast - $t0, 1.0e-6);
        $realised = $meter->bytes / $window;

        // Count release batches, and remember where each one STARTED: consecutive
        // sends separated by more than half a drain interval belong to different
        // timer firings. Every frame is the same size, so a send's index is also a
        // cumulative byte count — which is what makes the batch-to-batch numerator
        // exact (whole batches only, no partial batch at either edge).
        $batchStartIndex = [];
        $previous = null;
        foreach ($meter->sentAt as $index => $at) {
            if ($previous === null || ($at - $previous) > ($tick / 2)) {
                $batchStartIndex[] = $index;
            }
            $previous = $at;
        }
        $batches = count($batchStartIndex);

        // BATCH-TO-BATCH window: first batch that ran during run() -> last batch,
        // counting only the whole batches between those two starts.
        $firstDuringRun = null;
        foreach ($batchStartIndex as $index) {
            if ($index >= $framesBeforeRun) {
                $firstDuringRun = $index;
                break;
            }
        }
        $lastStart = $batchStartIndex === [] ? null : $batchStartIndex[$batches - 1];

        $batchWindow = 1.0e-6;
        $batchBytes = 0;
        if ($firstDuringRun !== null && $lastStart !== null && $lastStart > $firstDuringRun) {
            $batchWindow = max($meter->sentAt[$lastStart] - $meter->sentAt[$firstDuringRun], 1.0e-6);
            $batchBytes = ($lastStart - $firstDuringRun) * $this->frameBytes();
        }
        $batchRealised = $batchBytes / $batchWindow;

        return [
            'realised' => $realised,
            'ratio' => $realised / $capBytesPerSecond,
            'window' => $window,
            'bytes' => $meter->bytes,
            'frames' => $meter->frames,
            'batches' => $batches,
            'duringRun' => $meter->bytes - $bytesBeforeRun,
            'loopRate' => $meter->bytes / max($loopEnd - $t0, 1.0e-6),
            'backlog' => $backlog,
            'batchWindow' => $batchWindow,
            'batchBytes' => $batchBytes,
            'batchRealised' => $batchRealised,
            'batchRatio' => $batchRealised / $capBytesPerSecond,
        ];
    }

    /**
     * Print the per-run distribution when `PHLIX_THROTTLE_REPORT=1`.
     *
     * Off by default (CI logs stay quiet, and PHPUnit's strict-output mode is not
     * tripped because STDERR is not the buffered test output), but the acceptance
     * criterion is a measured rate, so the measurement must be reproducible by a
     * later auditor without editing the test.
     *
     * @param list<array{
     *     realised: float, ratio: float, window: float, bytes: int, frames: int,
     *     batches: int, duringRun: int, loopRate: float, backlog: int,
     *     batchWindow: float, batchBytes: int, batchRealised: float, batchRatio: float
     * }> $runs
     */
    private function report(array $runs, float $capBytesPerSecond, float $tick): void
    {
        if (getenv('PHLIX_THROTTLE_REPORT') !== '1') {
            return;
        }

        fwrite(STDERR, sprintf(
            "\n[S42 WS throttle, real event loop] cap=%d bps (%.0f B/s), frame=%d B, tick=%.3f s, window=%.2f s\n",
            self::CAP_BPS,
            $capBytesPerSecond,
            $this->frameBytes(),
            $tick,
            self::WINDOW_SECONDS,
        ));

        $ratios = [];
        foreach ($runs as $index => $run) {
            $ratios[] = $run['batchRatio'];
            fwrite(STDERR, sprintf(
                "  run %d: %d B in %d frames | t0-anchored %.4f s = %.0f B/s (%.4f Mbps, ratio %.4f) | "
                . "batches %d | during run() %d B | loop-window %.0f B/s | backlog %d\n",
                $index + 1,
                $run['bytes'],
                $run['frames'],
                $run['window'],
                $run['realised'],
                ($run['realised'] * 8) / 1_000_000,
                $run['ratio'],
                $run['batches'],
                $run['duringRun'],
                $run['loopRate'],
                $run['backlog'],
            ));
            fwrite(STDERR, sprintf(
                "         batch-to-batch %.4f s over %d B = %.0f B/s (%.4f Mbps, ratio %.4f) "
                . "| band +/- %.0f B/s\n",
                $run['batchWindow'],
                $run['batchBytes'],
                $run['batchRealised'],
                ($run['batchRealised'] * 8) / 1_000_000,
                $run['batchRatio'],
                ($capBytesPerSecond * $tick) / $run['batchWindow'],
            ));
        }

        $mean = 0.0;
        foreach ($ratios as $ratio) {
            $mean += $ratio;
        }
        $mean /= max(count($ratios), 1);

        fwrite(STDERR, sprintf(
            "  batch-to-batch ratio min=%.4f max=%.4f mean=%.4f spread=%.4f (n=%d)\n",
            min($ratios),
            max($ratios),
            $mean,
            max($ratios) - min($ratios),
            count($ratios),
        ));
        fwrite(STDERR, sprintf(
            "  derived band per run: [%.0f, %.0f] B/s (floor = cap, ceiling = cap + one frame/window)\n",
            $capBytesPerSecond,
            $capBytesPerSecond + ($this->frameBytes() / self::WINDOW_SECONDS),
        ));
    }

    /** Wire length of one DATA frame carrying {@see PAYLOAD_BYTES}. */
    private function frameBytes(): int
    {
        return strlen($this->codec->encode(RelayFrameType::DATA, 1, str_repeat('P', self::PAYLOAD_BYTES)));
    }

    /** @return list<string> */
    private function pendingFor(Tunnel $tunnel, int $channelId): array
    {
        $prop = new ReflectionProperty($tunnel, 'pendingClientFrames');
        /** @var array<int, list<string>> $value */
        $value = $prop->getValue($tunnel);

        return $value[$channelId] ?? [];
    }

    private function drainIntervalSeconds(): float
    {
        /** @var float $value */
        $value = (new ReflectionClassConstant(Tunnel::class, 'THROTTLE_DRAIN_INTERVAL_SECONDS'))->getValue();

        return $value;
    }
}
