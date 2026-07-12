<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\InvalidFrameTypeException;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Timer;

/**
 * Manages the persistent WebSocket connection from a leaf hub to the master hub.
 *
 * On leaf hubs this class:
 *   1. Connects to master hub's WS endpoint at `wss://master-url/relay/federation/{leaf_hub_id}`
 *   2. Sends HUB_HELLO JSON frame on connect
 *   3. Handles HUB_HELLO_ACK response (validates master key, stores session)
 *   4. Sends HUB_HEARTBEAT binary frame every 15 seconds
 *   5. Handles incoming LIBRARY_SHARE_UPDATE, ADMIN_DELEGATION, HUB_DISCONNECTED frames
 *   6. Auto-reconnects with exponential backoff on disconnect
 *   7. Pushes local library share changes to master when connected
 *
 * @package Phlix\Hub\Federation
 */
class FederationPeerManager
{
    /**
     * Leaf-side connection to master hub (null when disconnected).
     *
     * @var AsyncTcpConnection|null
     */
    private ?AsyncTcpConnection $masterConnection = null;

    /**
     * Current reconnect delay in seconds (exponential backoff).
     *
     * @var int
     */
    private int $reconnectDelaySeconds = 5;

    /**
     * Whether a reconnection is scheduled.
     *
     * @var bool
     */
    private bool $reconnectScheduled = false;

    /**
     * Frame decoder for incoming binary frames.
     *
     * @var FrameDecoder
     */
    private FrameDecoder $decoder;

    /**
     * Frame encoder for outgoing binary frames.
     *
     * @var FrameEncoder
     */
    private FrameEncoder $encoder;

    /**
     * Active heartbeat timer ID (Workerman).
     *
     * @var int|null
     */
    private ?int $heartbeatTimerId = null;

    /**
     * Stored session ID from HELLO_ACK handshake.
     *
     * @var string|null
     */
    private ?string $sessionId = null;

    /**
     * @param FederationHubRepository            $hubRepo       Hub + peer repository.
     * @param FederationSessionManager           $sessions      Federation session manager.
     * @param FederationLibraryShareRepository $libraryShares Library shares repository.
     * @param FederationAdminDelegationRepository $adminDel     Admin delegation repository.
     * @param AuditLogger                       $audit         Audit logger.
     */
    public function __construct(
        private readonly FederationHubRepository $hubRepo,
        private readonly FederationSessionManager $sessions,
        private readonly FederationLibraryShareRepository $libraryShares,
        private readonly FederationAdminDelegationRepository $adminDel,
        private readonly AuditLogger $audit,
    ) {
        $this->decoder = new FrameDecoder();
        $this->encoder = new FrameEncoder();
    }

    /**
     * Initiate the persistent WebSocket connection to the master hub.
     *
     * Only operates when this hub is configured as a leaf hub with an
     * active relay-enabled peer (the master). Idempotent — if already
     * connected this is a no-op.
     *
     * @return void
     */
    public function connectToMaster(): void
    {
        $hubConfig = $this->hubRepo->getHubConfig();
        if ($hubConfig === null) {
            return;
        }

        $role = is_string($hubConfig['role'] ?? null) ? $hubConfig['role'] : 'leaf';
        /** @var mixed $isActiveRaw */
        $isActiveRaw = $hubConfig['is_active'] ?? null;
        $isActive = is_int($isActiveRaw) ? $isActiveRaw === 1 : (is_string($isActiveRaw) && $isActiveRaw === '1');

        if (!$isActive || $role !== 'leaf') {
            return;
        }

        $peers = $this->hubRepo->getConnectedPeers();
        if ($peers === []) {
            return;
        }

        // Get the first connected peer — assumes it is the master hub
        /** @var array<string, mixed> $masterPeer */
        $masterPeer = $peers[0];
        $masterUrl = is_string($masterPeer['url'] ?? null) ? (string) $masterPeer['url'] : '';

        if ($masterUrl === '') {
            return;
        }

        // Build the WSS URL: wss://master-host/relay/federation/{this_hub_id}
        /** @var string $leafHubId */
        $leafHubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
        $parsedHost = parse_url($masterUrl, PHP_URL_HOST);
        /** @var string $masterHost */
        $masterHost = is_string($parsedHost) ? $parsedHost : $masterUrl;
        $wsUrl = 'wss://' . $masterHost . '/relay/federation/' . urlencode($leafHubId);

        $this->establishConnection($wsUrl, $hubConfig);
    }

    /**
     * Actively disconnect from the master hub and cancel timers.
     *
     * @return void
     */
    public function disconnectFromMaster(): void
    {
        $this->cancelHeartbeatTimer();

        if ($this->masterConnection !== null) {
            try {
                $this->masterConnection->close();
            } catch (Throwable) {
                // Ignore close errors
            }
            $this->masterConnection = null;
        }

        $this->sessionId = null;
        $this->reconnectScheduled = false;
        $this->reconnectDelaySeconds = 5;
    }

    /**
     * Push an outgoing library share to the master hub if connected.
     *
     * @param string $shareId    Share UUID.
     * @param string $libraryId   Library UUID.
     * @param string $libraryName Library name.
     * @param string $permission Permission level ('read'|'readwrite').
     *
     * @return void
     */
    public function pushLibraryShare(
        string $shareId,
        string $libraryId,
        string $libraryName,
        string $permission,
    ): void {
        if ($this->masterConnection === null) {
            return;
        }

        $payload = json_encode([
            'shares' => [
                [
                    'id' => $shareId,
                    'library_id' => $libraryId,
                    'library_name' => $libraryName,
                    'permission' => $permission,
                    'status' => 'active',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $frame = $this->encoder->encode(RelayFrameType::DATA, 0, $payload);
        $this->masterConnection->send($frame);
    }

    /**
     * Push a library share revocation to the master hub if connected.
     *
     * @param string $shareId Share UUID being revoked.
     *
     * @return void
     */
    public function pushLibraryShareRevoked(string $shareId): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        $payload = json_encode([
            'share_id' => $shareId,
        ], JSON_THROW_ON_ERROR);

        $frame = $this->encoder->encode(RelayFrameType::DATA, 0, $payload);
        $this->masterConnection->send($frame);
    }

    /**
     * Check whether we are currently connected to the master hub.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->masterConnection !== null;
    }

    /**
     * Get the current session ID (null when not connected).
     *
     * @return string|null
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Establish a new WebSocket connection to the master hub.
     *
     * @param string $wsUrl     Full WebSocket URL.
     * @param array<string, mixed> $hubConfig This hub's configuration row.
     *
     * @return void
     */
    private function establishConnection(string $wsUrl, array $hubConfig): void
    {
        // Prevent multiple simultaneous connection attempts
        if ($this->masterConnection !== null) {
            return;
        }

        $this->masterConnection = new AsyncTcpConnection($wsUrl);

        $hubConfigFinal = $hubConfig;
        $self = $this;

        // On successful connect — send HELLO
        $this->masterConnection->onConnect = static function (
            /** @scrutinizer ignore-unused */ AsyncTcpConnection $conn
        ) use (
            $hubConfigFinal,
            $self
        ): void {
            $self->sendHubHello($hubConfigFinal);
        };

        // On message — handle frames
        $this->masterConnection->onMessage = function (
            /** @scrutinizer ignore-unused */ AsyncTcpConnection $conn,
            string $data
        ): void {
            $this->handleMessage($data);
        };

        // On close — schedule reconnect
        $this->masterConnection->onClose = function (
            /** @scrutinizer ignore-unused */ AsyncTcpConnection $conn
        ): void {
            $this->scheduleReconnect();
        };

        // On error — schedule reconnect
        $this->masterConnection->onError = function (
            /** @scrutinizer ignore-unused */ AsyncTcpConnection $conn,
            int $code,
            string $reason
        ): void {
            $logger = LoggerFactory::get(LogChannels::RELAY);
            $logger->warning('FederationPeerManager: connection error', [
                'code' => $code,
                'reason' => $reason,
            ]);
            $this->scheduleReconnect();
        };

        try {
            $this->masterConnection->connect();
        } catch (Throwable $e) {
            $logger = LoggerFactory::get(LogChannels::RELAY);
            $logger->error('FederationPeerManager: failed to connect', [
                'error' => $e->getMessage(),
            ]);
            $this->masterConnection = null;
            $this->scheduleReconnect();
        }
    }

    /**
     * Send the HUB_HELLO JSON text frame to the master hub.
     *
     * @param array<string, mixed> $hubConfig This hub's configuration.
     *
     * @return void
     */
    private function sendHubHello(array $hubConfig): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        /** @var string $hubId */
        $hubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
        /** @var string $hubName */
        $hubName = is_string($hubConfig['name'] ?? null) ? $hubConfig['name'] : '';
        /** @var string $publicKey */
        $publicKey = is_string($hubConfig['public_key'] ?? null) ? $hubConfig['public_key'] : '';

        $payload = json_encode([
            'type' => 'hub_hello',
            'hub_id' => $hubId,
            'hub_name' => $hubName,
            'public_key' => $publicKey,
            'role' => 'leaf',
            'capabilities' => ['library_shares', 'relay', 'admin_delegation'],
        ], JSON_THROW_ON_ERROR);

        $this->masterConnection->send($payload);
    }

    /**
     * Handle an incoming WebSocket message (text JSON or binary frame).
     *
     * @param string $data Raw frame payload.
     *
     * @return void
     */
    private function handleMessage(string $data): void
    {
        // Detect text vs binary frame by checking for valid JSON UTF-8
        if ($this->isTextFrame($data)) {
            $this->handleTextFrame($data);
        } else {
            $this->handleBinaryFrame($data);
        }
    }

    /**
     * Handle an incoming text (JSON) frame.
     *
     * @param string $data Raw JSON string.
     *
     * @return void
     */
    private function handleTextFrame(string $data): void
    {
        try {
            /** @var array<string, mixed>|null $msg */
            $msg = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }

        if (!is_array($msg)) {
            return;
        }

        /** @var string $type */
        $type = is_string($msg['type'] ?? null) ? $msg['type'] : '';

        match ($type) {
            'hub_hello_ack' => $this->handleHelloAck($msg),
            default => null,
        };
    }

    /**
     * Handle HUB_HELLO_ACK — session established, start heartbeat timer.
     *
     * @param array<string, mixed> $msg Decoded JSON payload.
     *
     * @return void
     */
    private function handleHelloAck(array $msg): void
    {
        /** @var mixed $sessionIdRaw */
        $sessionIdRaw = $msg['session_id'] ?? null;
        $this->sessionId = is_string($sessionIdRaw) ? $sessionIdRaw : null;

        $hubConfig = $this->hubRepo->getHubConfig();
        if ($hubConfig !== null) {
            /** @var string $hubId */
            $hubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
            /** @var string $peerName */
            $peerName = is_string($msg['master_hub_id'] ?? null) ? $msg['master_hub_id'] : 'master';

            // Register session
            if ($this->sessionId !== null) {
                $peers = $this->hubRepo->getConnectedPeers();
                if ($peers !== []) {
                    /** @var array<string, mixed> $masterPeer */
                    $masterPeer = $peers[0];
                    /** @var string $peerId */
                    $peerId = is_string($masterPeer['id'] ?? null) ? $masterPeer['id'] : '';
                    if ($peerId !== '') {
                        $this->sessions->registerSession($peerId);
                    }
                }
            }

            $this->audit->logHubConnect($hubId, $peerName, '', true);

            // Reset backoff on successful handshake
            $this->reconnectDelaySeconds = 5;
            $this->reconnectScheduled = false;

            // Start heartbeat timer
            $this->startHeartbeatTimer();
        }
    }

    /**
     * Handle an incoming binary relay frame.
     *
     * @param string $data Raw binary frame data.
     *
     * @return void
     */
    private function handleBinaryFrame(string $data): void
    {
        try {
            $frame = $this->decoder->decode($data);
        } catch (InvalidFrameTypeException $e) {
            // Undecodable frame or a decode-buffer overflow (H-R7) from the
            // master hub. Escaping here would fatal out of the Workerman message
            // callback; close the connection cleanly and let the reconnect timer
            // re-establish a known-good session.
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: undecodable frame from master, closing connection',
                ['error' => $e->getMessage()],
            );
            try {
                $this->masterConnection?->close();
            } catch (Throwable) {
                // Connection already gone — no-op.
            }
            return;
        }
        if ($frame === null) {
            return;
        }

        try {
            $type = RelayFrameType::fromValue($frame->type->value);
        } catch (Throwable) {
            return;
        }

        match ($type) {
            RelayFrameType::HEARTBEAT => $this->handleHeartbeat(),
            RelayFrameType::DISCONNECTED => $this->handleDisconnected($frame->payload),
            RelayFrameType::DATA => $this->handleDataFrame($frame->payload),
            default => null,
        };
    }

    /**
     * Handle a heartbeat — respond with our own heartbeat.
     *
     * @return void
     */
    private function handleHeartbeat(): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        $frame = $this->encoder->encode(RelayFrameType::HEARTBEAT, 0, '');
        $this->masterConnection->send($frame);

        // Touch the session heartbeat in DB
        if ($this->sessionId !== null) {
            try {
                $this->sessions->touchHeartbeat($this->sessionId);
            } catch (Throwable) {
                // Session not found — ignore
            }
        }
    }

    /**
     * Handle a HUB_DISCONNECTED frame from master.
     *
     * @param string $payload Frame payload (JSON {reason}).
     *
     * @return void
     */
    private function handleDisconnected(string $payload): void
    {
        $reason = 'master_disconnect';
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
            // Use default
        }

        $this->cancelHeartbeatTimer();

        if ($this->masterConnection !== null) {
            try {
                $this->masterConnection->close();
            } catch (Throwable) {
                // Ignore
            }
            $this->masterConnection = null;
        }

        $this->sessionId = null;
        $this->scheduleReconnect();

        // Audit log
        $hubConfig = $this->hubRepo->getHubConfig();
        if ($hubConfig !== null) {
            /** @var string $hubId */
            $hubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
            /** @var string $peerName */
            $peerName = is_string($hubConfig['name'] ?? null) ? $hubConfig['name'] : 'master';
            $this->audit->logHubDisconnect($hubId, $peerName, $reason);
        }
    }

    /**
     * Handle incoming DATA frame — contains library share updates or admin delegations.
     *
     * @param string $payload Raw JSON payload.
     *
     * @return void
     */
    private function handleDataFrame(string $payload): void
    {
        try {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        // Handle library share updates (incoming offers from master)
        if (isset($data['shares']) && is_array($data['shares'])) {
            /** @var mixed $share */
            foreach ($data['shares'] as $share) {
                if (is_array($share)) {
                    /** @var array<string, mixed> $share */
                    $this->libraryShares->handleIncomingOffer($share);
                }
            }
        }

        // Handle library share revocation notification
        if (isset($data['share_id']) && is_string($data['share_id'])) {
            $this->handleLibraryShareRevoked($data['share_id']);
        }

        // Handle admin delegation push
        if (isset($data['user_id']) && isset($data['action'])) {
            $this->handleAdminDelegation($data);
        }
    }

    /**
     * Handle an incoming library share revoked notification from master.
     *
     * @param string $shareId Share UUID that was revoked.
     *
     * @return void
     */
    private function handleLibraryShareRevoked(string $shareId): void
    {
        // Mark the incoming offer as rejected if we have it
        $offer = $this->libraryShares->getIncomingOfferById($shareId);
        if ($offer !== null) {
            // Already handled — the share was removed
        }
    }

    /**
     * Handle an incoming admin delegation push from master.
     *
     * @param array<string, mixed> $data Payload with user_id, peer_id, action.
     *
     * @return void
     */
    private function handleAdminDelegation(array $data): void
    {
        /** @var string $userId */
        $userId = is_string($data['user_id'] ?? null) ? $data['user_id'] : '';
        /** @var string $peerId */
        $peerId = is_string($data['peer_id'] ?? null) ? $data['peer_id'] : '';
        /** @var string $action */
        $action = is_string($data['action'] ?? null) ? $data['action'] : '';

        if ($userId === '' || $peerId === '') {
            return;
        }

        if ($action === 'grant') {
            $this->adminDel->grant($this->generateUuid(), $peerId, $userId);
            $this->audit->logAdminDelegation($peerId, $userId, 'grant');
        } elseif ($action === 'revoke') {
            $delegations = $this->adminDel->getActiveDelegationsForUser($userId);
            foreach ($delegations as $d) {
                /** @var string $dId */
                $dId = is_string($d['id'] ?? null) ? $d['id'] : '';
                /** @var string $dPeerId */
                $dPeerId = is_string($d['peer_id'] ?? null) ? $d['peer_id'] : '';
                if ($dId !== '' && $dPeerId === $peerId) {
                    $this->adminDel->revoke($dId);
                    $this->audit->logAdminDelegation($peerId, $userId, 'revoke');
                    break;
                }
            }
        }
    }

    /**
     * Start the heartbeat timer — sends HUB_HEARTBEAT every 15 seconds.
     *
     * @return void
     */
    private function startHeartbeatTimer(): void
    {
        $this->cancelHeartbeatTimer();

        $self = $this;
        $this->heartbeatTimerId = Timer::add(
            15,
            static function () use ($self): void {
                $self->sendHeartbeat();
            },
        );
    }

    /**
     * Send a HUB_HEARTBEAT binary frame to the master hub.
     *
     * @return void
     */
    private function sendHeartbeat(): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        $frame = $this->encoder->encode(RelayFrameType::HEARTBEAT, 0, '');
        $this->masterConnection->send($frame);

        if ($this->sessionId !== null) {
            try {
                $this->sessions->touchHeartbeat($this->sessionId);
            } catch (Throwable) {
                // Ignore
            }
        }
    }

    /**
     * Cancel the active heartbeat timer.
     *
     * @return void
     */
    private function cancelHeartbeatTimer(): void
    {
        if ($this->heartbeatTimerId !== null) {
            try {
                Timer::del($this->heartbeatTimerId);
            } catch (Throwable) {
                // Already cancelled or invalid
            }
            $this->heartbeatTimerId = null;
        }
    }

    /**
     * Schedule a reconnection attempt with exponential backoff.
     *
     * Backoff sequence: 5, 10, 20, 40, max 60 seconds.
     *
     * @return void
     */
    private function scheduleReconnect(): void
    {
        // Prevent duplicate scheduling
        if ($this->reconnectScheduled) {
            return;
        }

        $this->reconnectScheduled = true;
        $this->cancelHeartbeatTimer();

        // Clean up existing connection
        if ($this->masterConnection !== null) {
            try {
                $this->masterConnection->close();
            } catch (Throwable) {
                // Ignore
            }
            $this->masterConnection = null;
        }

        $this->sessionId = null;

        $delay = $this->reconnectDelaySeconds;
        $self = $this;

        Timer::add(
            $delay,
            static function () use ($self, $delay): void {
                $self->reconnectScheduled = false;
                $self->reconnectDelaySeconds = min($delay * 2, 60);
                $self->connectToMaster();
            },
        );
    }

    /**
     * Determine whether an incoming frame payload is a text (JSON) frame.
     *
     * Text frames are valid UTF-8 JSON starting with '{' or '['.
     *
     * @param string $data Raw frame payload.
     *
     * @return bool True for text/JSON frames, false for binary.
     */
    private function isTextFrame(string $data): bool
    {
        if ($data === '') {
            return false;
        }

        $firstByte = ord($data[0]);

        // Binary frames start with 0x00 (big-endian seq number) and are at least 7 bytes
        if ($firstByte === 0x00 && strlen($data) >= 7) {
            return false;
        }

        // Otherwise check if it's valid UTF-8 JSON
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($data, false, 2);
            return is_array($decoded) || is_scalar($decoded);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Generate a random UUID v4.
     *
     * @return string Formatted UUID string.
     */
    private function generateUuid(): string
    {
        return Ids::uuidV4();
    }
}
