<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrameType;
use Throwable;
use Workerman\Connection\ConnectionInterface;

use function json_decode;
use function json_encode;

/**
 * Handles incoming HUB_* frames on the master hub.
 *
 * Parses JSON text frames for HELLO and binary frames for HEARTBEAT,
 * HUB_DISCONNECTED and DATA (library-share offers pushed by a leaf).
 *
 * Identity model (C-1(3)): every WS route carries a hub-identity UUID in
 * the path; {@see FederationHubRepository::getPeerById()} resolves it
 * against BOTH the local peer row id and the peer-bound `leaf_hub_id`, so
 * the connection's transport identity always maps to exactly one local
 * peer row. Frame handlers address sessions/shares by that resolved LOCAL
 * row id — never by the raw path value.
 *
 * H-4 mutual proof-of-key: knowing a peer's public_key/identifiers is NOT
 * authentication — anyone can replay them. The handshake is now a signed
 * ceremony: HELLO_ACK carries a fresh session nonce plus an Ed25519
 * signature over the canonical ACK string made with THIS hub's keypair, and
 * the leaf must answer with HELLO_AUTH signing {session_id, nonce,
 * leaf_hub_id} with the private key belonging to its REGISTERED public_key.
 * Only after that proof verifies does the channel flip to VERIFIED in the
 * connection manager; until then every DATA payload (offers, shares,
 * admin-delegation) is dropped and audited. Canonical byte strings live in
 * {@see FederationHandshake} so both roles sign/verify the exact same form —
 * and the leaf's proof must arrive on the very socket still registered for
 * that peer: a late HELLO_AUTH replayed over a superseded connection is
 * refused ('handshake_stale_connection'), never stamped onto the replacement.
 *
 * @package Phlix\Hub\Federation
 */
final class FederationFrameHandler
{
    private FrameEncoder $encoder;

    /**
     * Statuses from which a HUB_HELLO is accepted. 'pending' is the first
     * hello; 'connected' re-attaches after a crash that never fired onClose
     * (registerSession retires the stale live row); 'disconnected' is the
     * normal reconnect after a clean drop or a reap. 'suspended' is the
     * operator kill-switch and stays rejected (C-1(2) — the old gate only
     * accepted 'pending', so no peer could EVER reconnect).
     */
    private const HELLO_ACCEPTED_STATUSES = ['pending', 'connected', 'disconnected'];

    /**
     * @param FederationHubRepository          $hubRepo        Hub repository for peer lookups.
     * @param FederationSessionManager         $sessions       Session manager for federation sessions.
     * @param FederationLibraryShareRepository $libraryShares  Library shares repository.
     * @param FederationConnectionManager      $connMgr        Connection manager for active WS connections.
     * @param AuditLogger                      $audit          Audit logger for federation events.
     * @param Ed25519KeyManager                $keyManager     This hub's Ed25519 keypair (master ACK signing).
     */
    public function __construct(
        private readonly FederationHubRepository $hubRepo,
        private readonly FederationSessionManager $sessions,
        private readonly FederationLibraryShareRepository $libraryShares,
        private readonly FederationConnectionManager $connMgr,
        private readonly AuditLogger $audit,
        private readonly Ed25519KeyManager $keyManager,
    ) {
        $this->encoder = new FrameEncoder();
    }

    /**
     * Handle an incoming text frame (HELLO / HELLO_ACK / ERROR).
     *
     * Returns null to keep the connection open, or a string error message
     * to send back and close the connection.
     *
     * The ARRIVING connection object is threaded through: the H-4 HELLO_AUTH
     * proof is bound to its carrier socket (identity-of-proof-carrier), so
     * the handler must know WHICH connection delivered the bytes instead of
     * re-resolving by hubId and stamping whatever is registered now.
     *
     * @param string              $hubId       Peer hub UUID (from route param).
     * @param string              $jsonPayload Raw JSON text frame payload.
     * @param ConnectionInterface $connection  The Workerman WS connection the frame arrived on.
     *
     * @return string|null Error message if the connection should be rejected, null otherwise.
     */
    public function handleTextFrame(string $hubId, string $jsonPayload, ConnectionInterface $connection): ?string
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
            'hub_hello_auth' => $this->handleHelloAuth($hubId, $decoded, $connection),
            default => null, // Unknown text frames are ignored
        };
    }

    /**
     * Handle an incoming binary frame.
     *
     * @param string $hubId     Peer hub UUID (from route param).
     * @param string $payload   Decoded binary payload.
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
            RelayFrameType::HEARTBEAT => $this->handleHeartbeat($hubId),
            RelayFrameType::DISCONNECTED => $this->handleDisconnected($hubId, $payload),
            // M-5: leaf → master library-share pushes arrive as DATA frames.
            // Previously a silent no-op that made one whole sync direction
            // non-functional.
            RelayFrameType::DATA => $this->handleDataFrame($hubId, $payload),
            default => null,
        };
    }

    /**
     * Handle HUB_HELLO from a connecting (or reconnecting) leaf hub.
     *
     * Validates the peer key lookup, cross-checks/binds the reported hub
     * identity, registers the session, and answers with a SIGNED HELLO_ACK
     * carrying a fresh single-use nonce (H-4). Share pushes and the
     * connect-audit are deferred to {@see handleHelloAuth()} — the channel
     * is transport-only until the leaf proves possession of its key.
     *
     * @param string               $leafHubId Peer hub UUID (from route).
     * @param array<string, mixed> $decoded   Decoded JSON payload.
     *
     * @return string|null Error message to reject, or null to accept.
     */
    private function handleHubHello(string $leafHubId, array $decoded): ?string
    {
        /** @var mixed $rawPublicKey */
        $rawPublicKey = $decoded['public_key'] ?? null;
        /** @var mixed $rawHubId */
        $rawHubId = $decoded['hub_id'] ?? null;

        if (!is_string($rawPublicKey) || $rawPublicKey === '') {
            return 'Invalid peer key';
        }

        // Look up peer by public key
        $peer = $this->hubRepo->getPeerByPublicKey($rawPublicKey);
        if ($peer === null) {
            return 'Invalid peer key';
        }

        // L-6: the leaf's own hub id is mandatory in HELLO — it is the value
        // the master binds to federation_peers.leaf_hub_id and the anchor of
        // the whole identity reconciliation (C-1(3)). Checked AFTER the key
        // lookup so an unknown key still fails as 'Invalid peer key'.
        if (!is_string($rawHubId) || $rawHubId === '') {
            return 'Missing hub_id';
        }

        /** @var string $peerId */
        $peerId = $peer['id'];
        /** @var string $peerStatus */
        $peerStatus = is_string($peer['status'] ?? null) ? $peer['status'] : 'pending';

        if ($peerStatus === 'suspended') {
            return 'Peer suspended';
        }

        if (!in_array($peerStatus, self::HELLO_ACCEPTED_STATUSES, true)) {
            return 'Peer not registered';
        }

        // Identity cross-check (L-6 / C-1(3)): once bound, the hub_id a peer
        // reports must never change — a mismatch means key/identity confusion
        // and the link is refused loudly instead of silently re-binding.
        /** @var string $boundHubId */
        $boundHubId = is_string($peer['leaf_hub_id'] ?? null) ? $peer['leaf_hub_id'] : '';
        if ($boundHubId !== '') {
            if ($boundHubId !== $rawHubId) {
                $this->log()->error('Federation HELLO rejected: hub_id does not match bound identity', [
                    'peer_id' => $peerId,
                    'bound_hub_id' => $boundHubId,
                    'reported_hub_id' => $rawHubId,
                ]);
                return 'Peer hub_id mismatch';
            }
        } else {
            $this->hubRepo->setPeerLeafHubId($peerId, $rawHubId);
        }

        // L-6: resolve the connection BEFORE registering anything, so a
        // missing registration can never leave an orphan live session row
        // behind with no ACK sent.
        $conn = $this->connMgr->getConnection($leafHubId);
        if ($conn === null) {
            return 'Connection not registered';
        }

        // Register session and get session ID (retires any stale live row)
        $sessionId = $this->sessions->registerSession($peerId);

        // Update peer timestamps
        $this->hubRepo->updatePeerStatus($peerId, 'connected');

        // Get master hub ID
        $hubConfig = $this->hubRepo->getHubConfig();
        /** @var string $masterHubId */
        $masterHubId = is_array($hubConfig)
            ? (is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '')
            : '';

        // H-4: mint a fresh single-use nonce for this ceremony. The leaf
        // signs it back in HELLO_AUTH; consumeHandshake() retires it, so a
        // replayed proof can never re-verify. registerSession above already
        // abandoned any handshake pending for this peer.
        $nonce = FederationHandshake::newNonce();
        $this->sessions->beginHandshake($sessionId, $peerId, $nonce);

        // Send SIGNED HELLO_ACK (master's proof of key possession).
        $this->sendHelloAck(
            $conn,
            $sessionId,
            $masterHubId,
            $nonce,
            ['library_shares', 'relay', 'admin_delegation'],
        );

        // Shares/audit wait for the leaf's HELLO_AUTH proof — until the
        // channel flips VERIFIED the link is inert (handleDataFrame drops).
        return null;
    }

    /**
     * Handle HELLO_AUTH from a leaf — the leaf half of the H-4 ceremony.
     *
     * The leaf proves possession of the private key belonging to its
     * REGISTERED public key by signing the canonical
     * {session_id, nonce, leaf_hub_id} string. The nonce must still be
     * pending (single-use: a second proof over the same session is refused),
     * must belong to THIS peer's ceremony, and the claimed identity must
     * match the bound one. The proof is additionally bound to its CARRIER:
     * the bytes must arrive on the socket that is still the registered
     * connection for this hub, so a late AUTH replayed over a superseded
     * socket can never vouch for its replacement. Success flips the
     * connection to VERIFIED, pushes pending shares, and audits the connect;
     * any failure closes the link with an audited refusal.
     *
     * @param string               $hubId      Peer hub UUID (from route).
     * @param array<string, mixed> $decoded    Decoded JSON payload.
     * @param ConnectionInterface  $connection The socket the AUTH bytes arrived on.
     *
     * @return string|null Error message to reject, or null to accept.
     */
    private function handleHelloAuth(string $hubId, array $decoded, ConnectionInterface $connection): ?string
    {
        /** @var mixed $rawSessionId */
        $rawSessionId = $decoded['session_id'] ?? null;
        /** @var mixed $rawSignature */
        $rawSignature = $decoded['signature'] ?? null;
        /** @var mixed $rawLeafHubId */
        $rawLeafHubId = $decoded['leaf_hub_id'] ?? null;

        if (
            !is_string($rawSessionId) || $rawSessionId === ''
            || !is_string($rawSignature) || $rawSignature === ''
            || !is_string($rawLeafHubId) || $rawLeafHubId === ''
        ) {
            return 'Invalid hello_auth payload';
        }

        $peer = $this->hubRepo->getPeerById($hubId);
        if ($peer === null) {
            return 'Unknown peer';
        }

        /** @var string $peerId */
        $peerId = $peer['id'];
        /** @var string $peerName */
        $peerName = is_string($peer['name'] ?? null) ? $peer['name'] : 'unknown';
        /** @var string $peerUrl */
        $peerUrl = is_string($peer['url'] ?? null) ? $peer['url'] : '';
        /** @var string $peerPublicKey */
        $peerPublicKey = is_string($peer['public_key'] ?? null) ? $peer['public_key'] : '';
        /** @var string $boundHubId */
        $boundHubId = is_string($peer['leaf_hub_id'] ?? null) ? $peer['leaf_hub_id'] : '';

        $conn = $this->connMgr->getConnection($hubId);
        if ($conn === null) {
            return 'Connection not registered';
        }

        // Identity-of-proof-carrier (H-4 defense-in-depth): the AUTH bytes
        // must arrive on the very socket that is STILL registered for this
        // hub. Resolving the stamp target by hubId alone would let a late
        // proof delivered over a SUPERSEDED socket verify its replacement.
        // Like the relay flow-control arms (phlix-server RelayConsumer), the
        // law is capture-the-object and compare by identity — a stale
        // carrier is inert. Checked BEFORE the nonce burn so a stale frame
        // cannot burn the live ceremony's proof, and markVerified's own
        // superseded-guard stays as the last line. Workerman's single-threaded
        // loop makes this check→stamp window atomic within the process.
        if ($conn !== $connection) {
            $this->refuseHelloAuth($peerId, $peerName, $peerUrl, 'handshake_stale_connection');
            return 'HELLO_AUTH refused: proof arrived on a superseded connection';
        }

        // Single-use nonce burn: unknown/expired session or an already
        // consumed nonce both land here (replay of a valid proof included).
        $pending = $this->sessions->consumeHandshake($rawSessionId);
        if ($pending === null || $pending['peer_id'] !== $peerId) {
            $this->refuseHelloAuth($peerId, $peerName, $peerUrl, 'handshake_unknown_or_replayed');
            return 'HELLO_AUTH refused: no pending handshake for this session';
        }

        // Identity binding: the proof must vouch for the bound hub id.
        if ($boundHubId === '' || $boundHubId !== $rawLeafHubId) {
            $this->refuseHelloAuth($peerId, $peerName, $peerUrl, 'handshake_identity_mismatch');
            return 'HELLO_AUTH refused: leaf_hub_id does not match the bound identity';
        }

        $canonical = FederationHandshake::helloAuthCanonical($rawSessionId, $pending['nonce'], $rawLeafHubId);
        if (!FederationHandshake::verify($canonical, $rawSignature, $peerPublicKey)) {
            $this->refuseHelloAuth($peerId, $peerName, $peerUrl, 'handshake_proof_failed');
            return 'HELLO_AUTH verification failed';
        }

        if (!$this->connMgr->markVerified($hubId, $conn)) {
            // Socket was replaced mid-ceremony; this proof vouches for a dead
            // connection and must not mark the successor verified.
            $this->refuseHelloAuth($peerId, $peerName, $peerUrl, 'handshake_connection_superseded');
            return 'HELLO_AUTH refused: connection superseded during handshake';
        }

        $this->log()->info('Federation channel verified', [
            'peer_id' => $peerId,
            'route_hub_id' => $hubId,
        ]);

        // Channel is live AND authenticated — release the deferred work.
        $hubConfig = $this->hubRepo->getHubConfig();
        /** @var string $masterHubId */
        $masterHubId = is_array($hubConfig)
            ? (is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '')
            : '';
        $this->pushLibrarySharesToLeaf($conn, $masterHubId, $peerId);

        $this->audit->logHubConnect($peerId, $peerName, $peerUrl, true);

        return null;
    }

    /**
     * One loud, audited refusal path for failed HELLO_AUTH proofs.
     *
     * @param string $peerId Local federation_peers.id.
     * @param string $peerName Peer display name.
     * @param string $peerUrl  Peer URL.
     * @param string $reason   Machine-readable refusal reason.
     */
    private function refuseHelloAuth(string $peerId, string $peerName, string $peerUrl, string $reason): void
    {
        $this->log()->error('Federation HELLO_AUTH refused', [
            'peer_id' => $peerId,
            'reason' => $reason,
        ]);
        $this->audit->logHubConnect($peerId, $peerName, $peerUrl, false, $reason);
        $this->audit->logFailedAuth('federation_handshake_' . $reason, ['peer_id' => $peerId]);
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
        // Leaf-side handling lives in FederationPeerManager::handleHelloAck().
    }

    /**
     * Handle a HUB_HEARTBEAT frame.
     *
     * H-2: the heartbeat must refresh the session row ADDRESSED BY PEER —
     * the old code passed the hub UUID to touchHeartbeat() which matches on
     * the session UUID, updated 0 rows, and let the reaper kill live links.
     *
     * Convergence: when no live session exists any more (the maintenance
     * worker reaped it while the socket stayed open), the master sends
     * DISCONNECTED and drops the WS so the leaf reconnects and re-hellos
     * instead of lingering as a zombie. (The reaper itself runs in a
     * different process and cannot close sockets; this is the in-process
     * detection point.)
     *
     * @param string $hubId Peer hub UUID (from route param).
     *
     * @return void
     */
    private function handleHeartbeat(string $hubId): void
    {
        $conn = $this->connMgr->getConnection($hubId);
        if ($conn === null) {
            return;
        }

        $peer = $this->hubRepo->getPeerById($hubId);
        if ($peer === null) {
            return;
        }

        /** @var string $peerId */
        $peerId = $peer['id'];

        if ($this->sessions->touchHeartbeatByPeerId($peerId)) {
            return;
        }

        $this->log()->warning('Federation heartbeat on reaped session — closing zombie link', [
            'peer_id' => $peerId,
            'route_hub_id' => $hubId,
        ]);

        $frame = $this->encoder->encode(
            RelayFrameType::DISCONNECTED,
            0,
            json_encode(['reason' => 'session_expired'], JSON_THROW_ON_ERROR),
        );

        $this->connMgr->removeConnectionByConn($conn);
        try {
            $conn->send($frame);
        } catch (Throwable) {
            // Socket already gone — the close below is the authoritative step.
        }

        try {
            $conn->close();
        } catch (Throwable) {
            // Already closed.
        }
    }

    /**
     * Handle a HUB_DISCONNECTED frame (leaf says goodbye).
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

        // Identity-scoped unmap so a racing close cannot evict a newer conn.
        $this->connMgr->removeConnectionByConn($conn);

        // Find and close the session for the RESOLVED local peer row.
        $peer = $this->hubRepo->getPeerById($hubId);
        if ($peer !== null) {
            /** @var string $peerId */
            $peerId = $peer['id'];
            $session = $this->sessions->getActiveSession($peerId);
            if ($session !== null) {
                /** @var string $sessionId */
                $sessionId = is_string($session['id']) ? $session['id'] : '';
                if ($sessionId !== '') {
                    $this->sessions->closeSession($sessionId);
                }
            }
            /** @var string $peerName */
            $peerName = is_string($peer['name'] ?? null) ? $peer['name'] : 'unknown';
            $this->audit->logHubDisconnect($peerId, $peerName, $reason);
        }

        try {
            $conn->close();
        } catch (Throwable) {
            // Already closed.
        }
    }

    /**
     * React to a federation WS connection closing (called by the controller's
     * onClose so DB state converges with socket state — M-6).
     *
     * The removal is identity-checked: a late close from a SUPERSEDED
     * connection must neither unmap the new registration nor mark the peer
     * disconnected while the new link is live.
     *
     * @param string              $hubId Peer hub UUID the connection was dialed under.
     * @param ConnectionInterface $conn  The Workerman WS connection that closed.
     *
     * @return void
     */
    public function handleConnectionClosed(string $hubId, ConnectionInterface $conn): void
    {
        if (!$this->connMgr->removeConnectionByConn($conn)) {
            return; // Stale close for a replaced connection — leave state alone.
        }

        $peer = $this->hubRepo->getPeerById($hubId);
        if ($peer === null) {
            return;
        }

        /** @var string $peerId */
        $peerId = $peer['id'];

        $session = $this->sessions->getActiveSession($peerId);
        if ($session !== null) {
            /** @var string $sessionId */
            $sessionId = is_string($session['id'] ?? null) ? $session['id'] : '';
            if ($sessionId !== '') {
                $this->sessions->closeSession($sessionId); // Peer → 'disconnected'.
            }
        } elseif (is_string($peer['status'] ?? null) && $peer['status'] === 'connected') {
            $this->hubRepo->updatePeerStatus($peerId, 'disconnected');
        }

        /** @var string $peerName */
        $peerName = is_string($peer['name'] ?? null) ? $peer['name'] : 'unknown';
        $this->audit->logHubDisconnect($peerId, $peerName, 'connection_closed');
    }

    /**
     * Send a SIGNED HELLO_ACK to a newly connected leaf hub (H-4 master leg).
     *
     * The signature is an Ed25519 detached signature (base64) over the
     * canonical ACK string {@see FederationHandshake::helloAckCanonical()},
     * made with this hub's keypair. The leaf verifies it against the master
     * peer row's registered public key before trusting anything the ACK
     * asserts — an unsigned or foreignly-signed ACK is refused with backoff.
     *
     * @param ConnectionInterface $conn          Leaf WS connection.
     * @param string              $sessionId     Federation session UUID.
     * @param string              $masterHubId   This hub's UUID ('' when unconfigured).
     * @param string              $nonce         Fresh single-use handshake nonce.
     * @param array<string>       $capabilities  Supported federation features.
     *
     * @return void
     */
    private function sendHelloAck(
        ConnectionInterface $conn,
        string $sessionId,
        string $masterHubId,
        string $nonce,
        array $capabilities,
    ): void {
        $keyPair = $this->keyManager->getOrCreateKeyPair();
        $signature = FederationHandshake::sign(
            FederationHandshake::helloAckCanonical($sessionId, $masterHubId, $nonce),
            $keyPair['private'],
        );

        $payload = json_encode([
            'type' => 'hub_hello_ack',
            'session_id' => $sessionId,
            'master_hub_id' => $masterHubId,
            'role' => 'master',
            'capabilities' => $capabilities,
            'nonce' => $nonce,
            'signature' => $signature,
        ], JSON_THROW_ON_ERROR);

        $conn->send($payload);
    }

    /**
     * Push the active library shares TARGETED AT one peer to its leaf WS.
     *
     * M-5: each offer carries `peer_id` = THIS hub's own federation_hubs.id
     * (the offering identity). The leaf rewrites it to its LOCAL peer row id
     * of the master before persisting — a wire id from one hub's row space is
     * meaningless as a foreign key in the other's.
     *
     * Misdelivery guard: the query filters on the LOCAL peer row this
     * connection resolved to at HELLO. An unfiltered push used to broadcast
     * every leaf's shares to every connecting peer, rewriting each offer's
     * identity to the wrong target — shares meant for A landing in B's
     * offer inbox.
     *
     * @param ConnectionInterface $conn          Leaf WS connection.
     * @param string              $originHubId   This (master) hub's own UUID.
     * @param string              $targetPeerId  Local federation_peers.id of the connecting leaf.
     *
     * @return void
     */
    private function pushLibrarySharesToLeaf(ConnectionInterface $conn, string $originHubId, string $targetPeerId): void
    {
        $activeShares = $this->libraryShares->getActiveOutgoingSharesForPeer($targetPeerId);
        if ($activeShares === []) {
            return;
        }

        if ($originHubId === '') {
            $this->log()->warning('Federation share push skipped: master hub has no configured id');
            return;
        }

        $sharePayload = json_encode([
            'shares' => array_map(
                /** @param array<string, mixed> $share @return array<string, mixed> */
                static fn (array $share): array => [
                    'id' => $share['id'],
                    // Wire identity: the ORIGINATING hub's own id (see docblock).
                    'peer_id' => $originHubId,
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

    /**
     * Handle a DATA frame from a leaf — library-share offers and revocations.
     *
     * M-5 (was a no-op that made leaf → master share sync non-functional).
     * The sender's LOCAL peer row is resolved from the transport identity
     * (the route id this connection registered under) and used as the offer
     * FK — the wire `peer_id` is treated only as a cross-check against the
     * bound `leaf_hub_id`, never trusted as a row reference.
     *
     * H-4 unverified-channel gate: offers/shares/admin payloads are dropped
     * (and audited) until this socket completed the mutual proof ceremony —
     * transport liveness alone authorises nothing.
     *
     * @param string $hubId   Peer hub UUID (route).
     * @param string $payload Raw JSON payload.
     *
     * @return void
     */
    private function handleDataFrame(string $hubId, string $payload): void
    {
        if (!$this->connMgr->isVerified($hubId)) {
            $this->log()->warning('Federation DATA frame dropped: channel not yet verified', [
                'route_hub_id' => $hubId,
            ]);
            $this->audit->logFailedAuth('federation_data_frame_before_verification', [
                'route_hub_id' => $hubId,
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

        $peer = $this->hubRepo->getPeerById($hubId);
        if ($peer === null) {
            return;
        }

        /** @var string $peerId */
        $peerId = $peer['id'];
        /** @var string $boundHubId */
        $boundHubId = is_string($peer['leaf_hub_id'] ?? null) ? $peer['leaf_hub_id'] : '';

        if (isset($data['shares']) && is_array($data['shares'])) {
            /** @var mixed $share */
            foreach ($data['shares'] as $share) {
                if (!is_array($share)) {
                    continue;
                }

                /** @var mixed $wirePeerId */
                $wirePeerId = $share['peer_id'] ?? null;
                if (is_string($wirePeerId) && $boundHubId !== '' && $wirePeerId !== $boundHubId) {
                    $this->log()->warning('Federation share offer skipped: peer_id mismatch', [
                        'peer_id' => $peerId,
                        'bound_hub_id' => $boundHubId,
                        'wire_peer_id' => $wirePeerId,
                    ]);
                    continue;
                }

                /** @var array<string, mixed> $offer */
                $offer = $share;
                $offer['peer_id'] = $peerId; // Local FK, resolved from transport.
                $this->libraryShares->handleIncomingOffer($offer);
            }
        }

        // Leaf revoked one of its outgoing shares → drop the local offer row.
        // Scoped by the transport-resolved local peer FK: a wire share id is
        // only unique per offering peer, never globally (item: hardening).
        if (isset($data['share_id']) && is_string($data['share_id'])) {
            $this->libraryShares->deleteIncomingOffer($data['share_id'], $peerId);
        }
    }

    /**
     * Relay-channel logger (the federation WS rides the relay transport).
     */
    private function log(): StructuredLogger
    {
        return LoggerFactory::get(LogChannels::RELAY);
    }
}
