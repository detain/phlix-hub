<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Throwable;
use Workerman\Connection\ConnectionInterface;

use function json_decode;
use function json_encode;
use function strlen;

/**
 * Handles incoming HUB_* frames on the master hub.
 *
 * Parses JSON text frames for HELLO/HELLO_ACK and binary frames for
 * everything else (HEARTBEAT, LIBRARY_SHARE_UPDATE, ADMIN_DELEGATION,
 * HUB_DISCONNECTED).
 *
 * @package Phlix\Hub\Federation
 */
final class FederationFrameHandler
{
    private FrameEncoder $encoder;

    /**
     * @param FederationHubRepository         $hubRepo       Hub repository for peer lookups.
     * @param FederationSessionManager        $sessions      Session manager for federation sessions.
     * @param FederationLibraryShareRepository $libraryShares Library shares repository.
     * @param FederationConnectionManager    $connMgr       Connection manager for active WS connections.
     * @param AuditLogger                     $audit         Audit logger for federation events.
     */
    public function __construct(
        private readonly FederationHubRepository $hubRepo,
        private readonly FederationSessionManager $sessions,
        private readonly FederationLibraryShareRepository $libraryShares,
        private readonly FederationConnectionManager $connMgr,
        private readonly AuditLogger $audit,
    ) {
        $this->encoder = new FrameEncoder();
    }

    /**
     * Handle an incoming text frame (HELLO / HELLO_ACK / ERROR).
     *
     * Returns null to keep the connection open, or a string error message
     * to send back and close the connection.
     *
     * @param string $hubId       Peer hub UUID (from route param).
     * @param string $jsonPayload Raw JSON text frame payload.
     *
     * @return string|null Error message if the connection should be rejected, null otherwise.
     */
    public function handleTextFrame(string $hubId, string $jsonPayload): ?string
    {
        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($jsonPayload, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 'Invalid JSON payload';
        }

        if (!is_array($decoded)) {
            return 'Invalid frame payload';
        }

        /** @var mixed $type */
        $type = $decoded['type'] ?? null;
        if (!is_string($type)) {
            return 'Missing frame type';
        }

        return match ($type) {
            'hub_hello' => $this->handleHubHello($hubId, $decoded),
            'hub_hello_ack' => $this->handleHubHelloAck($hubId, $decoded),
            default => null, // Unknown text frames are ignored
        };
    }

    /**
     * Handle an incoming binary frame.
     *
     * @param string $hubId    Peer hub UUID (from route param).
     * @param string $payload Decoded binary payload.
     * @param int    $frameType RelayFrameType value.
     *
     * @return void
     */
    public function handleBinaryFrame(string $hubId, string $payload, int $frameType): void
    {
        try {
            $type = RelayFrameType::fromValue($frameType);
        } catch (Throwable) {
            return; // Unknown frame type — ignore
        }

        match ($type) {
            RelayFrameType::HEARTBEAT => $this->handleHeartbeat($hubId, $payload),
            RelayFrameType::DISCONNECTED => $this->handleDisconnected($hubId, $payload),
            // Library share updates from leaf are handled via REST API (H.6c),
            // but we accept them here for forward-compatibility — no-op on master.
            default => null,
        };
    }

    /**
     * Handle HUB_HELLO from a newly connecting leaf hub.
     *
     * Validates the public key, creates a federation session, stores the
     * connection, updates peer status, sends HELLO_ACK, and pushes active
     * library shares to the leaf.
     *
     * @param string            $leafHubId Peer hub UUID (from route).
     * @param array<string, mixed> $decoded  Decoded JSON payload.
     *
     * @return string|null Error message to reject, or null to accept.
     */
    private function handleHubHello(string $leafHubId, array $decoded): ?string
    {
        /** @var mixed $rawPublicKey */
        $rawPublicKey = $decoded['public_key'] ?? null;
        /** @var mixed $rawHubName */
        $rawHubName = $decoded['hub_name'] ?? null;
        /** @var mixed $rawHubId */
        $rawHubId = $decoded['hub_id'] ?? null;
        /** @var mixed $rawRole */
        $rawRole = $decoded['role'] ?? null;
        /** @var mixed $rawCapabilities */
        $rawCapabilities = $decoded['capabilities'] ?? null;

        if (!is_string($rawPublicKey) || $rawPublicKey === '') {
            return 'Invalid peer key';
        }

        // Look up peer by public key
        $peer = $this->hubRepo->getPeerByPublicKey($rawPublicKey);
        if ($peer === null) {
            return 'Invalid peer key';
        }

        /** @var string $peerId */
        $peerId = $peer['id'];
        /** @var string $peerName */
        $peerName = is_string($rawHubName) ? $rawHubName : $peer['name'] ?? 'unknown';
        /** @var string $peerUrl */
        $peerUrl = $peer['url'] ?? '';
        /** @var string $peerStatus */
        $peerStatus = $peer['status'] ?? 'pending';

        if ($peerStatus !== 'pending') {
            return 'Peer not registered';
        }

        // Register session and get session ID
        $sessionId = $this->sessions->registerSession($peerId);

        // Get the WS connection for this hubId
        $conn = $this->connMgr->getConnection($leafHubId);
        if ($conn === null) {
            // Fallback: lookup by peerId in reverse map
            return null;
        }

        // Update peer status to connected
        $this->hubRepo->updatePeerStatus($peerId, 'connected');

        // Get master hub ID
        $hubConfig = $this->hubRepo->getHubConfig();
        $masterHubId = is_array($hubConfig)
            ? ($hubConfig['id'] !== null && is_string($hubConfig['id']) ? $hubConfig['id'] : 'master')
            : 'master';

        // Send HELLO_ACK
        $this->sendHelloAck($conn, $sessionId, $masterHubId, ['library_shares', 'relay', 'admin_delegation']);

        // Push all active library shares to the newly connected leaf
        $this->pushLibrarySharesToLeaf($leafHubId);

        // Audit log
        $this->audit->logHubConnect($peerId, $peerName, $peerUrl, true);

        return null;
    }

    /**
     * Handle HUB_HELLO_ACK from master hub (leaf side handler).
     *
     * On the master hub this is a no-op — the master never receives this
     * from itself. Included for protocol completeness.
     *
     * @param string            $hubId   Peer hub UUID.
     * @param array<string, mixed> $decoded Decoded JSON payload.
     *
     * @return void
     */
    private function handleHubHelloAck(string $hubId, array $decoded): void
    {
        // Master hub does not receive HELLO_ACK from other hubs.
        // Leaf-side handling will be implemented in H.6c.
    }

    /**
     * Handle a HUB_HEARTBEAT frame.
     *
     * @param string $hubId   Peer hub UUID.
     * @param string $payload Frame payload (unused — heartbeat has no payload).
     *
     * @return void
     */
    private function handleHeartbeat(string $hubId, string $payload): void
    {
        // Find active session for this hub
        $conn = $this->connMgr->getConnection($hubId);
        if ($conn === null) {
            return;
        }

        // Use reverse map to find peerId from connection
        // The session manager looks up by peer_id — we stored the connection
        // by hubId, which equals peer_id in our model
        try {
            $this->sessions->touchHeartbeat($hubId);
        } catch (Throwable) {
            // Session not found — ignore stale heartbeat
        }
    }

    /**
     * Handle a HUB_DISCONNECTED frame.
     *
     * @param string $hubId   Peer hub UUID.
     * @param string $payload Frame payload (JSON {reason}).
     *
     * @return void
     */
    private function handleDisconnected(string $hubId, string $payload): void
    {
        $conn = $this->connMgr->getConnection($hubId);
        if ($conn === null) {
            return;
        }

        $reason = 'unknown';
        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($payload, true, 2, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && isset($decoded['reason'])) {
                /** @var mixed $rawReason */
                $rawReason = $decoded['reason'];
                if (is_string($rawReason)) {
                    $reason = $rawReason;
                }
            }
        } catch (Throwable) {
            // Use default reason
        }

        // Close session and remove connection
        $this->connMgr->removeConnection($hubId);

        // Find and close session
        $peer = $this->hubRepo->getPeerById($hubId);
        if ($peer !== null) {
            $session = $this->sessions->getActiveSession($hubId);
            if ($session !== null) {
                /** @var string $sessionId */
                $sessionId = is_string($session['id']) ? $session['id'] : '';
                $this->sessions->closeSession($sessionId);
            }
            /** @var string $peerName */
            $peerName = is_string($peer['name']) ? $peer['name'] : 'unknown';
            $this->audit->logHubDisconnect($hubId, $peerName, $reason);
        }
    }

    /**
     * Send HELLO_ACK to a newly connected leaf hub.
     *
     * @param ConnectionInterface $conn         Leaf WS connection.
     * @param string             $sessionId    Federation session UUID.
     * @param string             $masterHubId   This hub's UUID.
     * @param array<string>      $capabilities Supported federation features.
     *
     * @return void
     */
    private function sendHelloAck(
        ConnectionInterface $conn,
        string $sessionId,
        string $masterHubId,
        array $capabilities,
    ): void {
        $payload = json_encode([
            'type' => 'hub_hello_ack',
            'session_id' => $sessionId,
            'master_hub_id' => $masterHubId,
            'role' => 'master',
            'capabilities' => $capabilities,
        ], JSON_THROW_ON_ERROR);

        $conn->send($payload);
    }

    /**
     * Push all active library shares to a newly connected leaf hub.
     *
     * Sends one LIBRARY_SHARE_UPDATE binary frame per active share.
     *
     * @param string $leafHubId Leaf hub UUID.
     *
     * @return void
     */
    private function pushLibrarySharesToLeaf(string $leafHubId): void
    {
        $activeShares = $this->libraryShares->getActiveOutgoingShares();
        if ($activeShares === []) {
            return;
        }

        $conn = $this->connMgr->getConnection($leafHubId);
        if ($conn === null) {
            return;
        }

        $sharePayload = json_encode([
            'shares' => array_map(
                static fn (array $share): array => [
                    'id' => $share['id'],
                    'library_id' => $share['library_id'],
                    'library_name' => $share['library_name'],
                    'permission' => $share['permission'],
                    'status' => $share['status'],
                ],
                $activeShares,
            ),
        ], JSON_THROW_ON_ERROR);

        // Encode as a binary relay frame using the shared codec
        $frame = $this->encoder->encode(RelayFrameType::DATA, 0, $sharePayload);
        $conn->send($frame);
    }
}
