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
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;

use function array_fill;
use function ceil;
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
 * S42: the realised rate matches the configured cap across the WHOLE shape of the
 * feature, not at one convenient operating point.
 *
 * ## Why a sweep rather than more repetitions of one case
 * A single measured cap can pass for the wrong reason — a hard-coded rate, a
 * bits/bytes slip that happens to cancel at one value, a bound that only holds for
 * one frame size. So this varies the two dimensions that actually change the
 * arithmetic: every cap the admin UI can set (the S41 allow-list, 1–50 Mbps) and
 * three frame sizes spanning the relay's range (1 KB … 65 KB, just inside the
 * 65 535-byte DATA payload limit). 18 combinations, each an independent
 * measurement with its own derived band.
 *
 * 🔴 The FRAME-SIZE dimension is load-bearing, measured on master 2026-08-03.
 * {@see TunnelThrottleLoadTest} measures 3 Mbps and 10 Mbps with 65 000-byte
 * frames, where the cap permits FEWER THAN ONE frame per 50 ms drain interval
 * (3 Mbps ÷ 65 007 B ≈ 5.8 frames/s ≈ 0.29 per tick). Any per-call release limit is
 * therefore invisible to it. Two such mutations of `Tunnel::drainThrottled()` left
 * master's whole suite green — 2532 tests, 19894 assertions, exit 0:
 *   - releasing at most ONE frame per drain call (a stray `break`), and
 *   - capping the batch at EIGHT frames (a plausible "don't hog the event loop"
 *     guard).
 * Both throttle a 50 Mbps stream to a fraction of its cap in production. The
 * one-frame case is caught here and by {@see TunnelThrottleTimerLoopTest}; the
 * eight-frame case is caught ONLY here, because only this file exercises an
 * operating point (50 Mbps with 1 KB frames ⇒ ~310 frames per tick) where a batch
 * cap binds.
 *
 * ## Determinism
 * Time is injected, never slept: the bucket is seeded on a clock anchored ~11.6
 * days in the future so the real-time refills performed by the production ingress
 * path ({@see Tunnel::sendToClient()} → `sendToClientThrottled()` →
 * `drainThrottled(null)`) are inert — {@see TokenBucket} refills only when the
 * clock advances past `updatedAt` — and budget is granted ONLY by this test's
 * simulated clock. Production code still runs verbatim; it simply cannot
 * manufacture budget. Nothing here is sampled, so there is no run-to-run spread to
 * average away: the same input produces the same figure every time.
 *
 * ## The band is DERIVED, not a tolerance guess
 * The bucket is emptied at `t0` (its own `updatedAt` origin), so it holds no burst
 * credit and every byte released afterwards was granted by refill at the cap `R`
 * bytes/sec. The drain releases frames while the balance is positive and stops when
 * it goes non-positive, so with a non-empty backlog (asserted) the balance after
 * the final release at `t0 + W` lies in `(−frameBytes, 0]`:
 *
 *     delivered ∈ [R·W, R·W + frameBytes)   ⇒   realised ∈ [R, R + frameBytes/W)
 *
 * Each case asserts BOTH edges. The window `W` is scaled per case so at least ~40
 * frames flow, keeping the `frameBytes/W` term small even for 65 KB frames at
 * 1 Mbps (the worst quantisation in the matrix).
 *
 * ## Window edges
 * LEFT `t0`: the moment the balance is exactly zero — the window cannot start
 * before the first token grant and contains no burst. RIGHT `t0 + W`: a drain
 * instant at which frames were still queued, so the window ends with the sink
 * saturated rather than mid-drain-tail. The burst that a freshly mounted stream
 * legitimately gets is measured separately, in
 * {@see testAFullBucketReleasesExactlyOneBurstWindowThenPacesToTheCap()}.
 */
final class TunnelThrottleRateSweepTest extends TestCase
{
    // Workerman's Timer statics and Worker registry are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use WorkermanTimerRuntimeControl;

    /**
     * Seconds added to `microtime(true)` when seeding a bucket so the production
     * ingress path's real-time refills are inert. ~11.6 days.
     */
    private const float CLOCK_EPOCH_OFFSET = 1_000_000.0;

    /** Frames a window must carry for its rate figure to be meaningful. */
    private const int MIN_FRAMES_PER_WINDOW = 40;

    /**
     * Upper bound on frames offered per simulated slot. The queue is bounded by
     * `Tunnel::MAX_CLIENT_QUEUE` (256) and overflow closes the channel, so a slot
     * must never offer more than the queue can hold; the slot count is raised until
     * this holds. Production reaches the same effect for free because its ingress
     * calls drain on real time between frames.
     */
    private const int MAX_FRAMES_PER_SLOT = 60;

    private FrameDecoder $codec;
    private StructuredLogger&MockObject $logger;
    private StructuredLogger&MockObject $clientLogger;
    private RelaySessionManager&MockObject $sessionManager;
    private TcpConnection&MockObject $serverWs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameDecoder();
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->clientLogger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->sessionManager->method('registerServer')->willReturn('session-sweep');
        $this->serverWs = $this->createMock(TcpConnection::class);
        $this->serverWs->method('send')->willReturn(true);
    }

    /**
     * Every admin-settable cap, at three frame sizes, realises its OWN rate.
     *
     * @return iterable<string, array{int, int}>
     */
    public static function capAndFrameSizeProvider(): iterable
    {
        // The S41 allow-list, minus 0 (Unlimited, which has no rate to measure and
        // is covered by the fast-path tests).
        foreach ([1, 3, 5, 10, 20, 50] as $mbps) {
            foreach ([1_000, 16_000, 65_000] as $payload) {
                yield sprintf('%d Mbps, %d B payload', $mbps, $payload) => [$mbps * 1_000_000, $payload];
            }
        }
    }

    #[DataProvider('capAndFrameSizeProvider')]
    public function testRealisedRateMatchesTheConfiguredCap(int $capBps, int $payloadBytes): void
    {
        // A per-user throttle is strictly per-channel: the tunnel that multiplexes
        // every other user on this server must never be paused or closed for it.
        $this->serverWs->expects($this->never())->method('pauseRecv');
        $this->serverWs->expects($this->never())->method('close');

        $capBytes = $capBps / 8.0;
        $frameBytes = $this->frameBytes($payloadBytes);
        $capFramesPerSecond = $capBytes / $frameBytes;

        // Window long enough to carry MIN_FRAMES_PER_WINDOW frames, so the
        // one-frame quantisation term stays small relative to the cap.
        $window = max(1.0, self::MIN_FRAMES_PER_WINDOW / $capFramesPerSecond);

        // Offer strictly above the cap so the backlog never empties, but by a
        // bounded excess so the 256-frame queue is not overflowed inside the
        // window (overflow is a separate, already-pinned behaviour).
        $excessFramesPerSecond = min(max($capFramesPerSecond * 0.5, 5.0), 100.0);
        $offerFramesPerSecond = $capFramesPerSecond + $excessFramesPerSecond;

        $tick = $this->drainIntervalSeconds();
        $slots = (int) max(
            ceil($window / $tick),
            ceil(($offerFramesPerSecond * $window) / self::MAX_FRAMES_PER_SLOT),
        );
        $slotLength = $window / $slots;

        $tunnel = $this->activeTunnel();

        $meter = new class {
            public int $bytes = 0;
            public int $frames = 0;
            public bool $closed = false;
        };

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturnCallback(
            static function (mixed $data) use ($meter): bool {
                $meter->bytes += strlen((string) $data);
                $meter->frames++;
                return true;
            },
        );
        $clientWs->method('close')->willReturnCallback(static function () use ($meter): void {
            $meter->closed = true;
        });

        $client = new ClientConnection($clientWs, 'server-sweep', 'client-sweep', $this->clientLogger, '', $capBps);

        // LEFT WINDOW EDGE: empty the bucket at t0 so it carries no burst credit
        // and t0 is the exact origin of every later token grant.
        $t0 = microtime(true) + self::CLOCK_EPOCH_OFFSET;
        $bucket = TokenBucket::fromThrottleBps($capBps, $t0);
        $this->assertNotNull($bucket);
        $bucket->spend($bucket->capacity());
        $client->throttleBucket = $bucket;

        $tunnel->registerClient($client);

        $drain = new ReflectionMethod($tunnel, 'drainThrottled');
        $payload = str_repeat('P', $payloadBytes);
        $offeredFrames = 0;
        $carry = 0.0;
        $depthHighWater = 0;

        for ($slot = 1; $slot <= $slots; $slot++) {
            $carry += $offerFramesPerSecond * $slotLength;
            while ($carry >= 1.0) {
                $carry -= 1.0;
                $offeredFrames++;
                // PRODUCTION ingress. Real-clock refills inside it are inert, so
                // nothing is delivered here except via the simulated drains below.
                $tunnel->sendToClient(
                    $client->channelId,
                    new RelayFrame(RelayFrameType::DATA, $client->channelId, $payload),
                );
                $depthHighWater = max($depthHighWater, count($this->pendingFor($tunnel, $client->channelId)));
            }

            // RIGHT WINDOW EDGE on the final slot: a drain instant at t0 + W.
            $drain->invoke($tunnel, $client, $t0 + ($slot * $slotLength));
        }

        $backlog = count($this->pendingFor($tunnel, $client->channelId));
        $realised = $meter->bytes / $window;
        $offeredBytes = $offeredFrames * $frameBytes;

        $detail = sprintf(
            'cap=%d bps (%.1f B/s) | frame=%d B | window=%.4f s in %d slots | '
            . 'offered=%d B in %d frames | delivered=%d B in %d frames | realised=%.1f B/s (%.4f x cap) | '
            . 'backlog left=%d frames (high-water %d)',
            $capBps,
            $capBytes,
            $frameBytes,
            $window,
            $slots,
            $offeredBytes,
            $offeredFrames,
            $meter->bytes,
            $meter->frames,
            $realised,
            $realised / $capBytes,
            $backlog,
            $depthHighWater,
        );

        // The acceptance criterion is a measured rate, so make the measurement
        // reproducible without editing the test: PHLIX_THROTTLE_REPORT=1 prints
        // every case's figures. STDERR is not PHPUnit's buffered test output, so
        // this does not trip strict-output mode; it is silent by default.
        if (getenv('PHLIX_THROTTLE_REPORT') === '1') {
            fwrite(STDERR, "\n[S42 sweep] " . $detail);
        }

        // Preconditions for the derived band -----------------------------------
        $this->assertFalse($meter->closed, 'the channel was closed mid-measurement: ' . $detail);
        $this->assertGreaterThan(
            0,
            $backlog,
            'the backlog emptied, so the window is not steady state: ' . $detail,
        );
        $this->assertGreaterThan(
            $meter->bytes,
            $offeredBytes,
            'the offer did not exceed what was delivered — nothing was throttled: ' . $detail,
        );
        $this->assertSame(
            $meter->frames * $frameBytes,
            $meter->bytes,
            'byte counter must be complete: frames x frameSize === bytes: ' . $detail,
        );
        $this->assertGreaterThanOrEqual(
            self::MIN_FRAMES_PER_WINDOW - 1,
            $meter->frames,
            'too few frames in the window for the rate figure to mean anything: ' . $detail,
        );
        $this->assertLessThanOrEqual(
            $this->maxClientQueue(),
            $depthHighWater,
            'the bounded re-queue was exceeded: ' . $detail,
        );

        // The derived band ------------------------------------------------------
        $this->assertGreaterThanOrEqual(
            $capBytes * 0.999,
            $realised,
            'throttle UNDER-delivered against its own cap: ' . $detail,
        );
        $this->assertLessThanOrEqual(
            ($capBytes + ($frameBytes / $window)) * 1.001,
            $realised,
            'throttle OVER-delivered beyond cap + one frame per window: ' . $detail,
        );
    }

    /**
     * The burst is DELIBERATE, and this pins it so nobody "fixes" the bucket after
     * measuring a first-second rate above the cap.
     *
     * A freshly mounted stream starts with a full bucket
     * ({@see ClientConnection::THROTTLE_BURST_SECONDS} = 1 s of data), which is
     * released immediately at line rate; only afterwards does the stream settle to
     * the sustained cap. So a measurement window that INCLUDES the burst legitimately
     * reads `cap + capacity/window`, which is why the sweep above spends the burst
     * before its window opens.
     */
    public function testAFullBucketReleasesExactlyOneBurstWindowThenPacesToTheCap(): void
    {
        $capBps = 3_000_000; // the S41 product default
        $capBytes = $capBps / 8.0;
        $payloadBytes = 16_000;
        $frameBytes = $this->frameBytes($payloadBytes);

        $tunnel = $this->activeTunnel();

        $meter = new class {
            public int $bytes = 0;
            public int $frames = 0;
        };

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturnCallback(
            static function (mixed $data) use ($meter): bool {
                $meter->bytes += strlen((string) $data);
                $meter->frames++;
                return true;
            },
        );

        $client = new ClientConnection($clientWs, 'server-sweep', 'client-burst', $this->clientLogger, '', $capBps);
        $t0 = microtime(true) + self::CLOCK_EPOCH_OFFSET;
        // FULL bucket — exactly what ClientConnection builds at mount.
        $client->throttleBucket = TokenBucket::fromThrottleBps($capBps, $t0);
        $this->assertNotNull($client->throttleBucket);
        $this->assertSame($capBytes, $client->throttleBucket->capacity());

        $tunnel->registerClient($client);

        // A backlog deeper than the burst, well inside the 256-frame bound.
        $payload = str_repeat('P', $payloadBytes);
        $this->writePendingClientFrames($tunnel, [
            $client->channelId => array_fill(
                0,
                100,
                $this->codec->encode(RelayFrameType::DATA, $client->channelId, $payload),
            ),
        ]);

        $drain = new ReflectionMethod($tunnel, 'drainThrottled');

        // t0: no time has passed, so ONLY the burst capacity can go out.
        $drain->invoke($tunnel, $client, $t0);
        $burstBytes = $meter->bytes;

        $this->assertGreaterThanOrEqual(
            $capBytes,
            (float) $burstBytes,
            sprintf(
                'a full bucket must release its whole burst window (%.0f B), released %d B',
                $capBytes,
                $burstBytes,
            ),
        );
        $this->assertLessThan(
            $capBytes + $frameBytes,
            (float) $burstBytes,
            sprintf('the burst must not exceed capacity + one frame; released %d B', $burstBytes),
        );

        // t0 + 1 s: the burst is spent, so this second second is paced at the cap.
        $drain->invoke($tunnel, $client, $t0 + 1.0);
        $secondSecond = $meter->bytes - $burstBytes;

        $this->assertGreaterThanOrEqual(
            $capBytes - $frameBytes,
            (float) $secondSecond,
            sprintf('the post-burst second under-delivered: %d B vs cap %.0f B', $secondSecond, $capBytes),
        );
        $this->assertLessThan(
            $capBytes + $frameBytes,
            (float) $secondSecond,
            sprintf('the post-burst second over-delivered: %d B vs cap %.0f B', $secondSecond, $capBytes),
        );
    }

    // -----------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------

    private function activeTunnel(): Tunnel
    {
        $tunnel = new Tunnel(
            'server-sweep',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = 'session-sweep';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        return $tunnel;
    }

    /** Wire length of one DATA frame carrying $payloadBytes. */
    private function frameBytes(int $payloadBytes): int
    {
        return strlen($this->codec->encode(RelayFrameType::DATA, 1, str_repeat('P', $payloadBytes)));
    }

    /** @return list<string> */
    private function pendingFor(Tunnel $tunnel, int $channelId): array
    {
        $prop = new ReflectionProperty($tunnel, 'pendingClientFrames');
        /** @var array<int, list<string>> $value */
        $value = $prop->getValue($tunnel);

        return $value[$channelId] ?? [];
    }

    /** @param array<int, list<string>> $queues */
    private function writePendingClientFrames(Tunnel $tunnel, array $queues): void
    {
        $prop = new ReflectionProperty($tunnel, 'pendingClientFrames');
        $prop->setValue($tunnel, $queues);
    }

    private function drainIntervalSeconds(): float
    {
        /** @var float $value */
        $value = (new ReflectionClassConstant(Tunnel::class, 'THROTTLE_DRAIN_INTERVAL_SECONDS'))->getValue();

        return $value;
    }

    private function maxClientQueue(): int
    {
        /** @var int $value */
        $value = (new ReflectionClassConstant(Tunnel::class, 'MAX_CLIENT_QUEUE'))->getValue();

        return $value;
    }
}
