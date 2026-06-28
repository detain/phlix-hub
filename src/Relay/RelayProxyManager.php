<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Channel\Client as ChannelClient;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Shared\Relay\RelayHttpResponseChunk;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use Throwable;
use Workerman\Timer;

use function base64_decode;
use function base64_encode;
use function is_array;
use function is_string;
use function json_encode;
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
     * @var int Next request id to allocate.
     */
    private int $nextRequestId = self::FIRST_REQUEST_ID;

    /**
     * In-flight proxy requests keyed by request id.
     *
     * @var array<int, array{
     *     reply_event: string,
     *     request_id: string,
     *     server_id: string,
     *     head: RelayHttpResponseHead|null,
     *     body: string,
     *     timer: int|null
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
     * @param int                                                 $timeoutSeconds Per-request timeout.
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

        $replyEvent = is_string($data['reply_event'] ?? null) ? $data['reply_event'] : '';
        $clientRequestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : '';
        $serverId = is_string($data['server_id'] ?? null) ? $data['server_id'] : '';

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

        $headers = [];
        if (isset($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $headers[$name] = $value;
                }
            }
        }

        $bodyB64 = is_string($data['body_b64'] ?? null) ? $data['body_b64'] : '';
        $body = $bodyB64 === '' ? '' : (base64_decode($bodyB64, true) ?: '');

        $envelope = new RelayHttpRequest(
            is_string($data['method'] ?? null) ? $data['method'] : 'GET',
            is_string($data['path'] ?? null) ? $data['path'] : '/',
            is_string($data['query'] ?? null) ? $data['query'] : '',
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

        if (strlen($json) > 65535) {
            $this->reply(
                $replyEvent,
                $clientRequestId,
                413,
                [],
                $this->errorBody('relay.request_too_large', 'Relayed request exceeds the 65535-byte frame limit.'),
            );
            return;
        }

        $requestId = $this->allocateRequestId();
        $timerId = null;
        try {
            $timerId = Timer::add($this->timeoutSeconds, function () use ($requestId): void {
                $this->onTimeout($requestId);
            }, [], false);
        } catch (Throwable) {
            // Timer unavailable (e.g. outside the event loop / tests) — proceed
            // without a timeout guard.
            $timerId = null;
        }

        $this->pending[$requestId] = [
            'reply_event' => $replyEvent,
            'request_id' => $clientRequestId,
            'server_id' => $serverId,
            'head' => null,
            'body' => '',
            'timer' => is_int($timerId) ? $timerId : null,
        ];

        $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_REQUEST, $requestId, $json));

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

        if ($chunk->kind === RelayHttpResponseChunk::KIND_HEAD) {
            $this->pending[$requestId]['head'] = $chunk->head;
            return;
        }

        if ($chunk->kind === RelayHttpResponseChunk::KIND_BODY) {
            $this->pending[$requestId]['body'] .= $chunk->body;
            return;
        }

        // KIND_END — assemble and publish.
        $entry = $this->pending[$requestId];
        $head = $entry['head'];
        $status = $head !== null ? $head->status : 502;
        $headers = $head !== null ? $head->headers : [];

        $this->cancelTimer($requestId);
        unset($this->pending[$requestId]);

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
            'body_b64' => base64_encode($body),
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
}
