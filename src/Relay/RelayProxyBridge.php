<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Channel\Client as ChannelClient;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\Coroutine\Channel;

use function base64_encode;
use function bin2hex;
use function getmypid;
use function is_array;
use function random_bytes;

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
        $this->replyEvent = $replyEvent
            ?? ('phlix.relay.proxy.reply.' . (getmypid() ?: 0) . '.' . bin2hex(random_bytes(4)));
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
        ]);

        try {
            /** @var mixed $result */
            $result = $channel->pop($timeout);
        } finally {
            unset($this->pending[$requestId]);
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
     * Deliver a relay reply to the waiting request coroutine.
     *
     * Wired as the {@see replyEvent()} subscriber in the worker's
     * `onWorkerStart`. Runs in the event-loop coroutine; pushing into the
     * capacity-1 channel returns immediately.
     *
     * @param mixed $data The published reply payload.
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
            // Already timed out and removed — drop the late reply.
            return;
        }

        /** @var array<string, mixed> $data */
        $channel->push($data);
    }
}
