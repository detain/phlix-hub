<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\ConnectionResponseSink;
use Phlix\Hub\Relay\TokenBucket;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

use function count;
use function implode;
use function str_contains;
use function str_repeat;

final class ConnectionResponseSinkTest extends TestCase
{
    /**
     * A test double for the browser connection: records everything written and
     * every close() call, and lets a test force send() to fail (client gone).
     * Skips the parent constructor so no live socket is needed; close() is
     * overridden rather than delegating to the real `TcpConnection::close()`
     * so the force-close assertions are direct or (see
     * {@see self::testBodyReportsFalseWhenTheConnectionSendFails()}
     * onward) not entangled with `TcpConnection`'s internal socket/event-loop
     * state, which is never initialised for this double. No return type-hint
     * so PHPStan keeps the anonymous class's public properties visible to
     * callers.
     */
    private function connection(bool $sendResult = true)
    {
        return new class ($sendResult) extends TcpConnection {
            /** @var list<string> */
            public array $written = [];
            public bool $closeCalled = false;

            public function __construct(private readonly bool $sendResult = true)
            {
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                $this->written[] = (string) $sendBuffer;
                return $this->sendResult;
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
                $this->closeCalled = true;
            }
        };
    }

    public function testFixedLengthPreservesContentLengthAndStreamsRawBody(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => '6']);
        $this->assertTrue($sink->body('foobar'));
        $sink->end();

        $written = $connection->written;
        $head = $written[0];
        $this->assertStringContainsString('HTTP/1.1 200 OK', $head);
        $this->assertStringContainsString('Content-Length: 6', $head);
        $this->assertStringContainsString('Content-Type: video/mp2t', $head);
        // Fixed-length framing: NOT chunked.
        $this->assertStringNotContainsString('Transfer-Encoding', $head);
        // Body streamed as raw bytes (no chunk-size prefix), and end() emits no
        // terminating chunk in fixed-length mode.
        $this->assertSame('foobar', $written[1]);
        $this->assertCount(2, $written);
    }

    public function testBytesStreamedCountsRawBodyBytesForFixedLength(): void
    {
        // HB-3.4 G1: bytesStreamed() is the authoritative on-the-wire download
        // total the bandwidth accounting meters — real body bytes, not headers.
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => '6']);
        self::assertSame(0, $sink->bytesStreamed());
        $sink->body('foo');
        $sink->body('bar');
        $sink->end();

        // 6 raw body bytes ('foo' + 'bar'), independent of the head bytes.
        self::assertSame(6, $sink->bytesStreamed());
    }

    public function testBytesStreamedCountsRawBodyBytesForChunked(): void
    {
        // Chunked framing wraps each fragment in a chunk header on the wire, but
        // bytesStreamed() must count only the RAW body bytes (the download the
        // user actually received), not the chunk framing overhead.
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $sink->body('hello');
        $sink->body('worldwide');
        $sink->end();

        self::assertSame(14, $sink->bytesStreamed()); // 5 + 9
    }

    public function testBytesStreamedDoesNotCountAFailedSend(): void
    {
        // When the connection reports the body send failed (client gone), those
        // bytes never reached the wire and must not be metered.
        $connection = $this->connection(sendResult: false);
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => '3']);
        self::assertFalse($sink->body('foo'));

        self::assertSame(0, $sink->bytesStreamed());
    }

    public function testUnknownLengthUsesChunkedTransferEncoding(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $sink->body('foo');
        $sink->end();

        $written = $connection->written;
        $this->assertStringContainsString('Transfer-Encoding: chunked', $written[0]);
        $this->assertStringNotContainsString('Content-Length', $written[0]);
        // Chunk framing: "3\r\nfoo\r\n" then the terminating "0\r\n\r\n".
        $this->assertSame("3\r\nfoo\r\n", $written[1]);
        $this->assertSame("0\r\n\r\n", $written[2]);
    }

    public function testPreservesContentRangeAnd206ForRangedRequests(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        // A direct-play Range seek: the server answers 206 + Content-Range +
        // Content-Length, all of which must survive the pass-through so <video>
        // seeking keeps working through the hub.
        $sink->head(206, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => '5',
            'Content-Range' => 'bytes 10-14/1000',
            'Accept-Ranges' => 'bytes',
        ]);
        $sink->body('12345');
        $sink->end();

        $head = $connection->written[0];
        $this->assertStringContainsString('HTTP/1.1 206 Partial Content', $head);
        $this->assertStringContainsString('Content-Range: bytes 10-14/1000', $head);
        $this->assertStringContainsString('Content-Length: 5', $head);
    }

    public function testStripsHopByHopHeadersButKeepsContentLength(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, [
            'Content-Length' => '3',
            'Connection' => 'close',
            'Keep-Alive' => 'timeout=5',
            'Transfer-Encoding' => 'gzip',
            'X-Custom' => 'kept',
        ]);

        $head = $connection->written[0];
        $this->assertStringContainsString('Content-Length: 3', $head);
        $this->assertStringContainsString('X-Custom: kept', $head);
        // The inbound hop-by-hop values are dropped (the sink emits its own
        // Connection: keep-alive; the gzip Transfer-Encoding is not forwarded).
        $this->assertStringNotContainsString('Keep-Alive: timeout=5', $head);
        $this->assertStringNotContainsString('Transfer-Encoding: gzip', $head);
    }

    public function testBodyReportsFalseWhenTheConnectionSendFails(): void
    {
        $connection = $this->connection(sendResult: false);
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Length' => '3']);
        // send() returns false (client gone) → body() reports the downstream gone.
        $this->assertFalse($sink->body('abc'));
        // Once closed, further writes are no-ops that keep reporting gone.
        $this->assertFalse($sink->body('def'));
        // A fixed-length response that failed mid-write must not be left
        // keep-alive with fewer bytes than its declared Content-Length (finding
        // 3): the connection is force-closed rather than reused.
        $this->assertTrue($connection->closeCalled);
    }

    public function testHeadResponseIsNeverForceClosedForAShortBody(): void
    {
        // A HEAD response legitimately has zero body bytes regardless of its
        // Content-Length (RFC 9110 §9.3.2) — the short-body force-close
        // safeguard (finding 3) must not fire for it.
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection, 'HEAD');

        $sink->head(200, ['Content-Length' => '1000']);
        $sink->end();

        $this->assertFalse($connection->closeCalled);
    }

    public function testEndForceClosesAShortFixedLengthResponse(): void
    {
        // Models the relay-side inactivity/absolute-duration ceiling ending a
        // stream (RelayProxyManager::onTimeout()/touchStreamTimer()) before all
        // declared bytes arrived: end() must force-close rather than leave the
        // connection keep-alive with a short body (finding 3).
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Length' => '6']);
        $sink->body('abc'); // only half the declared 6 bytes
        $sink->end();

        $this->assertTrue($connection->closeCalled);
    }

    public function testEndDoesNotForceCloseACompleteFixedLengthResponse(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Length' => '6']);
        $sink->body('foobar');
        $sink->end();

        $this->assertFalse($connection->closeCalled);
    }

    public function testBodyParksOnBufferFullAndResumesOnceDrained(): void
    {
        // Regression for finding 5: proves the onBufferFull -> resume->pop() ->
        // onBufferDrain hand-off actually runs (the earlier tests never flip
        // `paused`, so this path was previously uncovered). PHPUnit has no real
        // event loop, so the drain is fired synchronously from inside send()
        // — the only way to exercise the hand-off without a live coroutine
        // scheduler — but it drives the exact same production code path
        // (`ConnectionResponseSink::body()`'s `$this->paused` branch).
        $connection = new class extends TcpConnection {
            /** @var list<string> */
            public array $written = [];
            public bool $closeCalled = false;

            public function __construct()
            {
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                $this->written[] = (string) $sendBuffer;
                if (count($this->written) === 2) {
                    // The body write fills the buffer, then immediately drains.
                    /** @var callable $onBufferFull */
                    $onBufferFull = $this->onBufferFull;
                    $onBufferFull();
                    /** @var callable $onBufferDrain */
                    $onBufferDrain = $this->onBufferDrain;
                    $onBufferDrain();
                }
                return true;
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
                $this->closeCalled = true;
            }
        };

        $sink = new ConnectionResponseSink($connection);
        $sink->head(200, ['Content-Length' => '6']);
        // A resumed (drained) parked write is success, not abandonment.
        $this->assertTrue($sink->body('foobar'));
        $sink->end();
        $this->assertFalse($connection->closeCalled);
    }

    public function testBodyGivesUpAndForceClosesWhenDrainNeverArrives(): void
    {
        // Regression for finding 5: the abandon-while-paused path (drain never
        // comes) must report the downstream gone AND force-close (finding 3),
        // not just silently stop.
        $connection = new class extends TcpConnection {
            /** @var list<string> */
            public array $written = [];
            public bool $closeCalled = false;

            public function __construct()
            {
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                $this->written[] = (string) $sendBuffer;
                if (count($this->written) === 2) {
                    // Buffer fills and never drains.
                    /** @var callable $onBufferFull */
                    $onBufferFull = $this->onBufferFull;
                    $onBufferFull();
                }
                return true;
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
                $this->closeCalled = true;
            }
        };

        $sink = new ConnectionResponseSink($connection);
        $sink->head(200, ['Content-Length' => '6']);
        // Only half the declared 6 bytes are written before the stall.
        $this->assertFalse($sink->body('foo'));
        $this->assertTrue($connection->closeCalled);

        // Once abandoned, further writes stay no-ops.
        $this->assertFalse($sink->body('bar'));
    }

    public function testAbortForceClosesTheConnectionAndDetachesTheHooks(): void
    {
        // The round-2 wire-corruption fix contract: after real bytes may have
        // been written, the bridge calls abort() (never end()) — it must
        // force-close the connection unconditionally and remove the
        // back-pressure hooks so a reused keep-alive socket does not inherit
        // this stream's callbacks.
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Length' => '100']);
        $sink->body('partial'); // fewer than the declared 100 bytes
        $sink->abort();

        $this->assertTrue($connection->closeCalled, 'abort() must force-close the connection');
        $this->assertNull($connection->onBufferFull, 'abort() must detach the buffer-full hook');
        $this->assertNull($connection->onBufferDrain, 'abort() must detach the buffer-drain hook');
    }

    public function testAbortAfterTheConnectionIsAlreadyClosedDoesNotCloseAgain(): void
    {
        // If the sink already force-closed (e.g. a send() failure mid-body),
        // a subsequent abort() from the bridge's exception path must be a no-op
        // that only detaches — not a second close() on a torn-down socket.
        $connection = new class extends TcpConnection {
            /** @var list<string> */
            public array $written = [];
            public int $closeCount = 0;

            public function __construct()
            {
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                $this->written[] = (string) $sendBuffer;
                return false; // client gone → body() force-closes + marks closed
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
                $this->closeCount++;
            }
        };

        $sink = new ConnectionResponseSink($connection);
        $sink->head(200, ['Content-Length' => '6']);
        $this->assertFalse($sink->body('abc')); // send() fails → force-close (count 1)
        $this->assertSame(1, $connection->closeCount);

        // abort() now hits the already-closed early return: no second close().
        $sink->abort();
        $this->assertSame(1, $connection->closeCount, 'abort() must not close an already-closed connection again');
    }

    public function testAbortSwallowsACloseFailure(): void
    {
        // abort() is itself the error-recovery path (the caller has nowhere left
        // to route a failure), so a throw from close() must never escape it.
        $connection = new class extends TcpConnection {
            public function __construct()
            {
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                return true;
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
                throw new \RuntimeException('socket already torn down');
            }
        };

        $sink = new ConnectionResponseSink($connection);
        $sink->head(200, ['Content-Length' => '6']);

        // Must not throw.
        $sink->abort();
        $this->assertNull($connection->onBufferFull, 'abort() must still detach even when close() throws');
    }

    public function testASecondHeadCallIsIgnored(): void
    {
        // head() is documented as called exactly once; a defensive second call
        // must be a no-op (not emit a second status line onto the wire).
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Length' => '3']);
        $sink->head(500, ['Content-Length' => '999']); // ignored

        $this->assertCount(1, $connection->written);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $connection->written[0]);
        $this->assertStringNotContainsString('500', $connection->written[0]);
    }

    public function testEndAfterTheConnectionIsAlreadyClosedIsANoop(): void
    {
        // After a mid-body send() failure marked the sink closed, a late end()
        // must simply detach — no terminating write, no extra close().
        $connection = new class extends TcpConnection {
            /** @var list<string> */
            public array $written = [];
            public int $closeCount = 0;

            public function __construct()
            {
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                $this->written[] = (string) $sendBuffer;
                return false;
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
                $this->closeCount++;
            }
        };

        $sink = new ConnectionResponseSink($connection);
        $sink->head(200, ['Content-Length' => '6']);
        $this->assertFalse($sink->body('abc')); // closes (count 1)
        $writesBeforeEnd = count($connection->written);

        $sink->end();
        $this->assertSame($writesBeforeEnd, count($connection->written), 'end() on a closed sink must not write');
        $this->assertSame(1, $connection->closeCount, 'end() on a closed sink must not close again');
        $this->assertNull($connection->onBufferFull, 'end() must detach even on the closed path');
    }

    public function testFixedLengthHeadDefaultsContentTypeWhenAbsent(): void
    {
        // A fixed-length response with no Content-Type must still get a valid
        // default so the browser is never left guessing the framing.
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Length' => '3']);

        $this->assertStringContainsString('Content-Type: application/octet-stream', $connection->written[0]);
        $this->assertStringContainsString('Connection: keep-alive', $connection->written[0]);
    }

    public function testBodyBeforeHeadIsIgnored(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        // Defensive: a body() before head() writes nothing and does not error.
        $this->assertTrue($sink->body('early'));
        $this->assertSame('', implode('', $connection->written));
    }

    public function testCrlfSmugglingHeadersAreDropped(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, [
            'Content-Length' => '1',
            "X-Evil\r\nInjected" => 'x',
            'X-Bad' => "value\r\nInjected: 1",
        ]);

        $head = $connection->written[0];
        $this->assertFalse(str_contains($head, 'Injected'));
    }

    // ---------------------------------------------------------------------
    // Per-user bandwidth throttle (S43, updates.md #50)
    // ---------------------------------------------------------------------

    public function testUnlimitedStreamIsNeverPaced(): void
    {
        // 0 = Unlimited → fromThrottleBps() returns null → the sink takes its
        // fast path: body() must never invoke the sleeper (no pacing overhead)
        // and streams every byte immediately.
        $bucket = TokenBucket::fromThrottleBps(0);
        $this->assertNull($bucket, '0 = Unlimited must yield no bucket');

        $connection = $this->connection();
        $sleeperCalls = 0;
        $sleeper = static function (float $seconds) use (&$sleeperCalls): void {
            $sleeperCalls++;
        };
        $sink = new ConnectionResponseSink($connection, 'GET', $bucket, $sleeper);

        $sink->head(200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($sink->body(str_repeat('y', 1000)));
        }
        $sink->end();

        $this->assertSame(0, $sleeperCalls, 'Unlimited (null bucket) must never pace');
        $this->assertSame(50 * 1000, $sink->bytesStreamed());
    }

    public function testThrottledBodyStreamIsPacedToTheConfiguredCap(): void
    {
        // Drive the token bucket deterministically: a virtual clock advanced ONLY
        // by the injected sleeper, so total elapsed == the time the pacing loop
        // chose to sleep — no real event loop, no wall-clock flakiness.
        $now = 1000.0;
        $slept = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $sleeper = static function (float $seconds) use (&$now, &$slept): void {
            $now += $seconds;
            $slept += $seconds;
        };

        // 8000 bits/sec = 1000 bytes/sec cap, 1 s burst (1000 B capacity).
        $bucket = TokenBucket::fromThrottleBps(8000, $now);
        $this->assertNotNull($bucket);

        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection, 'GET', $bucket, $sleeper, $clock);

        // Chunked (no Content-Length) so we can stream many fragments freely
        // without tripping the fixed-length short-body safeguard.
        $sink->head(200, ['Content-Type' => 'application/vnd.apple.mpegurl']);

        $fragment = str_repeat('x', 100); // 100-byte fragments
        $fragments = 200;                 // 20 000 bytes total
        for ($i = 0; $i < $fragments; $i++) {
            $this->assertTrue($sink->body($fragment));
        }
        $sink->end();

        $bytes = $fragments * 100;
        $this->assertSame($bytes, $sink->bytesStreamed());

        // The stream was actually paced (a real, substantial virtual wait), not
        // let through instantly — this is the anti-tautology guard.
        $this->assertGreaterThan(0.0, $slept, 'a finite cap must pace (sleep) the stream');

        // Realised throughput must track the 1000 B/s cap. It sits slightly ABOVE
        // the cap for a finite run (the initial 1 s burst is delivered "for free")
        // and converges downward to the cap as the run grows; it is never far
        // below it. Assert a tight band around the configured cap.
        $realisedRate = $bytes / $slept;
        $this->assertGreaterThanOrEqual(1000.0 * 0.95, $realisedRate, 'throttle under-delivered vs cap');
        $this->assertLessThanOrEqual(1000.0 * 1.10, $realisedRate, 'throttle over-delivered vs cap');

        // Bounded memory: the sink buffers nothing — each fragment is passed
        // straight through as exactly one chunk write, so under sustained
        // over-rate the sink's footprint stays O(1). Writes = head (1) + one per
        // fragment + the terminating zero-chunk from end() (1) — none dropped,
        // duplicated, or accumulated.
        $this->assertSame($fragments + 2, count($connection->written));
    }

    public function testAThrottledStreamDoesNotAffectAConcurrentUnlimitedStream(): void
    {
        // Model two users multiplexed on one worker: each has its OWN sink +
        // bucket + clock. Throttling one must not pace the other — the throttle
        // is strictly per-connection state (no shared/static throttle).

        // User A — throttled at 1000 B/s.
        $aNow = 0.0;
        $aSlept = 0.0;
        $aClock = static function () use (&$aNow): float {
            return $aNow;
        };
        $aSleeper = static function (float $seconds) use (&$aNow, &$aSlept): void {
            $aNow += $seconds;
            $aSlept += $seconds;
        };
        $aConn = $this->connection();
        $aSink = new ConnectionResponseSink(
            $aConn,
            'GET',
            TokenBucket::fromThrottleBps(8000, $aNow),
            $aSleeper,
            $aClock,
        );

        // User B — Unlimited (null bucket). Its sleeper must NEVER fire.
        $bSleeperCalls = 0;
        $bSleeper = static function (float $seconds) use (&$bSleeperCalls): void {
            $bSleeperCalls++;
        };
        $bConn = $this->connection();
        $bSink = new ConnectionResponseSink(
            $bConn,
            'GET',
            TokenBucket::fromThrottleBps(0),
            $bSleeper,
        );

        $aSink->head(200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $bSink->head(200, ['Content-Type' => 'application/vnd.apple.mpegurl']);

        // Interleave the two streams.
        $fragment = str_repeat('z', 500);
        for ($i = 0; $i < 60; $i++) {
            $this->assertTrue($aSink->body($fragment));
            $this->assertTrue($bSink->body($fragment));
        }
        $aSink->end();
        $bSink->end();

        // A was paced; B was never touched by A's throttle.
        $this->assertGreaterThan(0.0, $aSlept, 'the throttled stream must have been paced');
        $this->assertSame(0, $bSleeperCalls, 'the Unlimited concurrent stream must never be paced');
        $this->assertSame(60 * 500, $aSink->bytesStreamed());
        $this->assertSame(60 * 500, $bSink->bytesStreamed());
    }

    public function testAnOversizedFragmentNeverDeadlocksAThrottledStream(): void
    {
        // A fragment far larger than the whole bucket must still be released
        // (mirrors the WS path: canSpend gates on ANY positive budget, so an
        // oversized fragment drives the balance into debt that later refills pay
        // off — it can never permanently block the stream).
        $now = 0.0;
        $slept = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $sleeper = static function (float $seconds) use (&$now, &$slept): void {
            $now += $seconds;
            $slept += $seconds;
        };

        // 8000 bits/sec = 1000 B/s cap, 1000 B burst capacity.
        $bucket = TokenBucket::fromThrottleBps(8000, $now);
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection, 'GET', $bucket, $sleeper, $clock);

        $sink->head(200, ['Content-Type' => 'video/mp2t']);
        // 10 000 bytes — 10x the whole bucket — must still be delivered.
        $this->assertTrue($sink->body(str_repeat('q', 10_000)));
        // A second fragment must wait off the debt the first one drove, then go.
        $this->assertTrue($sink->body(str_repeat('q', 10_000)));
        $sink->end();

        $this->assertSame(20_000, $sink->bytesStreamed());
        $this->assertGreaterThan(0.0, $slept, 'the second oversized fragment must wait off the debt');
    }
}
