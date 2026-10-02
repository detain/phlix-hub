<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Channel\Client as ChannelClient;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Throwable;
use Workerman\Coroutine\Channel;

use function bin2hex;
use function getmypid;
use function is_array;
use function is_bool;
use function is_string;
use function random_bytes;

/**
 * HTTP-worker side of the cross-process federation master push.
 *
 * Lives in each HTTP worker process. {@see dispatch()} publishes a push command
 * ({@see FederationPushProtocol::COMMAND_EVENT}) to the `:8805` FederationWorker —
 * the process that owns the leaf's live WebSocket and its H-4 verified stamp —
 * and blocks the calling coroutine on a per-request channel until the DELIVERED
 * bool arrives on this worker's unique reply event, or
 * {@see FederationPushProtocol::REPLY_TIMEOUT_SECONDS} elapses.
 * {@see onReply()} (wired as the reply-event subscriber in the HTTP worker's
 * `onWorkerStart`) hands the bool to the waiting coroutine.
 *
 * Modelled on {@see \Phlix\Hub\SyncPlay\ChannelPendingCommandPusher}, which
 * crosses the identical process boundary for Alexa's pending-command push.
 *
 * ## Every failure returns false, and NOTHING here may throw at the request
 *
 * No broker, no subscriber, publish error, no reply within the timeout, a reply
 * of the wrong shape: each returns `false`. The SyncPlay sibling could let a
 * publish throw (its caller wraps the whole utterance); here the HTTP request has
 * ALREADY committed its DB truth by the time a command is published, and the leaf
 * converges on its next hello replay regardless — failing the admin request
 * because an advisory push could not be handed over would be the worse error.
 * `false` is the honest under-claim: it says "this process cannot confirm the
 * frame reached a socket", which is true in every one of those cases. Over-
 * claiming would log a delivery that never happened.
 *
 * @package Phlix\Hub\Federation
 */
final class FederationPushBridge
{
    /**
     * Unique-per-process channel event the `:8805` worker publishes this
     * worker's delivery replies on.
     */
    private readonly string $replyEvent;

    /**
     * In-flight commands keyed by request id → the coroutine channel the waiting
     * {@see dispatch()} call is blocked on.
     *
     * Bounded by construction: every entry is removed in the `finally` of the
     * call that created it, so this map can never grow without bound in a
     * resident worker.
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
            ?? ('phlix.federation.push.reply.' . ($pid === false ? 0 : $pid)
                . '.' . bin2hex(random_bytes(4)));
    }

    /**
     * The unique reply event this worker subscribes to.
     *
     * ⚠ Must be subscribed EXACTLY ONCE per worker, against the SAME instance
     * the request path resolves — see the singleton note on this class's
     * container binding. A second instance would subscribe to an event nobody
     * publishes on and every command would time out to false.
     */
    public function replyEvent(): string
    {
        return $this->replyEvent;
    }

    /**
     * Ask the `:8805` worker to execute one master-side push.
     *
     * @param string $action     One of the {@see FederationPushProtocol} ACTION_* constants.
     * @param string $leafHubId  Connection key (the leaf's own hub uuid).
     * @param string $payload    JSON DATA payload; '' for {@see FederationPushProtocol::ACTION_CLOSE_PEER}.
     *
     * @return bool True only when the `:8805` worker measured the delivery (or
     *              the teardown) and said so on this worker's reply event.
     */
    public function dispatch(string $action, string $leafHubId, string $payload): bool
    {
        $requestId = bin2hex(random_bytes(16));
        $channel = new Channel(1);
        $this->pending[$requestId] = $channel;

        try {
            try {
                ($this->publisher)(FederationPushProtocol::COMMAND_EVENT, [
                    'request_id' => $requestId,
                    'reply_event' => $this->replyEvent,
                    'action' => $action,
                    'leaf_hub_id' => $leafHubId,
                    'payload' => $payload,
                ]);
            } catch (Throwable $e) {
                // The broker itself refused the publish (no connection,
                // transport died mid-write). The DB truth is already
                // committed; the leaf converges on its next hello. Name the
                // action + target loudly — an undelivered REVOCATION in
                // particular is the state operators must be able to see.
                $this->logger->error('Federation master push command: publish failed', [
                    'action' => $action,
                    'leaf_hub_id' => $leafHubId,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            /** @var mixed $reply */
            $reply = $channel->pop(FederationPushProtocol::REPLY_TIMEOUT_SECONDS);
        } finally {
            unset($this->pending[$requestId]);
            // Wake/fail any push a late-arriving reply might still be blocked on.
            $channel->close();
        }

        if (!is_array($reply)) {
            // Timed out, or the broker/subscriber is not there at all. Under-
            // claim: "not confirmed delivered" — which is exactly what this
            // process can honestly say.
            $this->logger->warning('Federation master push command: no delivery reply', [
                'request_id' => $requestId,
                'action' => $action,
                'leaf_hub_id' => $leafHubId,
            ]);

            return false;
        }

        /** @var mixed $delivered */
        $delivered = $reply['delivered'] ?? null;
        if (!is_bool($delivered)) {
            $this->logger->warning('Federation master push command: reply carried no usable delivered flag', [
                'request_id' => $requestId,
                'action' => $action,
                'leaf_hub_id' => $leafHubId,
            ]);

            return false;
        }

        return $delivered;
    }

    /**
     * Deliver a reply to the waiting dispatch coroutine.
     *
     * Wired as the {@see replyEvent()} subscriber in the HTTP worker's
     * `onWorkerStart`. Guarded exactly as
     * {@see \Phlix\Hub\SyncPlay\ChannelPendingCommandPusher::onReply()} is: a
     * non-array payload or a missing/non-string `request_id` is dropped, and an
     * UNKNOWN request id is dropped SILENTLY — that is a late reply for a
     * command whose coroutine already gave up and untracked itself.
     *
     * The push is non-blocking (capacity-1 channel, one waiter, at most one
     * reply), so this single shared subscriber can never stall the other
     * in-flight commands on this worker.
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
