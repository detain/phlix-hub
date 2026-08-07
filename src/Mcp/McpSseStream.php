<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Response as WorkermanResponse;

use function is_callable;
use function json_encode;
use function str_replace;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The `GET /mcp` Server-Sent Events transport (S63).
 *
 * ## What this stream is, in MCP terms
 *
 * MCP's Streamable HTTP transport puts both directions on ONE endpoint. `POST`
 * carries client→server JSON-RPC (and is unchanged by this step). `GET` opens
 * the server→client channel: the server may push JSON-RPC requests and
 * notifications down it at any time, and the client keeps it open for the life
 * of the session. A server that offers no such channel is permitted to answer
 * the `GET` with `405`; a server that offers one must answer
 * `Content-Type: text/event-stream`.
 *
 * The hub offers one, and it is deliberately QUIET. Its tool set is fixed at
 * container-build time — `initialize` advertises `tools.listChanged: false` for
 * exactly that reason — and the hub exposes no resources, prompts,
 * subscriptions or progress, so there is nothing it can legitimately push. The
 * stream therefore carries only SSE **comments** (`: …` lines, which every
 * conformant parser discards) and a `retry:` hint. It never fabricates a
 * message, because a non-JSON-RPC `data:` frame on this channel would be a
 * protocol violation the client is entitled to disconnect over.
 *
 * That is still worth serving rather than 405-ing: the connection is the
 * session's liveness signal, some clients treat a missing stream as a degraded
 * server, and {@see message()} is the seam a future notification lands on
 * without re-opening the transport question.
 *
 * ## Framing: chunked, not connection-close
 *
 * The producer owns the whole on-the-wire response (see
 * {@see \Phlix\Hub\Http\Response::$streamProducer}). A `text/event-stream` has
 * no length known in advance, so the head declares `Transfer-Encoding: chunked`
 * and every frame goes out as one HTTP chunk — the identical idiom
 * {@see \Phlix\Hub\Http\ConnectionResponseSink::head()} uses for a relay body
 * of unknown length. The alternative (no length, no chunking, delimited by
 * connection close) contradicts the `keep-alive` the worker has already
 * negotiated and would desynchronise a pipelined client.
 *
 * ## Resident memory: the part that must not be got wrong
 *
 * This runs inside a Workerman worker that never restarts between requests, so:
 *
 *  - **No blocking sleep.** The keep-alive is an event-loop timer, never
 *    `sleep()`, which would stall every other connection in the worker.
 *  - **No per-stream state on `$this`.** One instance serves every concurrent
 *    stream, so all mutable state lives in the closures {@see open()} creates
 *    and dies with them. A `private array $streams` here would be the classic
 *    unbounded-static leak.
 *  - **Both timers are cancelled on close**, from an `onClose` hook that
 *    CHAINS the previous handler rather than replacing it. A timer surviving
 *    its connection is a leak that grows with every client that hangs up, and
 *    it would go on writing to a dead socket forever.
 *  - **The stream has a hard lifetime ceiling.** A client that opens streams
 *    and never reads them cannot accumulate them without bound; each one closes
 *    itself at {@see $maxSeconds} and any real client reconnects (that is what
 *    the `retry:` hint is for).
 *
 * @package Phlix\Hub\Mcp
 * @since   S63 (MCP SSE/protocol correctness + flagged playback tool)
 */
final class McpSseStream
{
    /** Seconds between keep-alive comments. */
    public const int DEFAULT_KEEPALIVE_SECONDS = 15;

    /**
     * Hard ceiling on one stream's lifetime, in seconds.
     *
     * Fifteen minutes: long enough that a working agent is not churning
     * connections, short enough that an abandoned one is reclaimed while the
     * worker is still the same process. The client is told to reconnect after
     * {@see RETRY_MS}, so the ceiling is invisible to a conformant client.
     */
    public const int DEFAULT_MAX_SECONDS = 900;

    /** The `retry:` hint (milliseconds) sent once at stream open. */
    public const int RETRY_MS = 3000;

    /**
     * @param McpStreamTimers $timers          Event-loop timers.
     * @param int             $keepaliveSeconds Keep-alive period.
     * @param int             $maxSeconds       Lifetime ceiling.
     */
    public function __construct(
        private readonly McpStreamTimers $timers,
        private readonly int $keepaliveSeconds = self::DEFAULT_KEEPALIVE_SECONDS,
        private readonly int $maxSeconds = self::DEFAULT_MAX_SECONDS,
    ) {
    }

    /**
     * The response head an SSE stream must carry.
     *
     * `X-Accel-Buffering: no` is for the nginx in `docker/nginx.conf`, which
     * would otherwise buffer the stream and deliver every frame at once —
     * turning a live channel into a batch at close. `no-store` is on the
     * `Cache-Control` because an intermediary that cached this would replay a
     * dead session to the next client.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            // Spelled bare, with no `; charset=utf-8` parameter. SSE is defined
            // as UTF-8 so the parameter carries nothing, and the bare spelling
            // is the one `Workerman\Protocols\Http\Response::__toString()`
            // recognises for its own event-stream branch — a `===` comparison
            // that a parameter would silently miss.
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Transfer-Encoding' => 'chunked',
        ];
    }

    /**
     * An SSE comment frame — ignored by every conformant parser, so it is the
     * only thing that may be sent on a channel with no JSON-RPC to carry.
     *
     * @param string $text Comment text. CR/LF are stripped: a newline here
     *        would end the comment and let the remainder be read as a field.
     */
    public static function comment(string $text): string
    {
        return ': ' . str_replace(["\r", "\n"], ' ', $text) . "\n\n";
    }

    /**
     * The `retry:` field, telling the client how long to wait before
     * reconnecting after the lifetime ceiling closes the stream.
     */
    public static function retry(int $milliseconds): string
    {
        return 'retry: ' . $milliseconds . "\n\n";
    }

    /**
     * A JSON-RPC message frame.
     *
     * Nothing calls this today — the hub has no server-initiated message (see
     * the class docblock). It exists so that the day it does, the framing is
     * already correct and already tested, rather than invented under pressure.
     * The `data:` payload is single-line because {@see json_encode()} without
     * `JSON_PRETTY_PRINT` emits no newline; a multi-line payload would need one
     * `data:` line per line.
     *
     * @param array<string, mixed> $envelope A JSON-RPC 2.0 envelope.
     *
     * @throws \JsonException When the envelope cannot be encoded.
     */
    public static function message(array $envelope): string
    {
        return "event: message\ndata: "
            . json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . "\n\n";
    }

    /**
     * The bytes written the instant the stream opens: head, `retry:` hint, and
     * an opening comment so a proxy that waits for first bytes releases at once.
     *
     * Separated from {@see open()} so a test can assert the wire format without
     * installing timers.
     */
    public static function opening(): string
    {
        return (string) new Chunk(self::retry(self::RETRY_MS))
            . (string) new Chunk(self::comment('phlix-hub MCP event stream open'));
    }

    /**
     * Take ownership of `$connection` and run the stream on it.
     *
     * @param TcpConnection $connection The live browser/client connection.
     */
    public function open(TcpConnection $connection): void
    {
        $connection->send((string) new WorkermanResponse(200, self::headers(), ''), true);
        $connection->send(self::opening(), true);

        // Per-stream state, by reference in the closures below — never on
        // `$this`, which is shared by every concurrent stream.
        $closed = false;
        /** @var list<int> $timerIds */
        $timerIds = [];

        $cancel = function () use (&$timerIds): void {
            foreach ($timerIds as $timerId) {
                $this->timers->del($timerId);
            }
            $timerIds = [];
        };

        $finish = static function () use ($connection, &$closed, $cancel): void {
            if ($closed) {
                return;
            }
            $closed = true;
            $cancel();
            // Terminating zero-length chunk, then close: a client that sees the
            // terminator knows the response ENDED rather than that the socket
            // broke, and reconnects on the `retry:` hint instead of erroring.
            $connection->send((string) new Chunk(''), true);
            $connection->close();
        };

        $keepalive = static function () use ($connection, &$closed, $finish): void {
            if ($closed) {
                return;
            }
            if ($connection->send((string) new Chunk(self::comment('keep-alive')), true) === false) {
                // The socket is gone. Stop rather than keep firing at it.
                $finish();
            }
        };

        $keepaliveId = $this->timers->add($this->keepaliveSeconds, $keepalive, true);
        if ($keepaliveId !== false) {
            $timerIds[] = $keepaliveId;
        }
        $deadlineId = $this->timers->add($this->maxSeconds, $finish, false);
        if ($deadlineId !== false) {
            $timerIds[] = $deadlineId;
        }

        // Cancel on hang-up. The previous handler is CHAINED, not replaced:
        // another layer may already own this connection's close, and silently
        // dropping its callback is how a resource leak is introduced while
        // fixing one. `TcpConnection::$onClose` is an untyped public property,
        // so it is narrowed to `callable|null` HERE rather than carried into the
        // closure as a `mixed` — a mixed binding is what errorLevel 1 forbids
        // and what the inferred-types percentage counts.
        $previousOnClose = is_callable($connection->onClose) ? $connection->onClose : null;
        $connection->onClose = static function (TcpConnection $conn) use (
            &$closed,
            $cancel,
            $previousOnClose
        ): void {
            $closed = true;
            $cancel();
            if ($previousOnClose !== null) {
                try {
                    $previousOnClose($conn);
                } catch (Throwable) {
                    // A failing predecessor must not prevent our cancellation,
                    // which has already happened above.
                }
            }
        };
    }
}
