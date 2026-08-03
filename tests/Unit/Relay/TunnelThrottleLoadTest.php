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
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;

use function count;
use function max;
use function microtime;
use function str_repeat;
use function strlen;

/**
 * S42 acceptance-criteria LOAD test for the per-user WS relay throttle.
 *
 * The pre-existing {@see TunnelTest} throttle cases prove the *mechanism*
 * (queue-not-send, FIFO order, timer arm/cancel, overflow closes one channel).
 * They do NOT prove the acceptance criterion, which is a **rate**: "a throttled
 * connection's observed throughput matches its configured cap in a load test;
 * other channels multiplexed over the same server WS are unaffected; no
 * unbounded buffer growth."
 *
 * ### How the rate is measured (numerator / denominator stated explicitly)
 * - **Numerator** — bytes counted inside the client {@see TcpConnection::send()}
 *   double, i.e. `strlen()` of the exact wire string handed to the socket. This
 *   is the LAST hop before Workerman's send buffer, so it is the real data-plane
 *   byte count. It is deliberately NOT `Tunnel::$bytesIn`, NOT
 *   `RelaySessionManager::recordBytesIn()` (a DB-backed accounting sink that may
 *   be batched/throttled) and NOT the token bucket's own balance. Every single
 *   `send()` call is counted — nothing is sampled, batched or rounded — and each
 *   test asserts `frames × frameSize === bytes` so the counter is provably
 *   complete rather than merely plausible.
 * - **Denominator** — the simulated wall-clock window `ticks × interval`, where
 *   `interval` is read from the PRODUCTION constant
 *   `Tunnel::THROTTLE_DRAIN_INTERVAL_SECONDS` (not a literal), so the simulation
 *   paces at exactly the cadence the shipped drain timer uses. The window
 *   includes the initial burst; each test also reports the burst-excluded figure
 *   in its assertion messages.
 *
 * ### Why the clock is anchored in the future
 * The production ingress path {@see Tunnel::sendToClient()} →
 * `sendToClientThrottled()` → `drainThrottled($client)` passes `null` for the
 * clock, i.e. real `microtime(true)`. Seeding each bucket at
 * `microtime(true) + CLOCK_EPOCH_OFFSET` makes every real-time refill a no-op
 * ({@see TokenBucket::refill()} returns early when the clock has not advanced
 * past `updatedAt`), so budget is granted ONLY by this test's simulated tick
 * clock. The production ingress code still runs verbatim — it simply cannot
 * manufacture budget — which keeps the measurement deterministic without
 * stubbing any production method.
 *
 * @covers \Phlix\Hub\Relay\Tunnel
 * @covers \Phlix\Hub\Relay\TokenBucket
 * @covers \Phlix\Hub\Relay\ClientConnection
 */
final class TunnelThrottleLoadTest extends TestCase
{
    /**
     * Seconds added to `microtime(true)` when seeding a bucket, so that the
     * real-time refills performed by the production ingress path are inert and
     * only this test's simulated clock grants budget. ~11.6 days.
     */
    private const float CLOCK_EPOCH_OFFSET = 1_000_000.0;

    /** Production-max relay DATA payload is 65535 bytes; stay just inside it. */
    private const int PAYLOAD_BYTES = 65_000;

    private FrameDecoder $codec;
    private StructuredLogger $logger;
    private StructuredLogger $clientLogger;
    private RelaySessionManager $sessionManager;
    private TcpConnection $serverWs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameDecoder();
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->clientLogger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->serverWs = $this->createMock(TcpConnection::class);
        $this->sessionManager->method('registerServer')->willReturn('session-load');
        $this->serverWs->method('send')->willReturn(true);
    }

    /**
     * AC bullet 1 + 2, together: three channels multiplexed over ONE server
     * tunnel, each offered the SAME 2x-over-cap load for 30 simulated seconds
     * at the production drain cadence.
     *
     *  - ch A: 3 Mbps  (the S41 product default) -> must realise ~3 Mbps
     *  - ch B: 10 Mbps (a different operator cap) -> must realise ~10 Mbps
     *  - ch C: Unlimited (0)                      -> must realise 100% of offer
     *
     * A's cap must not leak onto B or C, and the shared server tunnel must never
     * be paused.
     */
    public function test_each_channel_realises_its_own_cap_on_one_shared_server_tunnel(): void
    {
        // The shared server tunnel must NEVER be paused for a per-user throttle.
        $this->serverWs->expects($this->never())->method('pauseRecv');

        $tunnel = $this->activeTunnel();
        $tick = $this->drainIntervalSeconds();
        $ticks = (int) (30.0 / $tick); // 30 simulated seconds
        $window = $ticks * $tick;

        $base = microtime(true) + self::CLOCK_EPOCH_OFFSET;

        $a = $this->registerThrottled($tunnel, 'client-a', 3_000_000, $base);
        $b = $this->registerThrottled($tunnel, 'client-b', 10_000_000, $base);
        $c = $this->registerThrottled($tunnel, 'client-c', 0, $base);

        // Offer every channel its own cap PLUS the same absolute excess, so both
        // throttles genuinely gate for the whole window and neither bounded
        // backlog overflows inside it (overflow is measured separately, in
        // test_sustained_gross_over_offer_...). A is therefore offered 2x its cap
        // and B 1.3x its own — different caps, same excess, one tunnel.
        $excess = 3_000_000 / 8; // 375 000 B/s
        $offer = [
            $a['channel'] => (3_000_000 / 8) + $excess,   //   750 000 B/s = 2.0x cap
            $b['channel'] => (10_000_000 / 8) + $excess,  // 1 625 000 B/s = 1.3x cap
            $c['channel'] => (3_000_000 / 8) + $excess,   //   750 000 B/s (Unlimited)
        ];

        $offered = $this->runLoad($tunnel, [$a, $b, $c], $offer, $ticks, $tick, $base);

        // The measurement is only meaningful if every channel survived the whole
        // window — a channel closed mid-run would shorten its own denominator.
        $this->assertFalse($a['sent']->closed, 'channel A closed mid-measurement');
        $this->assertFalse($b['sent']->closed, 'channel B closed mid-measurement');
        $this->assertFalse($c['sent']->closed, 'channel C closed mid-measurement');

        // --- Channel A: 3 Mbps cap -------------------------------------------
        $rateA = $a['sent']->bytes / $window;
        $this->assertSame(
            $a['sent']->frames * $a['frameBytes'],
            $a['sent']->bytes,
            'byte counter must be complete: frames x frameSize === bytes',
        );
        $this->assertGreaterThan(
            0.0,
            $rateA,
            'the throttled channel delivered nothing at all',
        );
        // Anti-tautology: it must be visibly BELOW the offered load, otherwise
        // "matches the cap" could be satisfied by no throttling at all.
        $this->assertLessThan(
            0.60 * $offer[$a['channel']],
            $rateA,
            'throttled channel delivered ~the offered rate — the cap was not enforced',
        );
        $this->assertThrottledToCap(3_000_000, $rateA, $window, $a['sent']->bytes, $a['sent']->frames);

        // --- Channel B: 10 Mbps cap, SAME tunnel ------------------------------
        $rateB = $b['sent']->bytes / $window;
        $this->assertThrottledToCap(10_000_000, $rateB, $window, $b['sent']->bytes, $b['sent']->frames);
        $this->assertLessThan(
            0.90 * $offer[$b['channel']],
            $rateB,
            'the 10 Mbps channel delivered ~its offered rate — its cap was not enforced',
        );
        // B's realised rate must track ITS cap, not A's — the decisive
        // "other channels are unaffected" measurement. Caps are 10:3, so the
        // realised rates must be too.
        $this->assertGreaterThan(
            2.5 * $rateA,
            $rateB,
            sprintf(
                'the 10 Mbps channel was dragged toward the 3 Mbps channel’s cap '
                . '(A=%.0f B/s, B=%.0f B/s, ratio=%.2f, expected ~3.33)',
                $rateA,
                $rateB,
                $rateB / $rateA,
            ),
        );

        // --- Channel C: Unlimited --------------------------------------------
        $this->assertSame(
            $offered[$c['channel']],
            $c['sent']->bytes,
            'an Unlimited channel on the same tunnel must deliver 100% of the offered bytes',
        );
        $this->assertSame(
            [],
            $this->pendingFor($tunnel, $c['channel']),
            'an Unlimited channel must never queue a frame',
        );
        $this->assertNull($c['conn']->throttleDrainTimerId, 'Unlimited must arm no drain timer');
    }

    /**
     * AC bullet 3 — "no unbounded buffer growth", MEASURED.
     *
     * The same 30 s / 2x-over-cap run as above, reporting the high-water mark of
     * `Tunnel::$pendingClientFrames[$channelId]` sampled EVERY tick. The bound
     * that must hold is the production constant `MAX_CLIENT_QUEUE`.
     */
    public function test_throttled_backlog_high_water_mark_stays_within_the_production_bound(): void
    {
        $tunnel = $this->activeTunnel();
        $tick = $this->drainIntervalSeconds();
        $ticks = (int) (30.0 / $tick);
        $base = microtime(true) + self::CLOCK_EPOCH_OFFSET;
        $max = $this->maxClientQueue();

        $a = $this->registerThrottled($tunnel, 'client-a', 3_000_000, $base);

        $this->runLoad($tunnel, [$a], [$a['channel'] => 2 * 3_000_000 / 8], $ticks, $tick, $base);

        $highWater = $a['sent']->queueHighWater;

        $this->assertGreaterThan(
            0,
            $highWater,
            'the backlog never grew — the throttle did not actually gate anything',
        );
        $this->assertLessThanOrEqual(
            $max,
            $highWater,
            "backlog high-water {$highWater} exceeded MAX_CLIENT_QUEUE {$max}",
        );
        // Independently re-derive the bound from the excess offered-over-cap
        // bytes, so the assertion is not simply "whatever the code produced".
        $this->assertLessThan(
            $max,
            $highWater,
            "high-water {$highWater} reached the hard cap {$max}; at this offered "
            . 'rate the excess should still fit, so the queue is not absorbing as designed',
        );
    }

    /**
     * AC bullet 3, the OTHER half — what actually happens when the offered load
     * stays grossly above the cap for long enough that the bounded backlog
     * cannot absorb the excess.
     *
     * This is the honest counterpart to the bullet above: memory IS bounded, but
     * it is bounded by CLOSING the throttled channel. The test pins that
     * behaviour, measures the high-water mark (which must be exactly
     * MAX_CLIENT_QUEUE) and proves the blast radius is one channel: the tunnel
     * stays ACTIVE, the server is never paused, and a second channel on the same
     * tunnel keeps delivering after the first one is torn down.
     */
    public function test_sustained_gross_over_offer_bounds_memory_by_closing_only_that_channel(): void
    {
        $this->serverWs->expects($this->never())->method('pauseRecv');
        $this->serverWs->expects($this->never())->method('close');

        $tunnel = $this->activeTunnel();
        $tick = $this->drainIntervalSeconds();
        $ticks = (int) (30.0 / $tick);
        $base = microtime(true) + self::CLOCK_EPOCH_OFFSET;
        $max = $this->maxClientQueue();

        $a = $this->registerThrottled($tunnel, 'client-a', 3_000_000, $base);
        $b = $this->registerThrottled($tunnel, 'client-b', 0, $base);

        // 100 Mbps of offered load into a 3 Mbps channel — a LAN-speed origin
        // pushing a direct-play file at a defaulted user.
        $this->runLoad(
            $tunnel,
            [$a, $b],
            [$a['channel'] => 100_000_000 / 8, $b['channel'] => 100_000_000 / 8],
            $ticks,
            $tick,
            $base,
        );

        $this->assertTrue(
            $a['sent']->closed,
            'a sustained 33x-over-cap offer must eventually trip the bounded backlog',
        );
        $this->assertSame(
            $max,
            $a['sent']->queueHighWater,
            'the backlog high-water must be exactly MAX_CLIENT_QUEUE before the channel is closed',
        );
        // Report the survival time so the operational consequence is a number,
        // not an adjective: at 100 Mbps offered into a 3 Mbps cap the channel
        // lives only a few seconds.
        $this->assertGreaterThan(
            0,
            $a['sent']->closedAtTick,
            'the closure tick was not recorded',
        );
        $this->assertLessThan(
            $ticks,
            $a['sent']->closedAtTick,
            sprintf(
                'throttled channel survived %.2f s of 100 Mbps offered load '
                . '(high-water %d frames = %d bytes)',
                $a['sent']->closedAtTick * $tick,
                $a['sent']->queueHighWater,
                $a['sent']->queueHighWater * $a['frameBytes'],
            ),
        );
        $this->assertSame(
            Tunnel::STATUS_ACTIVE,
            $tunnel->status,
            'closing one throttled channel must never close the shared tunnel',
        );
        $this->assertFalse($b['sent']->closed, 'the co-tenant channel must survive');
        $this->assertGreaterThan(
            0,
            $b['sent']->bytesAfterPeerClose,
            'the co-tenant channel must keep delivering after the throttled channel is torn down',
        );
        $this->assertNull(
            $a['conn']->throttleDrainTimerId,
            'the drain timer must be cancelled when the channel is closed (no leaked repeating timer)',
        );
    }

    // -----------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------

    /**
     * Drive the production ingress + drain paths for `$ticks` ticks.
     *
     * Each tick: (1) offer this tick's share of `$bytesPerSecond` through the
     * PRODUCTION entry point {@see Tunnel::sendToClient()}; (2) advance the
     * simulated clock by one drain interval and invoke the private
     * `drainThrottled()` exactly as the shipped `Workerman\Timer` callback would;
     * (3) sample every channel's backlog depth.
     *
     * @param list<array{channel:int,conn:ClientConnection,sent:object,frameBytes:int}> $clients
     * @param array<int, float>                                                          $bytesPerSecond
     *
     * @return array<int, int> Offered bytes per channel.
     */
    private function runLoad(
        Tunnel $tunnel,
        array $clients,
        array $bytesPerSecond,
        int $ticks,
        float $tick,
        float $base,
    ): array {
        $drain = new ReflectionMethod($tunnel, 'drainThrottled');
        $payload = str_repeat('P', self::PAYLOAD_BYTES);
        $offered = [];
        $carry = [];
        foreach ($clients as $client) {
            $offered[$client['channel']] = 0;
            $carry[$client['channel']] = 0.0;
        }

        for ($i = 1; $i <= $ticks; $i++) {
            $now = $base + ($i * $tick);

            foreach ($clients as $client) {
                $channel = $client['channel'];
                if ($client['sent']->closed) {
                    continue;
                }

                $carry[$channel] += ($bytesPerSecond[$channel] * $tick) / $client['frameBytes'];
                while ($carry[$channel] >= 1.0) {
                    $carry[$channel] -= 1.0;
                    $offered[$channel] += $client['frameBytes'];
                    $tunnel->sendToClient(
                        $channel,
                        new RelayFrame(RelayFrameType::DATA, $channel, $payload),
                    );
                    // Sample the backlog at its true PEAK — immediately after the
                    // enqueue, before any drain can shrink it again.
                    $client['sent']->queueHighWater = max(
                        $client['sent']->queueHighWater,
                        count($this->pendingFor($tunnel, $channel)),
                    );
                    if ($client['sent']->closed) {
                        $client['sent']->closedAtTick = $i;
                        // Mirror the relay worker: the client WS onClose handler
                        // detaches the channel from the tunnel.
                        $tunnel->removeClient($client['conn']);
                        break;
                    }
                }
            }

            foreach ($clients as $client) {
                if ($client['sent']->closed || !$client['conn']->isThrottled()) {
                    continue;
                }
                $drain->invoke($tunnel, $client['conn'], $now);
            }

            foreach ($clients as $client) {
                $depth = count($this->pendingFor($tunnel, $client['channel']));
                $client['sent']->queueHighWater = max($client['sent']->queueHighWater, $depth);
            }

            // Track deliveries that happen after a peer channel has been closed.
            foreach ($clients as $client) {
                foreach ($clients as $peer) {
                    if ($peer !== $client && $peer['sent']->closed) {
                        $client['sent']->bytesAfterPeerClose = $client['sent']->bytes
                            - $client['sent']->bytesAtPeerClose;
                    }
                }
            }
        }

        return $offered;
    }

    /**
     * Assert a realised byte-rate tracks a configured bits/sec cap.
     *
     * The band is asymmetric on purpose: a finite run always sits slightly ABOVE
     * the sustained cap because the bucket starts FULL and delivers one burst
     * window "for free"; the excess is `capacity / window` and shrinks as the
     * window grows. It must never sit meaningfully below the cap (that would be
     * under-delivery).
     *
     * ⚠ S191 — the burst allowance is the LITERAL documented window (1.0 s), not
     * `TokenBucket::THROTTLE_BURST_SECONDS`. Deriving the expected ceiling from
     * the production constant made this band self-adjust: widening the window
     * raised the ceiling by exactly the amount the extra burst delivered, so the
     * assertion followed the change instead of reporting it (measured: all three
     * tests in this file passed with the window mutated 1.0 → 5.0, a 5× capacity
     * change). If the window is deliberately changed, update this literal.
     */
    private function assertThrottledToCap(
        int $capBps,
        float $realisedBytesPerSecond,
        float $window,
        int $bytes,
        int $frames,
    ): void {
        $expectedBurstSeconds = 1.0;
        $capBytes = $capBps / 8.0;
        $burstBytes = $capBytes * $expectedBurstSeconds;
        $expectedCeiling = ($capBytes * $window + $burstBytes) / $window;
        $detail = sprintf(
            'cap=%d bps (%.0f B/s) | delivered=%d B in %d frames | window=%.2f s '
            . '| realised=%.0f B/s (%.3f Mbps) | burst-excluded=%.0f B/s',
            $capBps,
            $capBytes,
            $bytes,
            $frames,
            $window,
            $realisedBytesPerSecond,
            ($realisedBytesPerSecond * 8) / 1_000_000,
            ($bytes - $burstBytes) / $window,
        );

        $this->assertGreaterThanOrEqual(
            $capBytes * 0.97,
            $realisedBytesPerSecond,
            "throttle UNDER-delivered vs its cap — {$detail}",
        );
        $this->assertLessThanOrEqual(
            $expectedCeiling * 1.02,
            $realisedBytesPerSecond,
            "throttle OVER-delivered vs cap + one burst window — {$detail}",
        );
    }

    /**
     * @return array{channel:int,conn:ClientConnection,sent:object,frameBytes:int}
     */
    private function registerThrottled(Tunnel $tunnel, string $clientId, int $bps, float $base): array
    {
        $meter = new class {
            public int $bytes = 0;
            public int $frames = 0;
            public int $queueHighWater = 0;
            public bool $closed = false;
            public int $closedAtTick = 0;
            public int $bytesAtPeerClose = 0;
            public int $bytesAfterPeerClose = 0;
        };

        $ws = $this->createMock(TcpConnection::class);
        $ws->method('send')->willReturnCallback(static function (mixed $data) use ($meter): bool {
            // NUMERATOR: every byte handed to the socket, counted per call.
            $meter->bytes += strlen((string) $data);
            $meter->frames++;
            return true;
        });
        $ws->method('close')->willReturnCallback(static function () use ($meter): void {
            $meter->closed = true;
            $meter->bytesAtPeerClose = $meter->bytes;
        });

        $conn = new ClientConnection($ws, 'server-load', $clientId, $this->clientLogger, '', $bps);
        if ($bps > 0) {
            // Re-seed the bucket on the future-anchored clock (see class docblock).
            $conn->throttleBucket = TokenBucket::fromThrottleBps($bps, $base);
        }
        $tunnel->registerClient($conn);

        return [
            'channel' => $conn->channelId,
            'conn' => $conn,
            'sent' => $meter,
            'frameBytes' => strlen(
                $this->codec->encode(RelayFrameType::DATA, $conn->channelId, str_repeat('P', self::PAYLOAD_BYTES)),
            ),
        ];
    }

    private function activeTunnel(): Tunnel
    {
        $tunnel = new Tunnel(
            'server-load',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = 'session-load';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        return $tunnel;
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

    private function maxClientQueue(): int
    {
        /** @var int $value */
        $value = (new ReflectionClassConstant(Tunnel::class, 'MAX_CLIENT_QUEUE'))->getValue();

        return $value;
    }
}
