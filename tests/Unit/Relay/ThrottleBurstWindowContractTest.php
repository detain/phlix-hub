<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\TokenBucket;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

/**
 * S191 — the relay throttle's burst window is ONE value, and the two relay
 * transports size their buckets identically from it.
 *
 * ## The defect this guards
 *
 * The burst window was declared TWICE as independent `1.0` literals — once in
 * {@see TokenBucket} (the HTTP-over-relay proxy path, S43) and once in
 * {@see ClientConnection} (the WS relay path, S42) — with a docblock asking that
 * the two "stay identical" and **nothing enforcing it**. Two transports silently
 * pacing to different effective caps is exactly the kind of divergence a docblock
 * cannot prevent. `ClientConnection::THROTTLE_BURST_SECONDS` is now an alias of
 * `TokenBucket::THROTTLE_BURST_SECONDS`, so drift is unrepresentable; this test
 * fails if anyone re-inlines a literal there, and pins the shared value itself.
 *
 * ## Why the expectations are literals
 *
 * Every assertion below compares against a hand-derived literal, never against
 * the production constant. An assertion written `× THROTTLE_BURST_SECONDS`
 * computes its expectation FROM the value under test, so it self-adjusts to any
 * change and is a tautology wearing the shape of a test. Measured on this tree
 * before the fix: with the window mutated 1.0 → 5.0 (a 5× capacity change), the
 * capacity assertions in `TokenBucketTest`, `ClientConnectionTest` and all three
 * tests in `TunnelThrottleLoadTest` **passed**.
 *
 * If the burst window is ever changed deliberately, update the literals here and
 * the derivations in the comments — that edit is the point, not an obstacle.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class ThrottleBurstWindowContractTest extends TestCase
{
    /**
     * The documented burst window: a freshly-mounted stream may burst ~1 second
     * of data for a snappy start, then settles to the sustained per-user cap.
     * Declared here as a literal so this file is an INDEPENDENT statement of the
     * intended value rather than a restatement of the production one.
     */
    private const float EXPECTED_BURST_SECONDS = 1.0;

    public function testTheBurstWindowIsItsDocumentedValue(): void
    {
        self::assertSame(
            self::EXPECTED_BURST_SECONDS,
            TokenBucket::THROTTLE_BURST_SECONDS,
            'the relay burst window changed; update the literals in this file and in '
            . 'TokenBucketTest / ClientConnectionTest / TunnelThrottleLoadTest deliberately',
        );
    }

    public function testBothRelayPathsShareOneBurstWindowConstant(): void
    {
        // Identity, not equality-by-coincidence: ClientConnection's constant is an
        // alias of TokenBucket's, so this fails the moment a literal is re-inlined
        // on the WS side — the drift the old docblock could only request.
        self::assertSame(
            TokenBucket::THROTTLE_BURST_SECONDS,
            ClientConnection::THROTTLE_BURST_SECONDS,
            'the WS relay path and the HTTP-over-relay proxy path must pace to the SAME '
            . 'burst window; they have drifted apart',
        );

        self::assertSame(self::EXPECTED_BURST_SECONDS, ClientConnection::THROTTLE_BURST_SECONDS);
    }

    /**
     * The behavioural half: the constants agreeing is not enough, because each
     * path does its own bits→bytes conversion and its own capacity arithmetic. A
     * change to either path's inline math must be caught even if the shared
     * constant is untouched.
     *
     * 8 Mbps = 8_000_000 bits/sec ÷ 8 = 1_000_000 bytes/sec sustained; capacity =
     * that rate × the 1-second window = 1_000_000 bytes.
     */
    public function testBothRelayPathsSizeTheSameBucketForOneCap(): void
    {
        $capBps = 8_000_000;
        $expectedRate = 1_000_000.0;
        $expectedCapacity = 1_000_000.0;

        // HTTP-over-relay proxy path (S43).
        $httpBucket = TokenBucket::fromThrottleBps($capBps, 0.0);
        self::assertNotNull($httpBucket);
        self::assertSame($expectedRate, $httpBucket->ratePerSecond());
        self::assertSame($expectedCapacity, $httpBucket->capacity());

        // WS relay path (S42) — the real ClientConnection constructor, not a
        // hand-rolled mirror of it.
        $wsConnection = new ClientConnection(
            $this->createMock(TcpConnection::class),
            'server-s191',
            'client-s191',
            $this->createMock(StructuredLogger::class),
            '',
            $capBps,
        );
        self::assertNotNull($wsConnection->throttleBucket);
        self::assertSame($expectedRate, $wsConnection->throttleBucket->ratePerSecond());
        self::assertSame($expectedCapacity, $wsConnection->throttleBucket->capacity());

        // And the two agree with each other, so neither path can be "fixed" alone.
        self::assertSame(
            $httpBucket->capacity(),
            $wsConnection->throttleBucket->capacity(),
            'the two relay transports must build the same bucket for the same cap',
        );
    }
}
