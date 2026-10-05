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
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\InvalidFrameTypeException;
use Phlix\Shared\Relay\RelayFrameType;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * Manages the persistent WebSocket connection from a leaf hub to the master hub.
 *
 * On leaf hubs this class:
 *   1. Connects to master hub's WS endpoint at
 *      `{scheme}://{master-host}[:{port}]/relay/federation/{leaf_hub_id}` —
 *      scheme and port come from the configured peer URL (M-8).
 *   2. Sends HUB_HELLO JSON frame on connect
 *   3. Handles HUB_HELLO_ACK (stores session; cross-checks the reported
 *      `master_hub_id` against the master peer's bound identity when one is
 *      known, binding it on first use). H-4: the ack is CRYPTOGRAPHICALLY
 *      verified first — its Ed25519 signature over the canonical
 *      {session_id, master_hub_id, nonce} string must check against the
 *      master peer row's registered public key before ANY of its claims are
 *      trusted; a forged/unsigned ack closes the link with backoff. On
 *      acceptance the leaf answers with HELLO_AUTH, proving possession of
 *      THIS hub's registered private key over {session_id, nonce,
 *      leaf_hub_id} (canonicals shared with the master via
 *      {@see FederationHandshake}).
 *   4. Sends HEARTBEAT (generic binary) every 15 seconds once verified
 *   5. Handles incoming DATA envelopes (library-share offers/revocations,
 *      ADMIN_DELEGATION payloads) — all refused before the channel is
 *      verified (H-4 leaf-side gate)
 *   6. Auto-reconnects with exponential backoff on disconnect — never after
 *      a deliberate disconnectFromMaster() (M-7); while federation is
 *      disabled the chain PARKS at the capped backoff (one gated re-check
 *      per ≤60 s, no TCP attempt) instead of dying, so re-enabling re-dials
 *      automatically without a restart or an explicit trigger
 *   7. Pushes local library share changes to master when connected
 *
 * @package Phlix\Hub\Federation
 */
class FederationPeerManager
{
    /**
     * Seconds to wait for HELLO_ACK after TCP connect before declaring the
     * link a zombie and dropping it so the reconnect path re-runs the
     * handshake (M-6 — previously isConnected() was true forever while the
     * master silently ignored a half-registered leaf).
     */
    private const int HELLO_ACK_TIMEOUT_SECONDS = 10;

    /**
     * Upper bound of the reconnect backoff ladder (seconds) — 5, 10, 20, 40,
     * then held here. ALSO the parked cadence during a
     * `federation.enabled=false` window: the reconnect tick's disabled branch
     * re-arms at exactly this delay, so a long off-window costs one wake per
     * cap — zero TCP attempts, no ladder restart (BEHAVIOR lane).
     */
    private const int RECONNECT_DELAY_CAP_SECONDS = 60;

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
     * True once the CURRENT disabled-window park has been announced on the
     * RELAY log. Throttles the hold message to one line per off-window —
     * reset when a tick proceeds past the enabled gate or the chain is torn
     * down deliberately — instead of one line every capped re-check.
     *
     * @var bool
     */
    private bool $reconnectHoldAnnounced = false;

    /**
     * True between a deliberate disconnectFromMaster() and the next explicit
     * connectToMaster(). scheduleReconnect() refuses to arm while set — the
     * close it performs fires its own onClose, which used to resurrect the
     * link the operator just dropped (M-7).
     *
     * @var bool
     */
    private bool $intentionalDisconnect = false;

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
     * Active reconnect timer ID (Workerman) — deleted on deliberate
     * disconnect instead of being orphaned until it fires (M-7).
     *
     * @var int|null
     */
    private ?int $reconnectTimerId = null;

    /**
     * Active HELLO_ACK timeout timer ID (Workerman).
     *
     * @var int|null
     */
    private ?int $ackTimeoutTimerId = null;

    /**
     * Local federation_peers row id of the master peer being dialed
     * (captured at dial time). Sessions, heartbeats and incoming offers are
     * addressed through this id — never re-derived from liveness status,
     * which is exactly what silently dropped leaf-side session bookkeeping
     * before (C-1(1)/H-2).
     *
     * @var string
     */
    private string $masterPeerId = '';

    /**
     * H-4 leaf-side channel gate: true only after a SIGNED HELLO_ACK verified
     * against the master peer's registered key AND this leaf's HELLO_AUTH proof
     * was sent. Every inbound DATA payload (offers, shares, admin-delegation)
     * is refused while false — transport liveness authorises nothing. Reset at
     * the start of every ceremony and on any link teardown.
     *
     * @var bool
     */
    private bool $channelVerified = false;

    /**
     * @param FederationHubRepository            $hubRepo       Hub + peer repository.
     * @param FederationSessionManager           $sessions      Federation session manager.
     * @param FederationLibraryShareRepository $libraryShares Library shares repository.
     * @param FederationAdminDelegationRepository $adminDel     Admin delegation repository.
     * @param AuditLogger                       $audit         Audit logger.
     * @param Ed25519KeyManager                 $keyManager    This leaf's Ed25519 keypair (HELLO_AUTH proof).
     * @param (callable(): bool)|null           $enabledResolver LIVE reader of
     *        `federation.enabled` (HubSettingsResolvers::bool, fail-safe to the
     *        boot flag); null keeps the pre-W5 always-on dial behavior.
     */
    public function __construct(
        private readonly FederationHubRepository $hubRepo,
        private readonly FederationSessionManager $sessions,
        private readonly FederationLibraryShareRepository $libraryShares,
        private readonly FederationAdminDelegationRepository $adminDel,
        private readonly AuditLogger $audit,
        private readonly Ed25519KeyManager $keyManager,
        // W5: LIVE reader of `federation.enabled` (HubSettingsResolvers::bool,
        // fail-safe to the boot flag). Null (unit tests) keeps the pre-W5
        // always-on behavior. Checked at dial time. Re-check law (BEHAVIOR
        // lane — lifts the one-shot death limitation recorded by the
        // docs-truth pass @5a048a6): a reconnect chain does NOT die during a
        // disabled window. The tick callback tests the gate itself and, while
        // closed, parks at RECONNECT_DELAY_CAP_SECONDS — one wake per cap,
        // zero TCP attempts — so re-enabling re-dials a link dropped
        // off-window within one capped tick, no restart or explicit trigger
        // needed. An explicit connectToMaster() refused while disabled arms
        // the same parked probe (idempotent; never while a socket is live or
        // the operator dropped the link — M-7). Links that survive the
        // off-window still re-admit traffic instantly (per-frame gates); the
        // ≤60s exponential backoff ladder on the ENABLED path is unchanged.
        private readonly mixed $enabledResolver = null,
    ) {
        $this->decoder = new FrameDecoder();
        $this->encoder = new FrameEncoder();
    }

    /**
     * Whether the federation subsystem currently dials peers.
     */
    private function federationEnabled(): bool
    {
        $resolver = $this->enabledResolver;

        return is_callable($resolver) ? $resolver() : true;
    }

    /**
     * Initiate the persistent WebSocket connection to the master hub.
     *
     * Only operates when this hub is configured as a leaf hub with an
     * active relay-enabled peer (the master). Idempotent — if already
     * connected this is a no-op. An explicit call re-arms auto-reconnect
     * after a deliberate disconnect.
     *
     * @return void
     */
    public function connectToMaster(): void
    {
        // W5 kill switch: no dials while federation is off. Deliberately
        // BEFORE the enabled path's state mutation (intentionalDisconnect
        // stays as-is) so toggling the setting cannot silently re-arm a link
        // the operator dropped (M-7). BEHAVIOR lane: the refusal no longer
        // lets the chain end silently — when nothing is live and no tick is
        // already armed, arm the parked disabled-hold probe (scheduleReconnect
        // owns the single-in-flight-timer guard). Outside a Workerman timer
        // runtime Timer::add throws — swallowed exactly like the src/Relay
        // sites, so in a plain CLI/unit context the observable behavior stays
        // the silent return it always was.
        if (!$this->federationEnabled()) {
            if ($this->masterConnection === null && !$this->intentionalDisconnect && !$this->reconnectScheduled) {
                try {
                    $this->scheduleReconnect();
                } catch (Throwable) {
                    // No Workerman runtime — nothing to arm; the next boot or
                    // explicit trigger dials as before.
                }
            }

            return;
        }

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

        // Explicit dial intent re-enables the auto-reconnect loop.
        $this->intentionalDisconnect = false;

        // C-1(1): dial ELIGIBLE peers (pending/connected/disconnected), not
        // 'connected' ones — status 'connected' only ever appears AFTER a
        // successful HELLO-ACK, so filtering on it could never bootstrap or
        // recover a link.
        $peers = $this->hubRepo->getDialablePeers();
        if ($peers === []) {
            return;
        }

        if (count($peers) > 1) {
            // M-8 fail-safe posture: the leaf protocol supports exactly one
            // master. Multiple non-suspended peers is an operator ambiguity;
            // the first (by name) is dialed, loudly.
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: multiple dialable peers, dialing first as master',
                ['peer_count' => count($peers), 'selected' => $peers[0]['id'] ?? null],
            );
        }

        /** @var array<string, mixed> $masterPeer */
        $masterPeer = $peers[0];
        /** @var string $masterPeerId */
        $masterPeerId = is_string($masterPeer['id'] ?? null) ? $masterPeer['id'] : '';
        /** @var string $masterUrl */
        $masterUrl = is_string($masterPeer['url'] ?? null) ? $masterPeer['url'] : '';

        if ($masterPeerId === '' || $masterUrl === '') {
            return;
        }

        /** @var string $leafHubId */
        $leafHubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
        if ($leafHubId === '') {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'FederationPeerManager: refusing to dial — this hub has no configured id',
            );
            return;
        }

        $this->masterPeerId = $masterPeerId;
        $this->establishConnection($this->buildMasterWsUrl($masterUrl, $leafHubId), $hubConfig);
    }

    /**
     * Build the master dial URL from the configured peer URL (M-8).
     *
     * Honors the configured scheme (http → ws, anything else → wss) and the
     * configured port; the old code hard-coded 'wss://' + bare host, so a
     * peer URL like `https://master.example:8443` silently dialed the wrong
     * endpoint.
     *
     * @param string $masterUrl Configured peer URL (e.g. https://host:port).
     * @param string $leafHubId This hub's own federation_hubs.id.
     *
     * @return string Fully-qualified WebSocket dial URL.
     */
    private function buildMasterWsUrl(string $masterUrl, string $leafHubId): string
    {
        $scheme = parse_url($masterUrl, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : '';
        $wsScheme = $scheme === 'http' ? 'ws' : 'wss';

        $host = parse_url($masterUrl, PHP_URL_HOST);
        $masterHost = is_string($host) && $host !== '' ? $host : $masterUrl;

        $port = parse_url($masterUrl, PHP_URL_PORT);
        $portSuffix = is_numeric($port) ? ':' . $port : '';

        return $wsScheme . '://' . $masterHost . $portSuffix
            . '/relay/federation/' . rawurlencode($leafHubId);
    }

    /**
     * Actively disconnect from the master hub and cancel timers.
     *
     * Deliberate: the flag is set BEFORE the close so the connection's own
     * onClose callback cannot schedule a reconnect, and any already-armed
     * reconnect timer is deleted rather than left to fire (M-7).
     *
     * @return void
     */
    public function disconnectFromMaster(): void
    {
        $this->intentionalDisconnect = true;
        $this->cancelHeartbeatTimer();
        $this->cancelAckTimeoutTimer();

        if ($this->reconnectTimerId !== null) {
            try {
                Timer::del($this->reconnectTimerId);
            } catch (Throwable) {
                // Already fired or invalid.
            }
            $this->reconnectTimerId = null;
        }

        if ($this->masterConnection !== null) {
            try {
                $this->masterConnection->close();
            } catch (Throwable) {
                // Ignore close errors
            }
            $this->masterConnection = null;
        }

        $this->sessionId = null;
        $this->masterPeerId = '';
        $this->channelVerified = false;
        $this->reconnectScheduled = false;
        $this->reconnectHoldAnnounced = false;
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

        // M-5: every offer carries `peer_id` = THIS hub's own
        // federation_hubs.id (the originating identity). The master rewrites
        // it to its local peer-row FK before persisting; a local row id from
        // this database would be meaningless — and FK-dangerous — over there.
        $hubConfig = $this->hubRepo->getHubConfig();
        /** @var string $originHubId */
        $originHubId = is_array($hubConfig) && is_string($hubConfig['id'] ?? null)
            ? $hubConfig['id']
            : '';
        if ($originHubId === '') {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: cannot push share without a configured hub id',
                ['share_id' => $shareId],
            );
            return;
        }

        $payload = json_encode([
            'shares' => [
                [
                    'id' => $shareId,
                    'peer_id' => $originHubId,
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
            return;
        }

        // M-6: TCP up ≠ handshake done. If no HELLO_ACK lands within the
        // timeout the link is a zombie (isConnected() true, session absent,
        // master already moved on) — drop it so onClose re-runs the handshake.
        $connForTimeout = $this->masterConnection;
        $selfRef = $this;
        $this->ackTimeoutTimerId = Timer::add(
            self::HELLO_ACK_TIMEOUT_SECONDS,
            static function () use ($selfRef, $connForTimeout): void {
                $selfRef->clearAckTimeoutTimerId();
                $selfRef->failHelloTimeout($connForTimeout);
            },
            [],
            false,
        );
    }

    /**
     * HELLO_ACK timeout fired: log and close the half-open connection.
     *
     * @param AsyncTcpConnection $conn Connection that failed to complete the handshake.
     *
     * @internal Visible for tests; not part of the public dial API.
     */
    public function failHelloTimeout(AsyncTcpConnection $conn): void
    {
        if ($this->sessionId !== null || $this->masterConnection !== $conn) {
            return; // Handshake completed (or connection already replaced).
        }

        LoggerFactory::get(LogChannels::RELAY)->warning(
            'FederationPeerManager: HELLO_ACK timeout — dropping half-open connection',
            ['timeout_seconds' => self::HELLO_ACK_TIMEOUT_SECONDS],
        );

        try {
            $conn->close();
        } catch (Throwable) {
            $this->masterConnection = null;
            $this->scheduleReconnect();
        }
    }

    /**
     * Clear the ack-timeout timer id slot once the one-shot fires.
     *
     * @internal
     */
    public function clearAckTimeoutTimerId(): void
    {
        $this->ackTimeoutTimerId = null;
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

        // A new ceremony starts untrusted (H-4): nothing carried over from
        // the previous handshake — especially its verified stamp.
        $this->channelVerified = false;

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
     * Handle HUB_HELLO_ACK — verify the master's signature, then establish
     * the session, answer with our own HELLO_AUTH proof, start heartbeat.
     *
     * M-8: the ack's `master_hub_id` is cross-checked against the master
     * peer's bound identity (`leaf_hub_id`, migration 046). A mismatch means
     * we are talking to a hub that is not the one this peer row represents —
     * the link is refused, never silently adopted. Unbound → bind on first
     * use.
     *
     * H-4: the ack is trusted ONLY after its Ed25519 `signature` verifies
     * over the canonical {session_id, master_hub_id, nonce} string against
     * the master peer row's registered public key. Unsigned, malformed or
     * foreignly-signed acks are refused before any of the ack's claims
     * (identity binding included) touch state — close + audit-fail + backoff.
     *
     * @param array<string, mixed> $msg Decoded JSON payload.
     *
     * @return void
     */
    private function handleHelloAck(array $msg): void
    {
        $this->cancelAckTimeoutTimer();

        /** @var mixed $sessionIdRaw */
        $sessionIdRaw = $msg['session_id'] ?? null;
        $sessionId = is_string($sessionIdRaw) ? $sessionIdRaw : null;

        /** @var mixed $rawMasterHubId */
        $rawMasterHubId = $msg['master_hub_id'] ?? null;
        $ackHubId = is_string($rawMasterHubId) ? $rawMasterHubId : '';

        /** @var mixed $rawNonce */
        $rawNonce = $msg['nonce'] ?? null;
        $nonce = is_string($rawNonce) ? $rawNonce : '';

        /** @var mixed $rawSignature */
        $rawSignature = $msg['signature'] ?? null;
        $signature = is_string($rawSignature) ? $rawSignature : '';

        $hubConfig = $this->hubRepo->getHubConfig();
        if ($hubConfig === null) {
            return;
        }

        /** @var string $hubId */
        $hubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';

        if ($this->masterPeerId === '') {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: HELLO_ACK without a dialed peer — ignored',
            );
            $this->audit->logHubConnect($hubId, 'master', $ackHubId, false);
            return;
        }

        $masterPeer = $this->hubRepo->getPeerById($this->masterPeerId);
        if ($masterPeer === null) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'FederationPeerManager: HELLO_ACK for unknown peer — closing',
                ['master_peer_id' => $this->masterPeerId],
            );
            $this->closeLinkAndReschedule();
            return;
        }

        /** @var string $boundHubId */
        $boundHubId = is_string($masterPeer['leaf_hub_id'] ?? null) ? $masterPeer['leaf_hub_id'] : '';
        /** @var string $peerName */
        $peerName = is_string($masterPeer['name'] ?? null) ? $masterPeer['name'] : 'master';
        /** @var string $masterPublicKey */
        $masterPublicKey = is_string($masterPeer['public_key'] ?? null) ? $masterPeer['public_key'] : '';

        // H-4: proof before claims. A signed handshake is the ONLY thing that
        // makes this ack the master's word — until it verifies, not one of
        // its assertions (identity binding included) may mutate state.
        if ($sessionId === null || $sessionId === '' || $nonce === '' || $signature === '') {
            $this->refuseHelloAck($hubId, $peerName, $ackHubId, 'hello_ack_missing_proof_fields');
            return;
        }

        $canonical = FederationHandshake::helloAckCanonical($sessionId, $ackHubId, $nonce);
        if (!FederationHandshake::verify($canonical, $signature, $masterPublicKey)) {
            $this->refuseHelloAck($hubId, $peerName, $ackHubId, 'hello_ack_signature_invalid');
            return;
        }

        if ($boundHubId !== '' && $ackHubId !== '' && $boundHubId !== $ackHubId) {
            $this->refuseHelloAck($hubId, $peerName, $ackHubId, 'hello_ack_identity_mismatch');
            return;
        }

        if ($boundHubId === '' && $ackHubId !== '') {
            $this->hubRepo->setPeerLeafHubId($this->masterPeerId, $ackHubId);
        }

        $this->sessionId = $sessionId;

        // Register the LOCAL mirror session row under the dialed peer id.
        // (The old code re-read getConnectedPeers() — empty for a 'pending'
        // master row at first ack — so leaf-side session bookkeeping never
        // existed at bootstrap. H-2/C-1(1).)
        $this->sessions->registerSession($this->masterPeerId);

        $this->audit->logHubConnect($hubId, $peerName, $ackHubId, true);

        // Reset backoff on successful handshake
        $this->reconnectDelaySeconds = 5;
        $this->reconnectScheduled = false;

        // H-4 leaf leg: prove possession of THIS hub's registered private key
        // over the same session nonce, then open the channel for payloads.
        $this->sendHelloAuth($hubId, $sessionId, $nonce);
        $this->channelVerified = true;

        // Start heartbeat timer
        $this->startHeartbeatTimer();
    }

    /**
     * Loud, audited refusal of a HELLO_ACK that failed the H-4 proof or the
     * M-8 identity cross-check — drops the link and arms exponential backoff.
     *
     * @param string $hubId    This leaf's hub UUID.
     * @param string $peerName Master peer display name.
     * @param string $ackHubId Master hub id claimed by the ack.
     * @param string $reason   Machine-readable refusal reason.
     */
    private function refuseHelloAck(string $hubId, string $peerName, string $ackHubId, string $reason): void
    {
        LoggerFactory::get(LogChannels::RELAY)->error(
            'FederationPeerManager: HELLO_ACK refused — ' . $reason,
            [
                'peer_id' => $this->masterPeerId,
                'ack_hub_id' => $ackHubId,
            ],
        );
        $this->audit->logHubConnect($hubId, $peerName, $ackHubId, false, $reason);
        $this->closeLinkAndReschedule();
    }

    /**
     * Send HELLO_AUTH — the leaf's proof-of-key over the canonical
     * {session_id, nonce, leaf_hub_id} string (H-4 ceremony, leaf leg).
     *
     * The master verifies this against OUR public key as registered on its
     * peer row; only then does it flip the channel to VERIFIED and release
     * share pushes. Signing the SAME canonical bytes the master reconstructs
     * is guaranteed by the shared {@see FederationHandshake} helper — the two
     * ends cannot drift their wire formats.
     *
     * @param string $ownHubId  This leaf's hub UUID.
     * @param string $sessionId Session UUID from the verified ack.
     * @param string $nonce     Nonce from the verified ack (echoed in proof).
     */
    private function sendHelloAuth(string $ownHubId, string $sessionId, string $nonce): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        $keyPair = $this->keyManager->getOrCreateKeyPair();
        $signature = FederationHandshake::sign(
            FederationHandshake::helloAuthCanonical($sessionId, $nonce, $ownHubId),
            $keyPair['private'],
        );

        $payload = json_encode([
            'type' => 'hub_hello_auth',
            'leaf_hub_id' => $ownHubId,
            'session_id' => $sessionId,
            'signature' => $signature,
        ], JSON_THROW_ON_ERROR);

        $this->masterConnection->send($payload);
    }

    /**
     * Close the current leaf→master link and let the reconnect path retry
     * (used by the ack identity refusal and the HELLO_ACK timeout).
     *
     * @return void
     */
    private function closeLinkAndReschedule(): void
    {
        $this->cancelAckTimeoutTimer();
        $this->cancelHeartbeatTimer();
        $this->channelVerified = false;

        if ($this->masterConnection !== null) {
            $conn = $this->masterConnection;
            $this->masterConnection = null;
            try {
                $conn->close();
            } catch (Throwable) {
                // Already gone.
            }
            // onClose may or may not have fired depending on where the close
            // happened in the lifecycle — schedule defensively; the
            // reconnectScheduled flag keeps it single-shot.
            $this->scheduleReconnect();
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

        // H-2: the LOCAL mirror session row is addressed by peer id — the
        // master's session UUID (kept in $this->sessionId for diagnostics)
        // is a row id in the MASTER's database and matches nothing here.
        if ($this->masterPeerId !== '') {
            try {
                $this->sessions->touchHeartbeatByPeerId($this->masterPeerId);
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
        $this->channelVerified = false;
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
        // H-4 unverified-channel gate (leaf side): a live socket alone proves
        // nothing — offers, revocations and admin-delegation payloads are all
        // dropped until the signed handshake ceremony completes.
        if (!$this->channelVerified) {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: DATA frame dropped — channel not yet verified',
                ['peer_id' => $this->masterPeerId],
            );
            $this->audit->logFailedAuth('federation_data_frame_before_verification', [
                'peer_id' => $this->masterPeerId,
            ]);
            return;
        }

        try {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        // Handle library share updates (incoming offers from master).
        if (isset($data['shares']) && is_array($data['shares'])) {
            /** @var mixed $share */
            foreach ($data['shares'] as $share) {
                if (!is_array($share)) {
                    continue;
                }

                $offer = $this->rebaseOfferIdentity($share);
                if ($offer === null) {
                    continue;
                }

                $this->libraryShares->handleIncomingOffer($offer);
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
     * Rewrite a master-pushed offer's wire `peer_id` to this database's
     * local FK (the master peer row we dialed) — M-5.
     *
     * The wire value is the master's OWN hub id; it is used only to
     * cross-check against the bound identity from HELLO_ACK. A mismatch is
     * refused loudly instead of writing an offer against the wrong peer row.
     *
     * @param array<array-key, mixed> $offer Offer as received (JSON-decoded,
     *                                      so keys are array-key until the
     *                                      repository re-parses each field).
     *
     * @return array<array-key, mixed>|null Rebasing offer, or null to drop it.
     */
    private function rebaseOfferIdentity(array $offer): ?array
    {
        if ($this->masterPeerId === '') {
            return null;
        }

        $masterPeer = $this->hubRepo->getPeerById($this->masterPeerId);
        if ($masterPeer === null) {
            return null;
        }

        /** @var string $boundHubId */
        $boundHubId = is_string($masterPeer['leaf_hub_id'] ?? null) ? $masterPeer['leaf_hub_id'] : '';
        /** @var mixed $wirePeerId */
        $wirePeerId = $offer['peer_id'] ?? null;

        if (is_string($wirePeerId) && $boundHubId !== '' && $wirePeerId !== $boundHubId) {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: share offer skipped — peer_id mismatch',
                [
                    'peer_id' => $this->masterPeerId,
                    'bound_hub_id' => $boundHubId,
                    'wire_peer_id' => $wirePeerId,
                ],
            );
            return null;
        }

        // Stamp the local FK before returning — never forward the wire value
        // (M-5). The repository parses every field defensively, so the map
        // keeps its array-key shape through this boundary.
        $offer['peer_id'] = $this->masterPeerId;

        return $offer;
    }

    /**
     * Handle an incoming library share revoked notification from master.
     *
     * M-5: the master's outgoing-share row id IS the offer id it pushed to
     * us, so revocation drops the local offer row outright (the pending/
     * accepted/rejected enum has no 'revoked' state to degrade into).
     *
     * @param string $shareId Share UUID that was revoked.
     *
     * @return void
     */
    private function handleLibraryShareRevoked(string $shareId): void
    {
        // Scoped to the master's local peer row — offers from the master are
        // stamped with it at rebase time (:931), and a wire id from any OTHER
        // peer's row space must never delete this hub's offer rows.
        $this->libraryShares->deleteIncomingOffer($shareId, $this->masterPeerId);
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
     * Send a generic HEARTBEAT (0x06) binary frame to the master hub.
     *
     * Liveness on the federation link rides the envelope-neutral HEARTBEAT
     * frame, not the retired HUB_HEARTBEAT (0x0B) type — see the envelope
     * law in docs/websockets.md (`Federation wire types 0x09-0x0F —
     * RETIRED`; HEARTBEAT 0x06 carries liveness for every surface, line 64
     * of the frame-type table) and the @deprecated annotations on
     * RelayFrameType::HUB_HEARTBEAT in phlix-shared.
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

        // H-2: refresh the LOCAL mirror session row by peer id (see
        // handleHeartbeat for why the master's session UUID matches nothing
        // in this database).
        if ($this->masterPeerId !== '') {
            try {
                $this->sessions->touchHeartbeatByPeerId($this->masterPeerId);
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
     * Cancel the armed HELLO_ACK timeout (handshake completed or link gone).
     *
     * @return void
     */
    private function cancelAckTimeoutTimer(): void
    {
        if ($this->ackTimeoutTimerId !== null) {
            try {
                Timer::del($this->ackTimeoutTimerId);
            } catch (Throwable) {
                // One-shot already fired or invalid.
            }
            $this->ackTimeoutTimerId = null;
        }
    }

    /**
     * Schedule a reconnection attempt with exponential backoff.
     *
     * Backoff sequence: 5, 10, 20, 40, max RECONNECT_DELAY_CAP_SECONDS.
     *
     * M-7: a DELIBERATE disconnectFromMaster() closes the socket, which fires
     * this path through onClose — while the intentional flag is set, no
     * reconnect may be armed, and any already-armed one-shot is cancelled
     * when it finally fires.
     *
     * Disabled-window park (BEHAVIOR lane): when the tick fires while
     * `federation.enabled` is false, the callback re-arms at the cap instead
     * of letting the gated dial end the chain — one wake per cap, zero TCP
     * attempts, zero repository reads. The ENABLED-path ordering inside the
     * callback (clear flags → ladder increment → intentional check → dial)
     * is byte-preserved; the disabled check sits strictly between the M-7
     * guard and the dial, so every previously-reachable enabled sequence
     * still executes exactly the same statements in the same order.
     *
     * @return void
     */
    private function scheduleReconnect(): void
    {
        if ($this->intentionalDisconnect) {
            return;
        }

        // Prevent duplicate scheduling
        if ($this->reconnectScheduled) {
            return;
        }

        $this->reconnectScheduled = true;
        $this->cancelHeartbeatTimer();
        $this->cancelAckTimeoutTimer();

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
        $this->channelVerified = false;

        $delay = $this->reconnectDelaySeconds;
        $self = $this;

        try {
            $this->reconnectTimerId = Timer::add(
                $delay,
                static function () use ($self, $delay): void {
                    $self->reconnectTimerId = null;
                    $self->reconnectScheduled = false;
                    $self->reconnectDelaySeconds = min($delay * 2, self::RECONNECT_DELAY_CAP_SECONDS);

                    if ($self->intentionalDisconnect) {
                        return; // Operator dropped the link while we were waiting.
                    }

                    if (!$self->federationEnabled()) {
                        // Kill switch is down: park the chain at the cap rather
                        // than letting the gated dial be this chain's last tick.
                        $self->reconnectDelaySeconds = self::RECONNECT_DELAY_CAP_SECONDS;
                        $self->announceDisabledReconnectHold();
                        $self->scheduleReconnect();

                        return;
                    }

                    $self->reconnectHoldAnnounced = false;
                    $self->connectToMaster();
                },
                [],
                false,
            );
        } catch (Throwable $e) {
            // No Workerman timer runtime: roll the in-flight latch back before
            // propagating, so a swallowed arm attempt (the gated-dial site in
            // connectToMaster) can never strand the chain with
            // reconnectScheduled stuck true and no timer behind it.
            $this->reconnectScheduled = false;
            $this->reconnectTimerId = null;

            throw $e;
        }
    }

    /**
     * Announce — once per disabled window — that the reconnect chain is
     * parked at the capped backoff until federation is re-enabled.
     *
     * Deliberately a plain RELAY-channel log line, not an audit entry: the
     * enabled path's audit trail is untouched, the inbound
     * `FEDERATION_DISABLED` refusal law lives in FederationFrameHandler, and
     * the per-cap wake cadence must not spam. The reconnectHoldAnnounced
     * throttle resets when a tick proceeds past the gate (real dial attempt)
     * or the chain is torn down deliberately.
     *
     * @return void
     */
    private function announceDisabledReconnectHold(): void
    {
        if ($this->reconnectHoldAnnounced) {
            return;
        }

        $this->reconnectHoldAnnounced = true;

        LoggerFactory::get(LogChannels::RELAY)->info(
            'FederationPeerManager: reconnect chain parked — federation disabled; re-checking every '
            . self::RECONNECT_DELAY_CAP_SECONDS . 's, dials on the next tick after re-enable',
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
