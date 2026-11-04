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

use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * `:8805`-worker side of the cross-process federation push commands.
 *
 * Lives in the single federation WS worker process — the only process whose
 * {@see FederationConnectionManager} holds the leaf's live socket AND the H-4
 * verified stamp for it, AND (review hub__asphp) the only process whose
 * {@see FederationPeerManager} ever claimed the leaf→master link. Wired as the
 * {@see FederationPushProtocol::COMMAND_EVENT} subscriber in
 * {@see \Phlix\Hub\Relay\FederationWorker::onWorkerStart()}; the mirror image of
 * {@see \Phlix\Hub\SyncPlay\PendingCommandDispatcher} on the SyncPlay side of
 * the same broker.
 *
 * ## Two command families, one execution law
 *
 * Master-side verbs (offer/revocation/close_peer) act on an INBOUND leaf
 * connection addressed by `leaf_hub_id`; leaf-side verbs (leaf_connect /
 * leaf_disconnect / leaf_share_offer / leaf_share_revocation,
 * `leaf_hub_id === ''`) act on THIS process's single OUTBOUND link through the
 * container-shared {@see FederationPeerManager} — the identical instance the
 * worker bootstrapped, because `:8805` resolves both from one container.
 * Either way the rule is the same: the command only ever executes where the
 * socket authority physically lives.
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
     */
    private \Closure $publisher;

    /**
     * @param StructuredLogger                                    $logger      Relay logger.
     * @param FederationConnectionManager                         $connMgr     THIS process's manager — the
     *        one whose registrations the H-4 handshake actually stamped.
     * @param (callable(string, array<string, mixed>): void)|null $publisher   Channel publisher
     *        (defaults to {@see ChannelClient::publish()}; overridable for tests).
     * @param FederationPeerManager|null                          $peerManager THIS process's leaf-link
     *        owner — the container singleton the worker bootstrapped (review hub__asphp: the leaf
     *        commands execute HERE, nowhere else). Null only for legacy hand-built dispatchers;
     *        leaf commands then answer the measured false (no executor).
     */
    public function __construct(
        private readonly StructuredLogger $logger,
        private readonly FederationConnectionManager $connMgr,
        ?callable $publisher = null,
        private readonly ?FederationPeerManager $peerManager = null,
    ) {
        $this->encoder = new FrameEncoder();
        $this->publisher = \Closure::fromCallable($publisher ?? static function (string $event, array $data): void {
            ChannelClient::publish($event, $data);
        });
    }

    /**
     * Handle one push command published by an HTTP worker.
     *
     * @param mixed $data The published command payload.
     */
    public function onCommand(mixed $data): void
    {
        if (!is_array($data)) {
            $this->logger->warning('Federation push command: payload was not an array');

            return;
        }

        /** @var array<string, mixed> $data */
        $requestId = self::field($data, 'request_id');
        $replyEvent = self::field($data, 'reply_event');
        $action = self::field($data, 'action');
        /** @var mixed $rawLeafHubId */
        $rawLeafHubId = $data['leaf_hub_id'] ?? null;
        /** @var mixed $payload */
        $payload = $data['payload'] ?? null;

        if (
            $requestId === null
            || $replyEvent === null
            || $action === null
            || !is_string($rawLeafHubId)
            || !is_string($payload)
        ) {
            // No reply: see the class docblock. A malformed command must not
            // produce a delivery verdict nobody measured.
            $this->logger->warning('Federation push command: malformed command, no reply sent', [
                'has_request_id' => $requestId !== null,
                'has_reply_event' => $replyEvent !== null,
                'has_action' => $action !== null,
                'has_leaf_hub_id' => is_string($rawLeafHubId),
                'has_payload' => is_string($payload),
            ]);

            return;
        }

        if (in_array($action, FederationPushProtocol::LEAF_ACTIONS, true)) {
            // Leaf family: addressed to THIS process's own outbound link, so
            // the connection key must be exactly '' (same envelope, different
            // addressing law — a non-empty key here is a mis-built command,
            // and mis-built commands get no reply).
            if ($rawLeafHubId !== '') {
                $this->logger->warning('Federation push command: malformed command, no reply sent', [
                    'action' => $action,
                    'reason' => 'leaf command must carry an empty leaf_hub_id',
                ]);

                return;
            }

            $this->executeLeaf($requestId, $replyEvent, $action, $payload);

            return;
        }

        // Master family: the target connection key is required non-empty.
        if ($rawLeafHubId === '') {
            $this->logger->warning('Federation push command: malformed command, no reply sent', [
                'action' => $action,
                'reason' => 'master-side command requires a leaf_hub_id',
            ]);

            return;
        }
        $leafHubId = $rawLeafHubId;

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
            $this->logger->warning('Federation push command: unrecognised command, no reply sent', [
                'action' => $action,
                'leaf_hub_id' => $leafHubId,
                'empty_payload' => $payload === '',
            ]);

            return;
        }

        $this->pushData($requestId, $replyEvent, $action, $leafHubId, $payload);
    }

    /**
     * Execute one leaf-family command against THIS process's peer manager —
     * the instance {@see FederationPeerManager::bootstrapFromWorker()} claimed
     * (review hub__asphp Critical: these intents used to run inside the
     * publishing HTTP worker, against its own never-connected private
     * instance, where they no-oped, forged a rogue second link, or played
     * disconnect theater).
     *
     * `delivered` is the MEASURED outcome the owner can honestly attest:
     * connect → link live or re-dial armed (idempotent repeat connects read
     * true without a second socket — establishConnection's live-handle guard);
     * disconnect → neither a live socket nor an armed re-dial is left; share
     * verbs → the H-4-gated push's send verdict. The lifecycle law (payload
     * must be '') mirrors close_peer; a lifecycle verb published WITH payload
     * is a command this dispatcher does not understand → no reply.
     */
    private function executeLeaf(string $requestId, string $replyEvent, string $action, string $payload): void
    {
        $manager = $this->peerManager;
        if ($manager === null) {
            // Understood command, no executor to measure it with: honest
            // false, loudly. (Only a hand-built legacy dispatcher is ever
            // unwired; the container always wires the singleton.)
            $this->logger->warning('Federation push command (leaf): refused — no peer manager wired here', [
                'action' => $action,
            ]);
            ($this->publisher)($replyEvent, [
                'request_id' => $requestId,
                'delivered' => false,
            ]);

            return;
        }

        if (
            $action === FederationPushProtocol::ACTION_LEAF_CONNECT
            || $action === FederationPushProtocol::ACTION_LEAF_DISCONNECT
        ) {
            if ($payload !== '') {
                $this->logger->warning('Federation push command: unrecognised command, no reply sent', [
                    'action' => $action,
                    'leaf_hub_id' => '',
                    'empty_payload' => false,
                ]);

                return;
            }

            if ($action === FederationPushProtocol::ACTION_LEAF_CONNECT) {
                $manager->connectToMaster();
                $delivered = $manager->linkLiveOrArmed();
            } else {
                $manager->disconnectFromMaster();
                $delivered = !$manager->linkLiveOrArmed();
            }
        } else {
            if ($payload === '') {
                $this->logger->warning('Federation push command: unrecognised command, no reply sent', [
                    'action' => $action,
                    'leaf_hub_id' => '',
                    'empty_payload' => true,
                ]);

                return;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                // Understood action, undecodable request fields: nothing could
                // be sent. Measured false — not the silence of a no-op.
                $this->logger->warning('Federation push command (leaf): request payload was not decodable JSON', [
                    'action' => $action,
                ]);
                ($this->publisher)($replyEvent, [
                    'request_id' => $requestId,
                    'delivered' => false,
                ]);

                return;
            }

            $delivered = $action === FederationPushProtocol::ACTION_LEAF_SHARE_OFFER
                ? $this->pushLeafOffer($manager, $decoded)
                : $this->pushLeafRevocation($manager, $decoded);
        }

        ($this->publisher)($replyEvent, [
            'request_id' => $requestId,
            'delivered' => $delivered,
        ]);

        $this->logger->info('Federation push command (leaf) executed', [
            'action' => $action,
            'delivered' => $delivered,
        ]);
    }

    /**
     * Offer request fields → the owner's H-4-gated push. Field law mirrors
     * what the HTTP side already validated (all four non-empty strings); an
     * incomplete request is a measured false, never a half-built frame.
     *
     * @param array<array-key, mixed> $decoded decoded request body — every key
     *        is re-narrowed through self::field(), never trusted as string
     */
    private function pushLeafOffer(FederationPeerManager $manager, array $decoded): bool
    {
        $shareId = self::field($decoded, 'share_id');
        $libraryId = self::field($decoded, 'library_id');
        $libraryName = self::field($decoded, 'library_name');
        $permission = self::field($decoded, 'permission');

        if ($shareId === null || $libraryId === null || $libraryName === null || $permission === null) {
            $this->logger->warning('Federation push command (leaf): share offer fields incomplete, nothing sent', [
                'share_id' => $shareId ?? '',
            ]);

            return false;
        }

        return $manager->pushLibraryShare($shareId, $libraryId, $libraryName, $permission);
    }

    /**
     * @param array<array-key, mixed> $decoded see pushLeafOffer's field law
     */
    private function pushLeafRevocation(FederationPeerManager $manager, array $decoded): bool
    {
        $shareId = self::field($decoded, 'share_id');
        if ($shareId === null) {
            $this->logger->warning('Federation push command (leaf): revocation without a share_id, nothing sent');

            return false;
        }

        return $manager->pushLibraryShareRevoked($shareId);
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
            $this->logger->warning('Federation push command refused: channel not verified', [
                'action' => $action,
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
            $this->logger->error('Federation push command failed: send failed', [
                'action' => $action,
                'leaf_hub_id' => $leafHubId,
                'error' => $e->getMessage(),
            ]);
            $delivered = false;
        }

        ($this->publisher)($replyEvent, [
            'request_id' => $requestId,
            'delivered' => $delivered,
        ]);

        $this->logger->info('Federation push command dispatched', [
            'action' => $action,
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

        $this->logger->info('Federation push command (close_peer): connection torn down', [
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
