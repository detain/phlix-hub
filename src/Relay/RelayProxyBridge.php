<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Channel\Client as ChannelClient;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Throwable;
use Workerman\Coroutine\Channel;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function getmypid;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * HTTP-worker side of the cross-process relay proxy.
 *
 * Lives in each HTTP worker process. {@see request()} publishes a proxy request
 * to the relay-ws worker (which owns the tunnel) over the `workerman/channel`
 * broker and blocks the calling coroutine on a per-request channel until the
 * assembled response arrives on this worker's unique reply event — or the
 * timeout elapses. {@see onReply()} (wired as the reply-event subscriber in the
 * worker's `onWorkerStart`) hands the response to the waiting coroutine.
 *
 * The Workerman Swoole event loop runs every connection/message callback inside
 * a coroutine, so {@see Channel::pop()} suspends only the request's coroutine
 * (never the event loop) and the reply callback's {@see Channel::push()} into a
 * capacity-1 channel returns immediately.
 *
 * @package Phlix\Hub\Relay
 * @since 0.10.0
 */
final class RelayProxyBridge
{
    /**
     * Capacity of the per-request coroutine channel used by {@see self::stream()}
     * to hand streamed response phases (head/body/end) from {@see self::onReply()}
     * to the consuming coroutine. Bounded so a slow browser (whose sink parks on
     * connection back-pressure and stops draining) makes {@see self::onReply()}'s
     * push block once this many ~64 KB fragments are queued — the upstream
     * back-pressure signal — instead of letting the hub buffer without bound.
     */
    private const STREAM_CHANNEL_CAPACITY = 32;

    /**
     * Bounded timeout (seconds) for {@see self::onReply()}'s push into a
     * per-request channel.
     *
     * `onReply()` is the SINGLE shared subscriber for this worker's reply event
     * (wired once in `Application.php`'s `onWorkerStart`), so an un-timed push
     * (the `Workerman\Coroutine\Channel::push()` default is to block forever)
     * would stall delivery of every OTHER in-flight request's reply on this
     * worker while it waits on one stuck consumer — not just the slow stream.
     * Bounding it converts that indefinite worker-wide stall into, at worst, a
     * bounded delay before the stuck reply is dropped (see
     * {@see self::onReply()}).
     *
     * MUST stay comfortably above {@see \Phlix\Hub\Http\ConnectionResponseSink::BACKPRESSURE_WAIT_SECONDS}
     * (30s): while a slow-but-alive browser is parked draining its send buffer,
     * the consuming coroutine in {@see self::stream()} is not popping the
     * channel, so it can legitimately look "full" for up to that long without
     * the stream actually being dead. A push timeout at or below that value
     * would misclassify a merely-slow browser as an abandoned one. The channel
     * is also explicitly {@see Channel::close()}d as soon as {@see self::stream()}
     * gives up (see its `finally`), which wakes any blocked push immediately —
     * this timeout is the defense-in-depth backstop for any path that doesn't
     * go through that close (e.g. a future refactor, or the buffered
     * capacity-1 channel used by {@see self::request()}).
     *
     * **Test-environment note:** this timeout's actual blocking/bounding
     * behavior relies on Swoole's documented `Swoole\Coroutine\Channel::push(mixed
     * $data, float $timeout)` semantics under a real coroutine scheduler. Plain
     * PHPUnit CLI has no Swoole event loop running, so `Workerman\Coroutine\Channel`
     * falls back to its non-blocking `Memory` driver, whose `push()` never
     * actually blocks regardless of the value passed here — so no test in this
     * repo can directly observe a real bounded wait. `RelayProxyBridgeTest`
     * verifies (a) the exact timeout value is passed to `push()`, and (b) the
     * drop-and-untrack behavior on a `false` return, both independent of which
     * driver is in effect; the "does this value actually bound a live block"
     * claim is verified by this docblock's API-documentation reasoning alone,
     * not by an executable test.
     */
    private const REPLY_PUSH_TIMEOUT_SECONDS = 45.0;

    /**
     * Unique-per-process channel event the relay worker publishes this worker's
     * replies on.
     *
     * @var string
     */
    private readonly string $replyEvent;

    /**
     * In-flight requests keyed by request id → the coroutine channel the
     * waiting {@see request()} call is blocked on.
     *
     * @var array<string, Channel>
     */
    private array $pending = [];

    /**
     * @var callable(string, array<string, mixed>): void
     */
    private $publisher;

    /**
     * @param StructuredLogger                                  $logger    Relay logger.
     * @param (callable(string, array<string, mixed>): void)|null $publisher Channel publisher
     *        (defaults to {@see ChannelClient::publish()}; overridable for tests).
     * @param string|null                                        $replyEvent Reply event override (tests).
     */
    public function __construct(
        private readonly StructuredLogger $logger,
        ?callable $publisher = null,
        ?string $replyEvent = null,
    ) {
        $this->publisher = $publisher ?? static function (string $event, array $data): void {
            ChannelClient::publish($event, $data);
        };
        $pid = getmypid();
        $this->replyEvent = $replyEvent
            ?? ('phlix.relay.proxy.reply.' . ($pid === false ? 0 : $pid) . '.' . bin2hex(random_bytes(4)));
    }

    /**
     * The unique reply event this worker subscribes to.
     *
     * @return string
     *
     * @since 0.10.0
     */
    public function replyEvent(): string
    {
        return $this->replyEvent;
    }

    /**
     * Proxy one request to the server over the relay tunnel and await the response.
     *
     * @param string                $serverId Target server UUID.
     * @param string                $method   HTTP method.
     * @param string                $path     Request path (no query string).
     * @param string                $query    Raw query string (no leading '?').
     * @param array<string, string> $headers  Forwarded request headers.
     * @param string                $body     Raw request body.
     * @param float                 $timeout  Seconds to wait for the response.
     *
     * @return array<string, mixed>|null The relay reply payload (status/headers/body_b64),
     *                                    or null on timeout / no response.
     *
     * @since 0.10.0
     */
    public function request(
        string $serverId,
        string $method,
        string $path,
        string $query,
        array $headers,
        string $body,
        float $timeout,
    ): ?array {
        $requestId = bin2hex(random_bytes(16));
        $channel = new Channel(1);
        $this->pending[$requestId] = $channel;

        ($this->publisher)(RelayProxyProtocol::REQUEST_EVENT, [
            'request_id' => $requestId,
            'reply_event' => $this->replyEvent,
            'server_id' => $serverId,
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'body_b64' => base64_encode($body),
            // Forward the caller's per-request ceiling so the relay worker's
            // completion timer uses the identical bound (playback-read segments
            // get the wider streaming timeout). Absent/invalid → the relay
            // worker falls back to its injected default, preserving old behaviour.
            'timeout' => $timeout,
        ]);

        try {
            /** @var mixed $result */
            $result = $channel->pop($timeout);
        } finally {
            unset($this->pending[$requestId]);
            // Wake/fail any push a late-arriving reply might still be blocked on
            // (see the docblock on {@see self::REPLY_PUSH_TIMEOUT_SECONDS}).
            $channel->close();
        }

        if (!is_array($result)) {
            $this->logger->warning('Relay proxy: timed out waiting for server response', [
                'server_id' => $serverId,
                'request_id' => $requestId,
                'path' => $path,
            ]);
            return null;
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Proxy one request and stream the server's response straight to the browser
     * as it arrives, without buffering the whole body on the hub.
     *
     * Publishes the request with a `stream` flag so the relay worker forwards the
     * response as phased channel messages (`head` → `body`* → `end`) instead of
     * reassembling and publishing one buffered blob. Each phase is delivered to
     * the given {@see RelayResponseSink} — {@see RelayResponseSink::head()} once,
     * then {@see RelayResponseSink::body()} per fragment (base64-decoded here),
     * then {@see RelayResponseSink::end()}.
     *
     * A non-phased reply is still handled: relay-worker error replies
     * (`server.no_tunnel`, timeout, tunnel-drop) and any legacy/non-streaming
     * server come through as a single buffered message, which is emitted as one
     * head + body + end. So this method degrades cleanly to the buffered result
     * on every error and BC path.
     *
     * The first phase is awaited for up to `$timeout` seconds (time-to-first-byte,
     * aligned to the server's segment-encode ceiling for `/hls`/`/dash`); once the
     * head arrives each subsequent phase is awaited for the same interval as an
     * *inactivity* bound, so a long-but-steady transfer is never cut off (this is
     * what lets a large direct-play stream run past the old 30 s total-body
     * ceiling). If {@see RelayResponseSink::body()} reports the browser gone, or a
     * phase never arrives, streaming stops.
     *
     * @param string                $serverId Target server UUID.
     * @param string                $method   HTTP method.
     * @param string                $path     Request path (no query string).
     * @param string                $query    Raw query string (no leading '?').
     * @param array<string, string> $headers  Forwarded request headers.
     * @param string                $body     Raw request body.
     * @param float                 $timeout  Per-phase wait (seconds).
     * @param RelayResponseSink     $sink     Downstream to write the response to.
     *
     * @return void
     *
     * @since 0.11.0
     */
    public function stream(
        string $serverId,
        string $method,
        string $path,
        string $query,
        array $headers,
        string $body,
        float $timeout,
        RelayResponseSink $sink,
    ): void {
        $requestId = bin2hex(random_bytes(16));
        $channel = new Channel(self::STREAM_CHANNEL_CAPACITY);
        $this->pending[$requestId] = $channel;

        ($this->publisher)(RelayProxyProtocol::REQUEST_EVENT, [
            'request_id' => $requestId,
            'reply_event' => $this->replyEvent,
            'server_id' => $serverId,
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'body_b64' => base64_encode($body),
            'timeout' => $timeout,
            // Ask the relay worker to forward the response as phased frames
            // rather than one reassembled buffered blob.
            'stream' => true,
        ]);

        // Doubles as the exception-safety signal for the `catch` below (finding
        // B, D3s re-review): once `$sink->head()` has SUCCEEDED, real bytes
        // (status line + headers) are on the wire, so any LATER exception must
        // never be allowed to reach the caller's generic buffered-error
        // fallback — a second response can never safely be written onto a
        // connection that already carries partial HTTP framing. An exception
        // raised before or during `head()` itself (which never returns, so
        // `$headSent` stays false) is still safe to rethrow: our own
        // `ConnectionResponseSink::head()` performs exactly one `send()` call as
        // its last statement in each branch, so it cannot itself leave partial
        // bytes on the wire without having returned successfully.
        $headSent = false;
        try {
            while (true) {
                /** @var mixed $message */
                $message = $channel->pop($timeout);
                if (!is_array($message)) {
                    // No phase arrived within the wait. If nothing was sent yet,
                    // synthesise a gateway timeout; otherwise close the stream.
                    if (!$headSent) {
                        $sink->head(504, ['Content-Type' => 'application/json']);
                        $headSent = true;
                        $sink->body($this->errorBody(
                            'gateway.timeout',
                            'The server did not respond over the relay in time.',
                        ));
                    }
                    $sink->end();
                    return;
                }

                /** @var array<string, mixed> $message */
                $phase = '';
                if (array_key_exists('phase', $message) && is_string($message['phase'])) {
                    $phase = $message['phase'];
                }

                if ($phase === 'body') {
                    $decoded = $this->decodeBody($message);
                    if ($decoded !== '' && $sink->body($decoded) === false) {
                        // Browser gone — stop consuming and cancel the relay
                        // request so the paired server stops transferring bytes
                        // for this now-orphaned stream. The `finally` drops the
                        // pending entry so any later phases from the relay
                        // worker are discarded by onReply().
                        ($this->publisher)(RelayProxyProtocol::CANCEL_EVENT, [
                            'request_id' => $requestId,
                            'server_id' => $serverId,
                        ]);
                        return;
                    }
                    continue;
                }

                if ($phase === 'head') {
                    $sink->head($this->replyStatus($message), $this->replyHeaders($message));
                    $headSent = true;
                    continue;
                }

                if ($phase === 'end') {
                    $sink->end();
                    return;
                }

                // No phase → a single buffered reply (error / non-streaming path):
                // emit it whole and finish.
                $sink->head($this->replyStatus($message), $this->replyHeaders($message));
                $headSent = true;
                $decoded = $this->decodeBody($message);
                if ($decoded !== '') {
                    $sink->body($decoded);
                }
                $sink->end();
                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('Relay proxy: streaming response failed mid-transfer', [
                'server_id' => $serverId,
                'path' => $path,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            if ($headSent) {
                // Real bytes (status line + headers, maybe body) are already on
                // the wire — do NOT let this exception reach a caller that
                // would send a second, buffered response over the same
                // connection (that would corrupt the wire framing). Abort the
                // sink directly instead: it force-closes the connection and
                // must not itself throw.
                $sink->abort();
                return;
            }
            // `head()` never succeeded — nothing was written yet, so it is
            // safe to propagate this exception. In PRACTICE, though, it will
            // NOT reach `Application::onMessage`'s outer buffered-500 handler:
            // `onMessage` wraps the streaming-producer call in its OWN
            // defense-in-depth `catch` that force-closes the connection
            // directly for ANY exception, because from that call site alone it
            // cannot distinguish "safe to send a graceful 500" (this case) from
            // "bytes may already be on the wire" (the `abort()` case above) —
            // so it treats every streaming-producer failure the same way. The
            // net effect for THIS case is a raw connection close rather than a
            // JSON 500 body; both read as "this request/fragment failed" to a
            // client (hls.js/native-HLS retries a failed segment fetch the
            // same way whether it was reset or answered with a 500), so this
            // is an accepted simplification, not a defect — see
            // `Application::onMessage`'s matching comment and
            // `quality_worklog.md` D3s round-3 (Finding D).
            throw $e;
        } finally {
            unset($this->pending[$requestId]);
            // Close the channel as soon as this coroutine stops consuming it —
            // whether it finished normally or gave up early (browser gone,
            // timeout, or the exception path above). Closing wakes any push
            // {@see self::onReply()} is CURRENTLY blocked on (returns false
            // immediately, per `Workerman\Coroutine\Channel::close()`), and
            // makes every future push on this channel fail fast instead of
            // hanging — fixing the resource leak a stuck consumer would
            // otherwise cause.
            $channel->close();
        }
    }

    /**
     * Extract the HTTP status from a reply/phase message.
     *
     * @param array<string, mixed> $message
     *
     * @return int
     */
    private function replyStatus(array $message): int
    {
        if (array_key_exists('status', $message) && is_int($message['status'])) {
            return $message['status'];
        }
        return 502;
    }

    /**
     * Extract the header map from a reply/phase message.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, string>
     */
    private function replyHeaders(array $message): array
    {
        $raw = $message['headers'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        /** @var mixed $value */
        foreach ($raw as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /**
     * Extract the raw body of a reply/phase message.
     *
     * @param array<string, mixed> $message
     *
     * @return string Raw body bytes ('' when absent).
     */
    private function decodeBody(array $message): string
    {
        $body = $message['body'] ?? null;
        if (!is_string($body) || $body === '') {
            return '';
        }
        return $body;
    }

    /**
     * Build a JSON error body.
     *
     * @param string $code    Machine error code.
     * @param string $message Human-readable message.
     *
     * @return string JSON.
     */
    private function errorBody(string $code, string $message): string
    {
        try {
            return json_encode(['error' => $message, 'code' => $code], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{"error":"relay error"}';
        }
    }

    /**
     * Deliver a relay reply (or, for a streaming request, one phase of it) to
     * the waiting request coroutine.
     *
     * Wired as the {@see replyEvent()} subscriber in the worker's
     * `onWorkerStart`. This is the SINGLE shared subscriber for every in-flight
     * request on this worker (see {@see self::REPLY_PUSH_TIMEOUT_SECONDS}), so
     * the push is bounded: if it cannot complete within the bound — the
     * consumer is gone/stuck and its channel is either closed (push fails
     * immediately) or genuinely full for too long — the reply is dropped and
     * this request id stops being tracked, rather than stalling delivery to
     * every OTHER in-flight request on this worker indefinitely.
     *
     * @param mixed $data The published reply/phase payload.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function onReply(mixed $data): void
    {
        if (!is_array($data)) {
            return;
        }

        /** @var mixed $requestId */
        $requestId = $data['request_id'] ?? null;
        if (!is_string($requestId)) {
            return;
        }

        $channel = $this->pending[$requestId] ?? null;
        if ($channel === null) {
            // Already timed out/closed and removed — drop the late reply.
            return;
        }

        /** @var array<string, mixed> $data */
        if ($channel->push($data, self::REPLY_PUSH_TIMEOUT_SECONDS) === false) {
            // The consumer never drained (closed channel, or genuinely stuck
            // beyond the bound) — drop this payload and stop tracking the
            // request so any further replies for it are dropped immediately
            // too, instead of retrying the same doomed push.
            unset($this->pending[$requestId]);
            $this->logger->warning('Relay proxy: dropped reply — consumer channel unavailable', [
                'request_id' => $requestId,
            ]);
        }
    }
}
