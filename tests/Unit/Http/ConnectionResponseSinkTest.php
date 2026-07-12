<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\ConnectionResponseSink;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

use function implode;
use function str_contains;

/**
 * @covers \Phlix\Hub\Http\ConnectionResponseSink
 */
final class ConnectionResponseSinkTest extends TestCase
{
    /**
     * A test double for the browser connection: records everything written and
     * every close() call, and lets a test force send() to fail (client gone).
     * Skips the parent constructor so no live socket is needed; close() is
     * overridden rather than delegating to the real `TcpConnection::close()`
     * so the force-close assertions are direct or (see
     * {@see self::test_body_reports_false_when_the_connection_send_fails()}
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

    public function test_fixed_length_preserves_content_length_and_streams_raw_body(): void
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

    public function test_bytes_streamed_counts_raw_body_bytes_for_fixed_length(): void
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

    public function test_bytes_streamed_counts_raw_body_bytes_for_chunked(): void
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

    public function test_bytes_streamed_does_not_count_a_failed_send(): void
    {
        // When the connection reports the body send failed (client gone), those
        // bytes never reached the wire and must not be metered.
        $connection = $this->connection(sendResult: false);
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => '3']);
        self::assertFalse($sink->body('foo'));

        self::assertSame(0, $sink->bytesStreamed());
    }

    public function test_unknown_length_uses_chunked_transfer_encoding(): void
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

    public function test_preserves_content_range_and_206_for_ranged_requests(): void
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

    public function test_strips_hop_by_hop_headers_but_keeps_content_length(): void
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

    public function test_body_reports_false_when_the_connection_send_fails(): void
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

    public function test_head_response_is_never_force_closed_for_a_short_body(): void
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

    public function test_end_force_closes_a_short_fixed_length_response(): void
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

    public function test_end_does_not_force_close_a_complete_fixed_length_response(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Length' => '6']);
        $sink->body('foobar');
        $sink->end();

        $this->assertFalse($connection->closeCalled);
    }

    public function test_body_parks_on_buffer_full_and_resumes_once_drained(): void
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

    public function test_body_gives_up_and_force_closes_when_drain_never_arrives(): void
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

    public function test_abort_force_closes_the_connection_and_detaches_the_hooks(): void
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

    public function test_abort_after_the_connection_is_already_closed_does_not_close_again(): void
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

    public function test_abort_swallows_a_close_failure(): void
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

    public function test_a_second_head_call_is_ignored(): void
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

    public function test_end_after_the_connection_is_already_closed_is_a_noop(): void
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

    public function test_fixed_length_head_defaults_content_type_when_absent(): void
    {
        // A fixed-length response with no Content-Type must still get a valid
        // default so the browser is never left guessing the framing.
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        $sink->head(200, ['Content-Length' => '3']);

        $this->assertStringContainsString('Content-Type: application/octet-stream', $connection->written[0]);
        $this->assertStringContainsString('Connection: keep-alive', $connection->written[0]);
    }

    public function test_body_before_head_is_ignored(): void
    {
        $connection = $this->connection();
        $sink = new ConnectionResponseSink($connection);

        // Defensive: a body() before head() writes nothing and does not error.
        $this->assertTrue($sink->body('early'));
        $this->assertSame('', implode('', $connection->written));
    }

    public function test_crlf_smuggling_headers_are_dropped(): void
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
}
