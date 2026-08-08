<?php

/**
 * Phlix hub component: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\SyncPlay;

use Channel\Client as ChannelClient;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\Coroutine\Channel;

use function bin2hex;
use function getmypid;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function random_bytes;

/**
 * HTTP-worker side of the cross-process pending-command push (S93).
 *
 * Lives in each HTTP worker process. {@see pushPlayMedia()} publishes the command
 * to the `:8804` SyncPlay relay worker (which owns the client sockets) over the
 * `workerman/channel` broker and blocks the calling coroutine on a per-request
 * channel until the DELIVERED COUNT arrives on this worker's unique reply event —
 * or {@see PendingCommandProtocol::REPLY_TIMEOUT_SECONDS} elapses.
 * {@see onReply()} (wired as the reply-event subscriber in the worker's
 * `onWorkerStart`) hands the count to the waiting coroutine.
 *
 * Modelled directly on {@see \Phlix\Hub\Relay\RelayProxyBridge}, which crosses the
 * identical process boundary for the HTTP-over-relay proxy. The Workerman Swoole
 * event loop runs every callback inside a coroutine, so {@see Channel::pop()}
 * suspends only this request's coroutine (never the event loop), and the reply
 * callback's push into a capacity-1 channel returns immediately.
 *
 * ## Every failure returns 0, deliberately, and that is the SAFE direction
 *
 * No broker, no subscriber, no reply within the timeout, a reply of the wrong
 * shape, a negative count: each returns `0`. Nothing here throws for an ordinary
 * failure. `0` makes the Alexa skill say "open the Phlix app" — an answer that is
 * true whenever it is spoken, since the frame demonstrably reached no socket.
 * The opposite error, over-claiming, would confirm a command nobody received and
 * leave a user watching a screen that never changes. Under-claiming is recoverable
 * (the user opens the app and asks again); over-claiming is not.
 *
 * @package Phlix\Hub\SyncPlay
 * @since   S93 (Alexa: play in an already-open app)
 */
final class ChannelPendingCommandPusher implements PendingCommandPusherInterface
{
    /**
     * Unique-per-process channel event the `:8804` worker publishes this worker's
     * delivered-count replies on.
     */
    private readonly string $replyEvent;

    /**
     * In-flight pushes keyed by request id → the coroutine channel the waiting
     * {@see pushPlayMedia()} call is blocked on.
     *
     * Bounded by construction: every entry is removed in the `finally` of the call
     * that created it, so this map can never grow without bound in a resident
     * worker.
     *
     * @var array<string, Channel>
     */
    private array $pending = [];

    /**
     * @var callable(string, array<string, mixed>): void
     */
    private $publisher;

    /**
     * @param StructuredLogger                                    $logger     Relay logger.
     * @param (callable(string, array<string, mixed>): void)|null $publisher  Channel publisher
     *        (defaults to {@see ChannelClient::publish()}; overridable for tests).
     * @param string|null                                         $replyEvent Reply event override (tests).
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
            ?? ('phlix.syncplay.pending_command.reply.' . ($pid === false ? 0 : $pid)
                . '.' . bin2hex(random_bytes(4)));
    }

    /**
     * The unique reply event this worker subscribes to.
     *
     * ⚠ Must be subscribed EXACTLY ONCE per worker, against the SAME instance the
     * request path resolves — see the singleton note on this class's container
     * binding. A second instance would subscribe to an event nobody publishes on
     * and every push would time out to 0.
     */
    public function replyEvent(): string
    {
        return $this->replyEvent;
    }

    /**
     * Ask the `:8804` worker to write a `play_media` frame to this user's open apps.
     *
     * @param string $userId   Hub user id the command is addressed to.
     * @param string $serverId Server the media id belongs to.
     * @param string $mediaId  Media id to start.
     * @param string $title    Human title, for the app's own UI.
     *
     * @return int Sockets actually written to; `0` means nobody received it.
     */
    public function pushPlayMedia(string $userId, string $serverId, string $mediaId, string $title): int
    {
        $requestId = bin2hex(random_bytes(16));
        $channel = new Channel(1);
        $this->pending[$requestId] = $channel;

        ($this->publisher)(PendingCommandProtocol::PUSH_EVENT, [
            'request_id' => $requestId,
            'reply_event' => $this->replyEvent,
            'user_id' => $userId,
            'server_id' => $serverId,
            'media_id' => $mediaId,
            'title' => $title,
        ]);

        try {
            /** @var mixed $reply */
            $reply = $channel->pop(PendingCommandProtocol::REPLY_TIMEOUT_SECONDS);
        } finally {
            unset($this->pending[$requestId]);
            // Wake/fail any push a late-arriving reply might still be blocked on.
            $channel->close();
        }

        if (!is_array($reply)) {
            // Timed out, or the broker/subscriber is not there at all. Not an
            // error the user should hear as a failure: the caller speaks the
            // honest "no open app" answer, which is true either way.
            $this->logger->warning('SyncPlay pending command: no delivered-count reply', [
                'request_id' => $requestId,
                'user_id' => $userId,
                'server_id' => $serverId,
            ]);

            return 0;
        }

        /** @var mixed $delivered */
        $delivered = $reply['delivered'] ?? null;
        if (is_int($delivered)) {
            return $delivered < 0 ? 0 : $delivered;
        }
        if (is_string($delivered) && is_numeric($delivered)) {
            $count = (int) $delivered;

            return $count < 0 ? 0 : $count;
        }

        $this->logger->warning('SyncPlay pending command: reply carried no usable delivered count', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'server_id' => $serverId,
        ]);

        return 0;
    }

    /**
     * Deliver a delivered-count reply to the waiting push coroutine.
     *
     * Wired as the {@see replyEvent()} subscriber in the worker's
     * `onWorkerStart`. Guarded exactly as
     * {@see \Phlix\Hub\Relay\RelayProxyBridge::onReply()} is: a non-array payload
     * or a missing/non-string `request_id` is dropped, and an UNKNOWN request id is
     * dropped SILENTLY — that is a late reply for a push whose coroutine already
     * gave up and untracked itself, so there is nobody to hand it to and nothing
     * has gone wrong.
     *
     * The push is non-blocking (capacity-1 channel, one waiter, at most one reply),
     * so this single shared subscriber can never stall the other in-flight pushes
     * on this worker.
     *
     * @param mixed $data The published reply payload.
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
        $channel->push($data, 0.0);
    }
}
