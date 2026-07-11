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
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use Phlix\Shared\Relay\RelayHttpResponseChunk;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use Throwable;
use Workerman\Timer;

use function base64_decode;
use function base64_encode;
use function is_array;
use function is_numeric;
use function is_string;
use function json_encode;
use function microtime;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Relay-ws-worker side of the cross-process HTTP proxy.
 *
 * Lives in the single relay worker process (the one that owns the server
 * tunnels). It subscribes to {@see RelayProxyProtocol::REQUEST_EVENT}; for each
 * proxy request it allocates a per-request id, sends an
 * {@see RelayFrameType::HTTP_REQUEST} frame down the matching tunnel, then
 * reassembles the streamed {@see RelayFrameType::HTTP_RESPONSE} chunks
 * (HEAD → BODY* → END) and publishes the finished response back to the
 * originating HTTP worker on its `reply_event`.
 *
 * @package Phlix\Hub\Relay
 * @since 0.10.0
 */
final class RelayProxyManager
{
    /**
     * First request id. Allocated from a high range so they never clash with
     * the low, monotonically-increasing client channel ids on the same tunnel.
     */
    private const FIRST_REQUEST_ID = 0x80000001;

    /** Wrap-around ceiling for request ids (stays within uint32). */
    private const MAX_REQUEST_ID = 0xFFFFFFFF;

    /**
     * Minimum seconds between completion-timer re-arms for a streaming request.
     * The timer is re-armed on response activity so it behaves as an *inactivity*
     * bound rather than a total-transfer one, but re-arming per ~64 KB frame
     * would churn the event loop; this throttles it to at most once per interval.
     */
    private const STREAM_TIMER_REARM_THROTTLE_SECONDS = 1.0;

    /**
     * Absolute ceiling (seconds) on how long a single streaming response may
     * remain open, regardless of activity.
     *
     * {@see self::touchStreamTimer()} re-arms the completion timer on every
     * response frame, so the per-frame bound alone is a pure *inactivity*
     * timeout — a steadily-dripping origin (a pathologically slow encode, or a
     * compromised/misbehaving paired server sending one byte every
     * `<timeout>s`) could otherwise keep re-arming it forever and hold the
     * browser connection, this pending entry, and the underlying server-side
     * transfer open indefinitely. This is defense-in-depth on top of that: once
     * a stream has been open this long it is terminated even if frames are
     * still actively arriving. Deliberately generous (15 minutes — well beyond
     * any real HLS/DASH segment or direct-play transfer) so it never clips a
     * legitimate slow-but-finite transfer; it exists purely to bound the
     * pathological/malicious case. Low risk in today's threat model (the origin
     * is the authenticated owner's own paired server), so a generous bound is
     * appropriate.
     */
    private const MAX_STREAM_DURATION_SECONDS = 900.0;

    /**
     * @var int Next request id to allocate.
     */
    private int $nextRequestId = self::FIRST_REQUEST_ID;

    /**
     * In-flight proxy requests keyed by request id.
     *
     * @todo Cancel-frame gap, deliberately deferred (D3s round 1 Finding 4):
     *       when a streaming entry's browser side is abandoned,
     *       `RelayProxyBridge::stream()` closes its own channel but this entry
     *       stays pending here and frames keep being published (into what is
     *       now a closed channel, dropped at O(1) cost — no leak) until the
     *       origin sends END or an inactivity/absolute-duration ceiling fires.
     *       There is currently no cancel signal to tell the tunnel/paired
     *       server to stop transferring early — only a wasted-bandwidth/CPU
     *       cost, bounded by those same ceilings, not a correctness issue. A
     *       future lightweight `HTTP_CANCEL {requestId}` relay frame (emitted
     *       from the bridge's "browser gone" branch) would let this entry be
     *       torn down immediately instead of waiting out a ceiling. See
     *       `quality_worklog.md` D3s round-1 Finding 4 disposition.
     *
     * @var array<int, array{
     *     reply_event: string,
     *     request_id: string,
     *     server_id: string,
     *     head: RelayHttpResponseHead|null,
     *     body: string,
     *     timer: int|null,
     *     stream: bool,
     *     stream_started: bool,
     *     timeout: float,
     *     timer_armed_at: float,
     *     stream_opened_at: float
     * }>
     */
    private array $pending = [];

    /**
     * @var callable(string, array<string, mixed>): void
     */
    private $publisher;

    /**
     * @param TunnelManagerInterface                              $tunnelManager Tunnel registry (lookup + send).
     * @param StructuredLogger                                    $logger        Relay logger.
     * @param int                                                 $timeoutSeconds Fallback
     *        completion-timer timeout (seconds), used only when a published
     *        request's `timeout` field ({@see asTimeout()}) is absent or
     *        non-positive.
     * @param (callable(string, array<string, mixed>): void)|null $publisher     Channel publisher
     *        (defaults to {@see ChannelClient::publish()}; overridable for tests).
     */
    public function __construct(
        private readonly TunnelManagerInterface $tunnelManager,
        private readonly StructuredLogger $logger,
        private readonly int $timeoutSeconds = RelayProxyProtocol::DEFAULT_TIMEOUT_SECONDS,
        ?callable $publisher = null,
    ) {
        $this->publisher = $publisher ?? static function (string $event, array $data): void {
            ChannelClient::publish($event, $data);
        };
    }

    /**
     * Handle a proxy request published by an HTTP worker.
     *
     * @param mixed $data The published request payload.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function onRequest(mixed $data): void
    {
        if (!is_array($data)) {
            return;
        }

        $replyEvent = self::asString($data['reply_event'] ?? null);
        $clientRequestId = self::asString($data['request_id'] ?? null);
        $serverId = self::asString($data['server_id'] ?? null);

        if ($replyEvent === '' || $clientRequestId === '' || $serverId === '') {
            $this->logger->warning('Relay proxy: malformed proxy request payload');
            return;
        }

        // Authoritative request-time liveness cross-check. The in-memory tunnel
        // registry — owned by this relay worker — is the source of truth for
        // proxy admission, NOT the `relay_active` DB flag the HTTP worker saw
        // (which can be stale after a crash/restart). If there is no live,
        // active tunnel for this server we fail fast with 503 here rather than
        // letting the request hang and time out into a 504 downstream. The
        // distinct `server.no_tunnel` code marks this as the registry verdict.
        $tunnel = $this->tunnelManager->getTunnelForServer($serverId);
        if ($tunnel === null || $tunnel->getStatus() !== Tunnel::STATUS_ACTIVE) {
            $this->reply(
                $replyEvent,
                $clientRequestId,
                503,
                [],
                $this->errorBody('server.no_tunnel', 'No live relay tunnel for this server.'),
            );
            return;
        }

        $headers = self::stringMap($data['headers'] ?? null);

        $bodyB64 = self::asString($data['body_b64'] ?? null);
        $decoded = $bodyB64 === '' ? '' : base64_decode($bodyB64, true);
        $body = is_string($decoded) ? $decoded : '';

        $envelope = new RelayHttpRequest(
            self::asString($data['method'] ?? null, 'GET'),
            self::asString($data['path'] ?? null, '/'),
            self::asString($data['query'] ?? null, ''),
            $headers,
            $body,
        );

        try {
            $json = $envelope->toJson();
        } catch (Throwable $e) {
            $this->reply(
                $replyEvent,
                $clientRequestId,
                500,
                [],
                $this->errorBody('relay.encode_error', $e->getMessage()),
            );
            return;
        }

        $requestId = $this->allocateRequestId();
        // Per-request completion ceiling forwarded by the HTTP worker so this
        // timer matches the browser-facing wait (playback-read segments carry
        // the wider streaming timeout). Absent/invalid → the injected default.
        $timeout = $this->asTimeout($data['timeout'] ?? null);
        // Streaming requests forward the response as phased frames (head/body/
        // end) instead of one reassembled blob, so the hub never buffers the
        // whole body. Absent/false → the historical buffered behaviour.
        $stream = ($data['stream'] ?? null) === true;
        $timerId = null;
        try {
            $timerId = Timer::add($timeout, function () use ($requestId): void {
                $this->onTimeout($requestId);
            }, [], false);
        } catch (Throwable) {
            // Timer unavailable (e.g. outside the event loop / tests) — proceed
            // without a timeout guard.
            $timerId = null;
        }

        $now = microtime(true);
        $this->pending[$requestId] = [
            'reply_event' => $replyEvent,
            'request_id' => $clientRequestId,
            'server_id' => $serverId,
            'head' => null,
            'body' => '',
            'timer' => is_int($timerId) ? $timerId : null,
            'stream' => $stream,
            'stream_started' => false,
            'timeout' => $timeout,
            'timer_armed_at' => $now,
            'stream_opened_at' => $now,
        ];

        // Use chunked sending when the body is large enough that the
        // base64-encoded JSON would exceed the 65535-byte frame limit.
        // For empty bodies or small encoded sizes, send as a single frame
        // for backwards compatibility.
        if ($body === '' || strlen($json) <= 65535) {
            $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_REQUEST, $requestId, $json));
        } else {
            // Chunked path: HEAD + BODY chunks + END
            $head = new RelayHttpRequestHead(
                $envelope->method,
                $envelope->path,
                $envelope->query,
                $headers,
            );
            $head = $head->withBodySize(strlen($body));

            $tunnel->sendToServer(new RelayFrame(
                RelayFrameType::HTTP_REQUEST,
                $requestId,
                RelayHttpRequestCodec::encodeHead($head),
            ));

            foreach (RelayHttpRequestCodec::chunkBodyIterator($body) as $bodyChunk) {
                $tunnel->sendToServer(new RelayFrame(
                    RelayFrameType::HTTP_REQUEST,
                    $requestId,
                    $bodyChunk,
                ));
            }

            $tunnel->sendToServer(new RelayFrame(
                RelayFrameType::HTTP_REQUEST,
                $requestId,
                RelayHttpRequestCodec::encodeEnd(),
            ));
        }

        $this->logger->info('Relay proxy: forwarded request to server', [
            'server_id' => $serverId,
            'request_id' => $requestId,
            'method' => $envelope->method,
            'path' => $envelope->path,
        ]);
    }

    /**
     * Handle an HTTP_RESPONSE frame arriving from a server tunnel.
     *
     * @param RelayFrame $frame The HTTP_RESPONSE frame (request id in seq).
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function onResponseFrame(RelayFrame $frame): void
    {
        $requestId = $frame->channelId();
        if (!isset($this->pending[$requestId])) {
            $this->logger->warning('Relay proxy: HTTP_RESPONSE for unknown/closed request, dropping', [
                'request_id' => $requestId,
            ]);
            return;
        }

        try {
            $chunk = RelayHttpResponseCodec::decode($frame->payload);
        } catch (Throwable $e) {
            $this->logger->warning('Relay proxy: malformed HTTP_RESPONSE chunk', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $streaming = $this->pending[$requestId]['stream'];

        if ($chunk->kind === RelayHttpResponseChunk::KIND_HEAD) {
            if ($streaming) {
                $head = $chunk->head;
                $this->pending[$requestId]['stream_started'] = true;
                $this->publishPhaseHead(
                    $this->pending[$requestId]['reply_event'],
                    $this->pending[$requestId]['request_id'],
                    $head !== null ? $head->status : 502,
                    $head !== null ? $head->headers : [],
                );
                $this->touchStreamTimer($requestId);
                return;
            }
            $this->pending[$requestId]['head'] = $chunk->head;
            return;
        }

        if ($chunk->kind === RelayHttpResponseChunk::KIND_BODY) {
            if ($streaming) {
                $this->publishPhaseBody(
                    $this->pending[$requestId]['reply_event'],
                    $this->pending[$requestId]['request_id'],
                    $chunk->body,
                );
                $this->touchStreamTimer($requestId);
                return;
            }
            $this->pending[$requestId]['body'] .= $chunk->body;
            return;
        }

        // KIND_END.
        $entry = $this->pending[$requestId];
        $this->cancelTimer($requestId);
        unset($this->pending[$requestId]);

        if ($streaming) {
            $this->publishPhaseEnd($entry['reply_event'], $entry['request_id']);
            $this->logger->info('Relay proxy: completed streamed response from server', [
                'request_id' => $requestId,
            ]);
            return;
        }

        // Buffered path — assemble and publish the whole body at once.
        $head = $entry['head'];
        $status = $head !== null ? $head->status : 502;
        $headers = $head !== null ? $head->headers : [];

        $this->reply($entry['reply_event'], $entry['request_id'], $status, $headers, $entry['body']);

        $this->logger->info('Relay proxy: completed response from server', [
            'request_id' => $requestId,
            'status' => $status,
            'body_len' => strlen($entry['body']),
        ]);
    }

    /**
     * Fail all in-flight requests for a server whose tunnel just dropped.
     *
     * @param string $serverId The server whose tunnel closed.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function failServer(string $serverId): void
    {
        foreach ($this->pending as $requestId => $entry) {
            if ($entry['server_id'] !== $serverId) {
                continue;
            }
            $this->cancelTimer($requestId);
            unset($this->pending[$requestId]);
            if ($entry['stream'] && $entry['stream_started']) {
                // The head (and some body) already reached the browser — a fresh
                // 503 body cannot be substituted, so just terminate the stream.
                $this->publishPhaseEnd($entry['reply_event'], $entry['request_id']);
                continue;
            }
            $this->reply(
                $entry['reply_event'],
                $entry['request_id'],
                503,
                [],
                $this->errorBody('server.offline', 'The relay tunnel closed before the response completed.'),
            );
        }
    }

    /**
     * Cancel an in-flight request and signal the server to stop transferring.
     *
     * Called when the browser abandons a streaming request so the server can
     * stop CPU/bandwidth work on a request whose response can no longer be
     * delivered. The pending entry is removed immediately; any subsequent
     * frames from the server for this request are dropped at O(1) cost.
     *
     * @param int $requestId The hub-assigned relay request id to cancel.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function cancelRequest(int $requestId): void
    {
        $entry = $this->pending[$requestId] ?? null;
        if ($entry === null) {
            // Already timed out / completed / cancelled — nothing to do.
            return;
        }

        $this->cancelTimer($requestId);
        unset($this->pending[$requestId]);

        $tunnel = $this->tunnelManager->getTunnelForServer($entry['server_id']);
        if ($tunnel !== null && $tunnel->getStatus() === Tunnel::STATUS_ACTIVE) {
            $tunnel->sendCancel($requestId);
        }

        $this->logger->info('Relay proxy: request cancelled', [
            'request_id' => $requestId,
            'server_id' => $entry['server_id'],
            'stream' => $entry['stream'],
            'stream_started' => $entry['stream_started'],
        ]);
    }

    /**
     * Handle a CANCEL_EVENT from an HTTP worker (browser abandoned the request).
     *
     * The published payload carries `server_id` and the HTTP-worker's internal
     * `request_id` (the client's `bin2hex(random_bytes(16))`), not the hub's
     * relay request id. We look up the pending entry by that client request id
     * to find the relay request id, then call {@see cancelRequest()}.
     *
     * @param mixed $data The published cancel payload.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function onCancel(mixed $data): void
    {
        if (!is_array($data)) {
            return;
        }

        $clientRequestId = self::asString($data['request_id'] ?? null);
        $serverId = self::asString($data['server_id'] ?? null);

        if ($clientRequestId === '' || $serverId === '') {
            $this->logger->warning('Relay proxy: malformed cancel payload');
            return;
        }

        // Find the pending entry by client request id.
        $relayRequestId = null;
        foreach ($this->pending as $id => $entry) {
            if ($entry['request_id'] === $clientRequestId && $entry['server_id'] === $serverId) {
                $relayRequestId = $id;
                break;
            }
        }

        if ($relayRequestId === null) {
            $this->logger->debug('Relay proxy: cancel for unknown/completed request, dropping', [
                'client_request_id' => $clientRequestId,
                'server_id' => $serverId,
            ]);
            return;
        }

        $this->cancelRequest($relayRequestId);
    }

    /**
     * Time out an in-flight request that never completed.
     *
     * @param int $requestId The relay request id.
     *
     * @return void
     */
    private function onTimeout(int $requestId): void
    {
        $entry = $this->pending[$requestId] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->pending[$requestId]);

        if ($entry['stream'] && $entry['stream_started']) {
            // Head already streamed to the browser — terminate the stream rather
            // than emit a fresh 504 body. This fires as an *inactivity* cutoff:
            // the timer is re-armed on each response frame, so it only trips when
            // the server genuinely stalls mid-transfer.
            $this->logger->warning('Relay proxy: streamed response stalled, terminating', [
                'request_id' => $requestId,
                'server_id' => $entry['server_id'],
            ]);
            $this->publishPhaseEnd($entry['reply_event'], $entry['request_id']);
            return;
        }

        $this->logger->warning('Relay proxy: request timed out awaiting server', [
            'request_id' => $requestId,
            'server_id' => $entry['server_id'],
        ]);
        $this->reply(
            $entry['reply_event'],
            $entry['request_id'],
            504,
            [],
            $this->errorBody('gateway.timeout', 'The server did not respond in time.'),
        );
    }

    /**
     * Re-arm the completion timer for a streaming request so it behaves as an
     * inactivity cutoff (reset on each response frame) instead of a total-transfer
     * ceiling — this is what lets a large, steadily-flowing body stream past the
     * old fixed timeout. Throttled to at most once per
     * {@see self::STREAM_TIMER_REARM_THROTTLE_SECONDS} so per-frame churn stays
     * off the event loop.
     *
     * Also enforces {@see self::MAX_STREAM_DURATION_SECONDS} as an absolute
     * ceiling: a stream that has been open that long is terminated here
     * (instead of re-armed) even though frames are still actively arriving —
     * defense-in-depth against a steadily-dripping origin that would otherwise
     * keep resetting the pure inactivity bound forever.
     *
     * @param int $requestId The relay request id.
     *
     * @return void
     */
    private function touchStreamTimer(int $requestId): void
    {
        if (!isset($this->pending[$requestId])) {
            return;
        }
        $entry = $this->pending[$requestId];
        $now = microtime(true);

        if ($now - $entry['stream_opened_at'] >= self::MAX_STREAM_DURATION_SECONDS) {
            $this->cancelTimer($requestId);
            unset($this->pending[$requestId]);
            $this->logger->warning('Relay proxy: streamed response exceeded absolute max duration, terminating', [
                'request_id' => $requestId,
                'server_id' => $entry['server_id'],
            ]);
            $this->publishPhaseEnd($entry['reply_event'], $entry['request_id']);
            return;
        }

        if ($now - $entry['timer_armed_at'] < self::STREAM_TIMER_REARM_THROTTLE_SECONDS) {
            return;
        }

        $this->cancelTimer($requestId);
        $timerId = null;
        try {
            $timerId = Timer::add($entry['timeout'], function () use ($requestId): void {
                $this->onTimeout($requestId);
            }, [], false);
        } catch (Throwable) {
            $timerId = null;
        }
        // Reassign the whole entry (not a partial sub-key write) so the array
        // shape is preserved for static analysis after the intervening calls.
        $entry['timer'] = is_int($timerId) ? $timerId : null;
        $entry['timer_armed_at'] = $now;
        $this->pending[$requestId] = $entry;
    }

    /**
     * Publish a streaming HEAD phase back to the originating HTTP worker.
     *
     * @param string                $replyEvent      Per-request reply event.
     * @param string                $clientRequestId The HTTP worker's request id.
     * @param int                   $status          HTTP status code.
     * @param array<string, string> $headers         Response headers.
     *
     * @return void
     */
    private function publishPhaseHead(string $replyEvent, string $clientRequestId, int $status, array $headers): void
    {
        ($this->publisher)($replyEvent, [
            'request_id' => $clientRequestId,
            'phase' => 'head',
            'status' => $status,
            'headers' => $headers,
        ]);
    }

    /**
     * Publish a streaming BODY phase (one fragment) back to the HTTP worker.
     *
     * @param string $replyEvent      Per-request reply event.
     * @param string $clientRequestId The HTTP worker's request id.
     * @param string $chunk           Raw body fragment.
     *
     * @return void
     */
    private function publishPhaseBody(string $replyEvent, string $clientRequestId, string $chunk): void
    {
        ($this->publisher)($replyEvent, [
            'request_id' => $clientRequestId,
            'phase' => 'body',
            'body' => $chunk,
        ]);
    }

    /**
     * Publish the streaming END phase back to the HTTP worker.
     *
     * @param string $replyEvent      Per-request reply event.
     * @param string $clientRequestId The HTTP worker's request id.
     *
     * @return void
     */
    private function publishPhaseEnd(string $replyEvent, string $clientRequestId): void
    {
        ($this->publisher)($replyEvent, [
            'request_id' => $clientRequestId,
            'phase' => 'end',
        ]);
    }

    /**
     * Publish an assembled response back to the originating HTTP worker.
     *
     * @param string                $replyEvent      Per-request reply event.
     * @param string                $clientRequestId The HTTP worker's request id.
     * @param int                   $status          HTTP status code.
     * @param array<string, string> $headers         Response headers.
     * @param string                $body            Raw response body.
     *
     * @return void
     */
    private function reply(string $replyEvent, string $clientRequestId, int $status, array $headers, string $body): void
    {
        ($this->publisher)($replyEvent, [
            'request_id' => $clientRequestId,
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ]);
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
        } catch (Throwable) {
            return '{"error":"relay error"}';
        }
    }

    /**
     * Cancel the timeout timer for a request, if any.
     *
     * @param int $requestId The relay request id.
     *
     * @return void
     */
    private function cancelTimer(int $requestId): void
    {
        $timerId = $this->pending[$requestId]['timer'] ?? null;
        if (is_int($timerId)) {
            try {
                Timer::del($timerId);
            } catch (Throwable) {
                // ignore
            }
        }
    }

    /**
     * Allocate the next request id, wrapping within the high uint32 range.
     *
     * @return int
     */
    private function allocateRequestId(): int
    {
        $id = $this->nextRequestId;
        $this->nextRequestId = $this->nextRequestId >= self::MAX_REQUEST_ID
            ? self::FIRST_REQUEST_ID
            : $this->nextRequestId + 1;

        return $id;
    }

    /**
     * Coerce a mixed value from the untyped published payload into a string,
     * falling back to the given default when it is not a string.
     *
     * @param mixed  $value   The raw payload field.
     * @param string $default Value to use when $value is not a string.
     *
     * @return string
     */
    private static function asString(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Coerce the per-request timeout from the published payload into a positive
     * float, falling back to this worker's injected default when the field is
     * absent or non-positive.
     *
     * Keeps the completion timer aligned with the HTTP worker's browser-facing
     * wait: a playback-read segment carries the wider streaming ceiling, while a
     * legacy HTTP worker that omits the field (or sends garbage) keeps the
     * historical default and behaviour is unchanged.
     *
     * @param mixed $value The raw payload `timeout` field.
     *
     * @return float Seconds for the completion timer.
     */
    private function asTimeout(mixed $value): float
    {
        if (is_numeric($value)) {
            $seconds = (float) $value;
            if ($seconds > 0.0) {
                return $seconds;
            }
        }

        return (float) $this->timeoutSeconds;
    }

    /**
     * Coerce a mixed value into a string→string map, dropping any entry whose
     * key or value is not a string.
     *
     * @param mixed $value The raw payload field (expected to be a map).
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        /** @var mixed $item */
        foreach ($value as $name => $item) {
            if (is_string($name) && is_string($item)) {
                $out[$name] = $item;
            }
        }

        return $out;
    }
}
