<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Mcp\McpSseStream;
use Phlix\Hub\Tests\Support\RecordingStreamTimers;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

use function count;
use function json_decode;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr_count;

/**
 * Unit tests for {@see McpSseStream} — the `GET /mcp` transport (S63).
 *
 * ## What this suite is really about
 *
 * Two things, and they are of different kinds.
 *
 * The first is WIRE FORMAT: SSE is a line protocol with exactly two structural
 * rules (a frame ends at a blank line; a `:`-leading line is a comment). Both
 * are easy to get subtly wrong and impossible to notice by eye in a chunked
 * body, so they are asserted on the bytes.
 *
 * The second, and the one that would actually cause an incident, is TIMER
 * LIFECYCLE. This runs in a Workerman worker that never restarts, so a timer
 * that outlives its connection leaks once per dropped client and then writes to
 * a dead socket forever. Nothing about that is visible in a passing integration
 * test — the stream works fine; it is the cleanup that does not. So the timers
 * are driven by hand ({@see RecordingStreamTimers}) and the cancellations are
 * asserted directly.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @covers \Phlix\Hub\Mcp\McpSseStream
 */
final class McpSseStreamTest extends TestCase
{
    // ------------------------------------------------------------------
    // Wire format
    // ------------------------------------------------------------------

    public function test_the_headers_declare_an_unbuffered_event_stream(): void
    {
        $headers = McpSseStream::headers();

        self::assertSame('text/event-stream', $headers['Content-Type'] ?? null);
        self::assertSame('chunked', $headers['Transfer-Encoding'] ?? null);
        self::assertSame('no', $headers['X-Accel-Buffering'] ?? null, 'nginx would otherwise batch the stream.');
        self::assertStringContainsString('no-store', strtolower($headers['Cache-Control'] ?? ''));
    }

    /**
     * The `Content-Type` must be the BARE media type.
     *
     * `Workerman\Protocols\Http\Response::__toString()` branches on
     * `$headers['Content-Type'] === 'text/event-stream'` — an equality test, so
     * a `; charset=utf-8` parameter would silently miss it. The value is
     * asserted exactly rather than with `assertStringContainsString`, because
     * `'text/event-stream'` is a substring of every spelling that would NOT hit
     * that branch.
     */
    public function test_the_content_type_is_bare_so_workermans_own_sse_branch_matches(): void
    {
        self::assertSame('text/event-stream', McpSseStream::headers()['Content-Type'] ?? null);
    }

    public function test_a_comment_frame_is_a_colon_line_terminated_by_a_blank_line(): void
    {
        $frame = McpSseStream::comment('hello');

        self::assertSame(": hello\n\n", $frame);
        self::assertTrue(str_starts_with($frame, ':'), 'an SSE comment must lead with a colon or it is a field.');
    }

    /**
     * A newline inside a comment would END the comment and let the rest of the
     * text be parsed as an SSE FIELD — an injection into the protocol. Both CR
     * and LF are neutralised.
     */
    public function test_a_comment_cannot_inject_a_field(): void
    {
        $frame = McpSseStream::comment("ok\ndata: {\"jsonrpc\":\"2.0\"}\r\nevent: message");

        self::assertSame(1, substr_count($frame, "\n\n"), 'the comment produced more than one frame.');
        self::assertStringNotContainsString("\ndata:", $frame);
        self::assertStringNotContainsString("\nevent:", $frame);
    }

    public function test_the_retry_field_is_a_whole_frame(): void
    {
        self::assertSame("retry: 3000\n\n", McpSseStream::retry(3000));
    }

    /**
     * The message framing exists for the first server-initiated notification,
     * which does not exist yet. It is tested now so that framing is not invented
     * under pressure later — and because a `data:` payload spanning two lines
     * would be parsed as two frames, which is the failure it is easiest to ship.
     */
    public function test_a_message_frame_carries_the_envelope_on_one_data_line(): void
    {
        $frame = McpSseStream::message(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']);

        self::assertStringStartsWith("event: message\ndata: ", $frame);
        self::assertStringEndsWith("\n\n", $frame);
        self::assertSame(1, substr_count($frame, "\n\n"));

        $payload = substr($frame, strlen("event: message\ndata: "), -2);
        self::assertStringNotContainsString(
            "\n",
            $payload,
            'the payload spans multiple lines, so an SSE parser would read it as two frames.',
        );
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded, 'the data line is not valid JSON: ' . $payload);
        self::assertSame('2.0', $decoded['jsonrpc'] ?? null);
    }

    /**
     * The opening bytes carry the reconnect hint AND a comment, so an
     * intermediary waiting for first bytes releases at once instead of holding
     * the connection until the first keep-alive 15 seconds later.
     */
    public function test_the_opening_bytes_carry_a_retry_hint_and_a_comment(): void
    {
        $opening = McpSseStream::opening();

        self::assertStringContainsString('retry: ' . McpSseStream::RETRY_MS, $opening);
        self::assertStringContainsString(': phlix-hub MCP event stream open', $opening);
    }

    // ------------------------------------------------------------------
    // Opening a stream
    // ------------------------------------------------------------------

    public function test_opening_writes_a_head_then_the_opening_frames(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';
        $connection = $this->connection($written);

        (new McpSseStream($timers))->open($connection);

        self::assertStringStartsWith('HTTP/1.1 200 OK', $written);
        self::assertStringContainsString("Content-Type: text/event-stream\r\n", $written);
        self::assertStringContainsString("Transfer-Encoding: chunked\r\n", $written);
        self::assertStringContainsString('retry: ' . McpSseStream::RETRY_MS, $written);
        self::assertStringNotContainsString(
            'Content-Length',
            $written,
            'a Content-Length on an open-ended stream would truncate it at the declared length.',
        );
    }

    /**
     * Exactly two timers: a repeating keep-alive and a one-shot deadline.
     */
    public function test_opening_schedules_a_repeating_keepalive_and_a_one_shot_deadline(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';

        (new McpSseStream($timers, 15, 900))->open($this->connection($written));

        self::assertSame(
            [
                ['interval' => 15, 'persistent' => true],
                ['interval' => 900, 'persistent' => false],
            ],
            $timers->scheduled,
        );
    }

    public function test_the_keepalive_writes_a_comment_frame(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';
        (new McpSseStream($timers))->open($this->connection($written));

        $keepaliveId = $timers->firstLiveIdWithPersistence(true);
        self::assertNotNull($keepaliveId, 'no repeating timer was scheduled.');

        $before = $written;
        self::assertTrue($timers->fire($keepaliveId));

        $added = substr($written, strlen($before));
        self::assertStringContainsString(': keep-alive', $added);
        self::assertStringNotContainsString('data:', $added, 'the quiet stream must never emit a message frame.');
    }

    /**
     * The deadline closes the stream CLEANLY: a terminating zero-length chunk
     * first, then the socket. A client that sees the terminator knows the
     * response ended and reconnects on the `retry:` hint; one that sees a bare
     * socket reset reports an error.
     */
    public function test_the_deadline_terminates_the_stream_and_closes_the_connection(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';
        $connection = $this->connection($written);
        $connection->expects(self::once())->method('close');

        (new McpSseStream($timers))->open($connection);

        $deadlineId = $timers->firstLiveIdWithPersistence(false);
        self::assertNotNull($deadlineId);

        $before = strlen($written);
        self::assertTrue($timers->fire($deadlineId));

        self::assertStringContainsString("0\r\n\r\n", substr($written, $before), 'no terminating chunk was sent.');
    }

    // ------------------------------------------------------------------
    // Timer lifecycle — the leak the class exists to avoid
    // ------------------------------------------------------------------

    /**
     * A client hang-up cancels BOTH timers.
     *
     * This is the assertion that matters most in a resident-memory worker. The
     * `onClose` hook is invoked the way Workerman invokes it, and both scheduled
     * ids must have been cancelled.
     */
    public function test_a_client_hangup_cancels_every_timer(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';
        $connection = $this->connection($written);

        (new McpSseStream($timers))->open($connection);

        self::assertCount(2, $timers->live, 'the fixture did not schedule the timers it is about to check.');

        self::assertIsCallable($connection->onClose);
        ($connection->onClose)($connection);

        self::assertSame([], $timers->live, 'a timer outlived its connection — that is a leak per dropped client.');
        self::assertCount(2, $timers->cancelled);
    }

    /**
     * ...and the keep-alive is INERT afterwards: a timer that somehow fires
     * after close must not write to a dead socket.
     */
    public function test_the_keepalive_writes_nothing_after_close(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';
        $connection = $this->connection($written);
        $stream = new McpSseStream($timers);
        $stream->open($connection);

        $keepaliveId = $timers->firstLiveIdWithPersistence(true);
        self::assertNotNull($keepaliveId);
        $keepalive = $timers->live[$keepaliveId]['callback'];

        self::assertIsCallable($connection->onClose);
        ($connection->onClose)($connection);

        $before = $written;
        $keepalive();

        self::assertSame($before, $written, 'the keep-alive wrote to a closed connection.');
    }

    /**
     * The previous `onClose` handler is CHAINED, not replaced.
     *
     * Another layer may already own this connection's close. Silently dropping
     * its callback would introduce a leak while fixing one — the exact shape
     * that makes a "cleanup" change a regression.
     */
    public function test_a_pre_existing_onclose_handler_is_still_invoked(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';
        $connection = $this->connection($written);

        $predecessorRan = false;
        $connection->onClose = static function () use (&$predecessorRan): void {
            $predecessorRan = true;
        };

        (new McpSseStream($timers))->open($connection);
        self::assertIsCallable($connection->onClose);
        ($connection->onClose)($connection);

        self::assertTrue($predecessorRan, 'the pre-existing onClose handler was discarded.');
        self::assertSame([], $timers->live);
    }

    /**
     * ...and a THROWING predecessor still leaves the timers cancelled. The
     * cancellation happens first precisely so it cannot be skipped by somebody
     * else's bug.
     */
    public function test_a_throwing_predecessor_does_not_prevent_cancellation(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';
        $connection = $this->connection($written);
        $connection->onClose = static function (): void {
            throw new \RuntimeException('predecessor exploded');
        };

        (new McpSseStream($timers))->open($connection);
        self::assertIsCallable($connection->onClose);
        ($connection->onClose)($connection);

        self::assertSame([], $timers->live);
    }

    /**
     * A failed keep-alive WRITE terminates the stream rather than firing at a
     * dead socket every 15 seconds until the deadline.
     */
    public function test_a_failed_keepalive_write_terminates_the_stream(): void
    {
        $timers = new RecordingStreamTimers();
        $sends = 0;
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('send')->willReturnCallback(
            static function () use (&$sends): bool {
                $sends++;
                // The head and the opening frame succeed; everything after the
                // client has gone fails.
                return $sends <= 2;
            },
        );
        $connection->expects(self::once())->method('close');

        (new McpSseStream($timers))->open($connection);

        $keepaliveId = $timers->firstLiveIdWithPersistence(true);
        self::assertNotNull($keepaliveId);
        self::assertTrue($timers->fire($keepaliveId));

        self::assertSame([], $timers->live, 'a dead socket left its timers running.');
    }

    /**
     * If timers cannot be scheduled at all — the "no event loop" degradation
     * {@see \Phlix\Hub\Mcp\WorkermanStreamTimers} reports as `false` — the
     * stream still OPENS and still writes its head, and no bogus id is
     * recorded for cancellation.
     *
     * The alternative (throwing) would take down the request that was being
     * served over a keep-alive that is a nicety.
     */
    public function test_a_stream_still_opens_when_no_timer_can_be_scheduled(): void
    {
        $timers = new RecordingStreamTimers(refuse: true);
        $written = '';
        $connection = $this->connection($written);

        (new McpSseStream($timers))->open($connection);

        self::assertStringStartsWith('HTTP/1.1 200 OK', $written);
        self::assertSame([], $timers->live);

        self::assertIsCallable($connection->onClose);
        ($connection->onClose)($connection);
        self::assertSame([], $timers->cancelled, 'a timer that was never scheduled must not be cancelled.');
    }

    // ------------------------------------------------------------------
    // Anti-vacuity
    // ------------------------------------------------------------------

    /**
     * The stream must never emit a JSON-RPC `data:` frame on its own.
     *
     * The hub has nothing to push (`tools.listChanged: false`, no resources, no
     * prompts, no subscriptions), and a non-JSON-RPC `data:` frame on this
     * channel is a protocol violation the client may disconnect over. Asserted
     * over the whole lifecycle — open, keep-alive, close — rather than at one
     * instant.
     */
    public function test_nothing_the_stream_emits_by_itself_is_a_data_frame(): void
    {
        $timers = new RecordingStreamTimers();
        $written = '';
        $connection = $this->connection($written);

        $stream = new McpSseStream($timers);
        $stream->open($connection);
        $keepaliveId = $timers->firstLiveIdWithPersistence(true);
        self::assertNotNull($keepaliveId);
        $timers->fire($keepaliveId);
        $timers->fire($keepaliveId);
        $deadlineId = $timers->firstLiveIdWithPersistence(false);
        self::assertNotNull($deadlineId);
        $timers->fire($deadlineId);

        self::assertFalse(str_contains($written, 'data:'), 'the quiet stream fabricated a message.');
        self::assertFalse(str_contains($written, 'event:'), 'the quiet stream fabricated an event.');
        // ...and the run was not vacuous: real frames WERE written.
        self::assertGreaterThan(1, substr_count($written, ': keep-alive'));
    }

    /**
     * A `TcpConnection` double that appends everything sent to `$sink`.
     *
     * @param string $sink Receives every byte written, by reference.
     */
    private function connection(string &$sink): TcpConnection
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('send')->willReturnCallback(
            static function (mixed $payload) use (&$sink): bool {
                $sink .= (string) $payload;
                return true;
            },
        );

        return $connection;
    }
}
