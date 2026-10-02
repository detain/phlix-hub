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
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrameType;
use Throwable;

use function is_array;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * `:8805`-worker side of the cross-process federation master push.
 *
 * Lives in the single federation WS worker process — the only process whose
 * {@see FederationConnectionManager} holds the leaf's live socket AND the H-4
 * verified stamp for it. Wired as the
 * {@see FederationPushProtocol::COMMAND_EVENT} subscriber in
 * {@see \Phlix\Hub\Relay\FederationWorker::onWorkerStart()}; the mirror image of
 * {@see \Phlix\Hub\SyncPlay\PendingCommandDispatcher} on the SyncPlay side of
 * the same broker.
 *
 * ## Transport moves intent, never authority
 *
 * Every DATA-carrying command re-passes the SAME verified-channel gate the
 * in-process push path applies (`isVerified()` against THIS process's map)
 * before a byte reaches a socket. A command published from an HTTP worker
 * carries no more privilege than the admin who typed it: an unverified (or
 * absent) leaf channel gets a refusal — logged, and answered honestly with
 * `delivered: false`.
 *
 * ## A malformed command produces NO reply at all
 *
 * {@see onCommand()} validates the envelope and, when a field is missing, empty,
 * or the action unknown, logs and returns WITHOUT publishing a reply. A reply is
 * a statement about delivery, and the only reply this dispatcher could honestly
 * make for a command it never understood is a bool it never measured. Staying
 * silent lets the publisher's own timeout degrade to false (same doctrine as
 * {@see \Phlix\Hub\SyncPlay\PendingCommandDispatcher::onPush()}).
 *
 * ## Nothing here may throw
 *
 * This runs inside a channel subscriber callback in the resident process that
 * owns EVERY federation socket. A socket write that explodes mid-delivery is
 * caught and answered `delivered: false` — the WS transport dying is exactly the
 * situation the bridge exists to be honest about, never a reason to take the
 * worker down.
 *
 * @package Phlix\Hub\Federation
 */
final class FederationPushDispatcher
{
    private readonly FrameEncoder $encoder;

    /**
     * @var callable(string, array<string, mixed>): void
     */
    private $publisher;

    /**
     * @param StructuredLogger                                    $logger   Relay logger.
     * @param FederationConnectionManager                         $connMgr  THIS process's manager — the
     *        one whose registrations the H-4 handshake actually stamped.
     * @param (callable(string, array<string, mixed>): void)|null $publisher Channel publisher
     *        (defaults to {@see ChannelClient::publish()}; overridable for tests).
     */
    public function __construct(
        private readonly StructuredLogger $logger,
        private readonly FederationConnectionManager $connMgr,
        ?callable $publisher = null,
    ) {
        $this->encoder = new FrameEncoder();
        $this->publisher = $publisher ?? static function (string $event, array $data): void {
            ChannelClient::publish($event, $data);
        };
    }

    /**
     * Handle one push command published by an HTTP worker.
     *
     * @param mixed $data The published command payload.
     */
    public function onCommand(mixed $data): void
    {
        if (!is_array($data)) {
            $this->logger->warning('Federation master push command: payload was not an array');

            return;
        }

        /** @var array<string, mixed> $data */
        $requestId = self::field($data, 'request_id');
        $replyEvent = self::field($data, 'reply_event');
        $action = self::field($data, 'action');
        $leafHubId = self::field($data, 'leaf_hub_id');
        /** @var mixed $payload */
        $payload = $data['payload'] ?? null;

        if (
            $requestId === null
            || $replyEvent === null
            || $action === null
            || $leafHubId === null
            || !is_string($payload)
        ) {
            // No reply: see the class docblock. A malformed command must not
            // produce a delivery verdict nobody measured.
            $this->logger->warning('Federation master push command: malformed command, no reply sent', [
                'has_request_id' => $requestId !== null,
                'has_reply_event' => $replyEvent !== null,
                'has_action' => $action !== null,
                'has_leaf_hub_id' => $leafHubId !== null,
                'has_payload' => is_string($payload),
            ]);

            return;
        }

        if ($action === FederationPushProtocol::ACTION_CLOSE_PEER) {
            $this->closePeer($requestId, $replyEvent, $leafHubId);

            return;
        }

        if (
            ($action !== FederationPushProtocol::ACTION_OFFER
                && $action !== FederationPushProtocol::ACTION_REVOCATION)
            || $payload === ''
        ) {
            // Unknown action, or a DATA action with no payload: not a command
            // this dispatcher understands. No reply (malformed doctrine above).
            $this->logger->warning('Federation master push command: unrecognised command, no reply sent', [
                'action' => $action,
                'leaf_hub_id' => $leafHubId,
                'empty_payload' => $payload === '',
            ]);

            return;
        }

        $this->pushData($requestId, $replyEvent, $action, $leafHubId, $payload);
    }

    /**
     * Offer/revocation: verified gate, then one DATA frame, then the measured bool.
     */
    private function pushData(
        string $requestId,
        string $replyEvent,
        string $action,
        string $leafHubId,
        string $payload,
    ): void {
        // THE verified-channel law, applied where the stamp actually lives. An
        // HTTP worker cannot check this itself — its container's manager is a
        // different, empty map. Refusing here is what stops the bridge from
        // becoming a gate bypass.
        if (!$this->connMgr->isVerified($leafHubId)) {
            $this->logger->warning("Federation master push command ({$action}): channel not verified", [
                'leaf_hub_id' => $leafHubId,
            ]);
            // Understood command, measured answer: NOT delivered.
            ($this->publisher)($replyEvent, [
                'request_id' => $requestId,
                'delivered' => false,
            ]);

            return;
        }

        try {
            $frame = $this->encoder->encode(RelayFrameType::DATA, 0, $payload);
            $delivered = $this->connMgr->sendTo($leafHubId, $frame);
        } catch (Throwable $e) {
            // Oversized/undecodable payload or a socket that died mid-write.
            // The resident worker stays up; the HTTP side hears the measured
            // truth.
            $this->logger->error("Federation master push command ({$action}): send failed", [
                'leaf_hub_id' => $leafHubId,
                'error' => $e->getMessage(),
            ]);
            $delivered = false;
        }

        ($this->publisher)($replyEvent, [
            'request_id' => $requestId,
            'delivered' => $delivered,
        ]);

        $this->logger->info("Federation master push command ({$action}): dispatched", [
            'leaf_hub_id' => $leafHubId,
            'delivered' => $delivered,
        ]);
    }

    /**
     * Teardown mirror of {@see FederationMasterPusher::closePeerConnection()},
     * executed against THIS process's connection map.
     *
     * No verification gate — parity with the in-process path: the goodbye frame
     * carries no claims, and closing a socket a peer row no longer owns is SAFE
     * regardless of handshake state.
     */
    private function closePeer(string $requestId, string $replyEvent, string $leafHubId): void
    {
        $conn = $this->connMgr->getConnection($leafHubId);
        if ($conn === null) {
            // Nothing live to close: the link is already gone, which is the
            // state the caller wanted. Same true the in-process path returns.
            ($this->publisher)($replyEvent, [
                'request_id' => $requestId,
                'delivered' => true,
            ]);

            return;
        }

        $this->connMgr->removeConnectionByConn($conn);

        $frame = $this->encoder->encode(
            RelayFrameType::DISCONNECTED,
            0,
            json_encode(['reason' => 'peer_deleted'], JSON_THROW_ON_ERROR),
        );
        try {
            $conn->send($frame);
        } catch (Throwable) {
            // Socket already gone — the close below is authoritative.
        }

        try {
            $conn->close();
        } catch (Throwable) {
            // Already closed.
        }

        ($this->publisher)($replyEvent, [
            'request_id' => $requestId,
            'delivered' => true,
        ]);

        $this->logger->info('Federation master push command (close_peer): connection torn down', [
            'leaf_hub_id' => $leafHubId,
        ]);
    }

    /**
     * A non-empty string field, or null.
     *
     * @param array<string, mixed> $source
     */
    private static function field(array $source, string $key): ?string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
