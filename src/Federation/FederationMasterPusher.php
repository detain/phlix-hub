<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrameType;
use Throwable;

use function json_encode;

/**
 * Pushes master-side library-share events to a leaf's live connection.
 *
 * The leaf side of share sync lives in FederationPeerManager (it dials the
 * master, so it owns `masterConnection`); the master side only had the
 * hello-time replay in FederationFrameHandler. Creating or revoking a share
 * on the master therefore never reached an already-connected leaf until the
 * leaf reconnected. This class closes that hole: the admin controller calls
 * pushOffer()/pushRevocation() right after persisting, and the frame lands
 * on the leaf's existing WS over FederationConnectionManager.
 *
 * Wire formats mirror the leaf→master direction exactly (same DATA frame,
 * seq 0, same JSON shape) so the leaf's FederationPeerManager::handleData()
 * parses both roles' pushes with one code path:
 *  - offer:      {"shares":[{id, peer_id, library_id, library_name,
 *                permission, status}]}  with peer_id = THIS hub's own id
 *  - revocation: {"share_id": "<uuid>"}
 *
 * Connections are keyed by the leaf's OWN hub uuid (its `leaf_hub_id`
 * binding), never the local peer row id — resolve() translates.
 *
 * H-4: pushes require the target channel to be VERIFIED (mutual Ed25519
 * handshake completed in FederationFrameHandler/FederationWorker) — an
 * unverified socket gets nothing but a refused, logged push. Note also that
 * FederationConnectionManager is process-local: the :8800 HTTP worker and
 * the :8805 FederationWorker hold separate connection maps. A LOCAL-first
 * triage therefore runs in-process only when THIS process actually holds the
 * verified socket; otherwise the intent crosses the broker via
 * {@see FederationPushBridge} and the :8805 process re-applies the same
 * verified law before writing ({@see FederationPushDispatcher}). Transport
 * moves intent, never authority.
 *
 * @package Phlix\Hub\Federation
 */
// Not final: the controller test-suite mocks this seam, same as
// FederationPeerManager. FederationConnectionManager stays final.
class FederationMasterPusher
{
    private FrameEncoder $encoder;

    public function __construct(
        private readonly FederationHubRepository $hubRepo,
        private readonly FederationConnectionManager $connMgr,
        private readonly ?FederationPushBridge $bridge = null,
    ) {
        $this->encoder = new FrameEncoder();
    }

    /**
     * Push a newly created outgoing share to its target leaf.
     *
     * @param string               $targetPeerRowId Local federation_peers.id the share was created for.
     * @param array<string, mixed> $shareRow        The persisted federation_library_shares row.
     *
     * @return bool True when the frame was written to a live connection.
     */
    public function pushOffer(string $targetPeerRowId, array $shareRow): bool
    {
        $originHubId = $this->ownHubId();
        if ($originHubId === null) {
            return false;
        }

        $leafHubId = $this->resolve($targetPeerRowId, 'push share offer');
        if ($leafHubId === null) {
            return false;
        }

        /** @var string $shareId */
        $shareId = $shareRow['id'] ?? '';
        if ($shareId === '') {
            $this->log()->warning('Federation master push skipped: share row has no id');
            return false;
        }

        $payload = json_encode([
            'shares' => [
                [
                    'id' => $shareId,
                    // Wire identity: this hub's own uuid (the leaf rebases it
                    // to its local master-peer FK — C-1/M-5).
                    'peer_id' => $originHubId,
                    'library_id' => $this->strField($shareRow, 'library_id', ''),
                    'library_name' => $this->strField($shareRow, 'library_name', ''),
                    'permission' => $this->strField($shareRow, 'permission', 'read'),
                    'status' => 'active',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // LOCAL-first: when THIS process owns the verified socket (the only
        // process that can be the :8805 worker itself) write directly. In an
        // HTTP worker the map is always empty here, so the intent crosses the
        // broker and the :8805 dispatcher re-passes the verified gate itself.
        if ($this->connMgr->isVerified($leafHubId)) {
            return $this->send($leafHubId, RelayFrameType::DATA, $payload);
        }

        if ($this->bridge !== null) {
            return $this->bridge->dispatch(FederationPushProtocol::ACTION_OFFER, $leafHubId, $payload);
        }

        $this->log()->warning('Federation master offer push skipped: channel not verified', [
            'peer_id' => $targetPeerRowId,
        ]);
        return false;
    }

    /**
     * Push a share revocation to the leaf that holds the offer, so its
     * accepted row converges immediately instead of surviving until the
     * next hello replay window.
     *
     * @param string $targetPeerRowId Local federation_peers.id owning the share.
     * @param string $shareId         Revoked share UUID.
     *
     * @return bool True when the frame was written to a live connection.
     */
    public function pushRevocation(string $targetPeerRowId, string $shareId): bool
    {
        if ($shareId === '') {
            $this->log()->warning('Federation master revocation push skipped: empty share id');
            return false;
        }

        $leafHubId = $this->resolve($targetPeerRowId, 'push share revocation');
        if ($leafHubId === null) {
            return false;
        }

        $payload = json_encode(['share_id' => $shareId], JSON_THROW_ON_ERROR);

        // LOCAL-first triage, same law as pushOffer(): the verified gate is
        // re-passed by the dispatcher in the process that owns the stamp.
        if ($this->connMgr->isVerified($leafHubId)) {
            return $this->send($leafHubId, RelayFrameType::DATA, $payload);
        }

        if ($this->bridge !== null) {
            return $this->bridge->dispatch(FederationPushProtocol::ACTION_REVOCATION, $leafHubId, $payload);
        }

        $this->log()->warning('Federation master revocation push skipped: channel not verified', [
            'peer_id' => $targetPeerRowId,
        ]);
        return false;
    }

    /**
     * Goodbye a peer's live WS before its row is deleted (deletePeer path).
     *
     * Sends HUB_DISCONNECTED{reason:'peer_deleted'}, unmaps the connection,
     * and closes the socket so the leaf treats the link as torn down rather
     * than silently holding a frame-pipe to a removed peer. No-op success
     * when the peer has no live connection.
     *
     * @param string $peerRowId Local federation_peers.id being deleted.
     *
     * @return bool True when there was nothing to close or the close ran.
     */
    public function closePeerConnection(string $peerRowId): bool
    {
        $leafHubId = $this->resolve($peerRowId, 'close peer connection');
        if ($leafHubId === null) {
            return true;
        }

        $conn = $this->connMgr->getConnection($leafHubId);
        if ($conn === null) {
            // No socket in THIS process. When a bridge exists the live link may
            // still be held by the :8805 worker — send the teardown intent
            // there (the dispatcher replies true for "nothing live to close",
            // the same answer this branch gives locally). Without a bridge the
            // historical no-op stands.
            if ($this->bridge !== null) {
                return $this->bridge->dispatch(FederationPushProtocol::ACTION_CLOSE_PEER, $leafHubId, '');
            }

            return true;
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

        return true;
    }

    /**
     * Translate a local peer row id into the leaf-hub connection key.
     *
     * @param string $peerRowId Local federation_peers.id.
     * @param string $purpose   Log context phrase.
     *
     * @return string|null The bound leaf_hub_id, or null (logged) when the
     *                     row is unknown or not yet bound.
     */
    private function resolve(string $peerRowId, string $purpose): ?string
    {
        if ($peerRowId === '') {
            return null;
        }

        $peer = $this->hubRepo->getPeerById($peerRowId);
        if ($peer === null) {
            $this->log()->warning("Federation master push skipped ({$purpose}): unknown peer", [
                'peer_id' => $peerRowId,
            ]);
            return null;
        }

        $leafHubId = $peer['leaf_hub_id'] ?? '';
        if (!is_string($leafHubId) || $leafHubId === '') {
            // Pre-bootstrap peer: never dialed, nothing live to push to.
            $this->log()->debug("Federation master push skipped ({$purpose}): peer not bound", [
                'peer_id' => $peerRowId,
            ]);
            return null;
        }

        return $leafHubId;
    }

    /**
     * This hub's own wire identity (federation_hubs.id).
     *
     * @return string|null Null (logged) when the hub row has no id.
     */
    private function ownHubId(): ?string
    {
        $hubConfig = $this->hubRepo->getHubConfig();
        $ownId = is_array($hubConfig) ? ($hubConfig['id'] ?? null) : null;
        if (!is_string($ownId) || $ownId === '') {
            $this->log()->warning('Federation master push skipped: master hub has no configured id');
            return null;
        }

        return $ownId;
    }

    /**
     * Encode one binary relay frame and write it to a leaf connection.
     *
     * LOCAL PATH ONLY: FederationConnectionManager is PROCESS-LOCAL, so this
     * writes when the calling process IS the :8805 FederationWorker (or a test
     * stands up the map locally). HTTP-worker callers reach the socket through
     * the {@see FederationPushBridge} seam in the local-first triage above the
     * send() call sites — never from here.
     *
     * @param string          $leafHubId Connection key (leaf's own hub uuid).
     * @param RelayFrameType  $type      Frame type.
     * @param string          $payload   JSON payload.
     *
     * @return bool True when the connection existed and the write succeeded.
     */
    private function send(string $leafHubId, RelayFrameType $type, string $payload): bool
    {
        $frame = $this->encoder->encode($type, 0, $payload);

        return $this->connMgr->sendTo($leafHubId, $frame);
    }

    /**
     * Read a string column out of a raw row without mixed-to-string casts.
     *
     * @param array<string, mixed> $row      Repository row.
     * @param string               $key      Column name.
     * @param string               $fallback Value when the column is absent/not a string.
     */
    private function strField(array $row, string $key, string $fallback): string
    {
        /** @var mixed $value */
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : $fallback;
    }

    private function log(): StructuredLogger
    {
        return LoggerFactory::get(LogChannels::RELAY);
    }
}
