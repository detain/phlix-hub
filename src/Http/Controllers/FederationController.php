<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubConfig;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Throwable;

/**
 * REST API controller for the federation management UI.
 *
 * All endpoints require authMiddleware + adminMiddleware.
 *
 * Endpoints:
 *  - GET    /api/v1/me/federation/hub-config                           → hub config
 *  - PUT    /api/v1/me/federation/hub-config                           → update hub config
 *  - GET    /api/v1/me/federation/peers                                 → list peers
 *  - POST   /api/v1/me/federation/peers                               → add peer
 *  - DELETE /api/v1/me/federation/peers/{id}                          → remove peer
 *  - PUT    /api/v1/me/federation/peers/{id}/relay                    → toggle relay
 *  - PUT    /api/v1/me/federation/peers/{id}/admin-delegation        → toggle admin delegation
 *  - GET    /api/v1/me/federation/library-shares/outgoing             → list outgoing shares
 *  - POST   /api/v1/me/federation/library-shares/outgoing             → create outgoing share
 *  - DELETE /api/v1/me/federation/library-shares/outgoing/{id}         → revoke outgoing share
 *  - GET    /api/v1/me/federation/library-shares/incoming             → list incoming offers
 *  - POST   /api/v1/me/federation/library-shares/incoming/{id}/accept  → accept incoming offer
 *  - POST   /api/v1/me/federation/library-shares/incoming/{id}/reject   → reject incoming offer
 *  - GET    /api/v1/me/federation/admin-delegations                   → list delegations (master only)
 *  - POST   /api/v1/me/federation/admin-delegations                   → create delegation
 *  - DELETE /api/v1/me/federation/admin-delegations/{id}              → delete delegation
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class FederationController
{
    public function __construct(
        private readonly FederationHubRepository $hubRepo,
        private readonly FederationSessionManager $sessions,
        private readonly FederationLibraryShareRepository $libraryShares,
        private readonly FederationAdminDelegationRepository $adminDel,
        private readonly FederationPeerManager $peerManager,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * `GET /api/v1/me/federation/hub-config` — return this hub's own federation config.
     */
    public function getHubConfig(Request $request): Response
    {
        $hubConfig = $this->hubRepo->getHubConfig();

        if ($hubConfig === null) {
            return (new Response())->json([
                'id' => null,
                'name' => null,
                'url' => null,
                'public_key' => null,
                'role' => 'leaf',
                'is_master' => false,
                'is_active' => true,
            ]);
        }

        return (new Response())->json(FederationHubConfig::fromRow($hubConfig)->toArray());
    }

    /**
     * `PUT /api/v1/me/federation/hub-config` — update this hub's role, URL, or active flag.
     *
     * Body: {role?: 'master'|'leaf', url?: string, is_active?: bool, name?: string}
     */
    public function putHubConfig(Request $request): Response
    {
        $body = $request->body;
        if ($body === []) {
            return $this->badRequest('invalid_body');
        }

        /** @var mixed $roleRaw */
        $roleRaw = $body['role'] ?? null;
        /** @var mixed $urlRaw */
        $urlRaw = $body['url'] ?? null;
        /** @var mixed $isActiveRaw */
        $isActiveRaw = $body['is_active'] ?? null;
        /** @var mixed $nameRaw */
        $nameRaw = $body['name'] ?? null;
        /** @var mixed $publicKeyRaw */
        $publicKeyRaw = $body['public_key'] ?? null;

        // Validate role
        if ($roleRaw !== null) {
            if (!is_string($roleRaw) || !in_array($roleRaw, ['master', 'leaf'], true)) {
                return $this->badRequest('invalid_role');
            }

            // If role is changing, update is_master flag
            $this->hubRepo->updateRole($roleRaw);

            // On role change, trigger peer manager reconnect
            $this->peerManager->disconnectFromMaster();
            if ($roleRaw === 'leaf') {
                $this->peerManager->connectToMaster();
            }
        }

        // Validate and update URL
        if ($urlRaw !== null) {
            if (!is_string($urlRaw) || $urlRaw === '') {
                return $this->badRequest('invalid_url');
            }

            // Update hub URL
            $hubConfig = $this->hubRepo->getHubConfig();
            if ($hubConfig !== null) {
                /** @var string $hubId */
                $hubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
                /** @var string $publicKey */
                $publicKey = is_string($hubConfig['public_key'] ?? null) ? $hubConfig['public_key'] : '';
                /** @var string $name */
                $name = is_string($nameRaw) && $nameRaw !== ''
                    ? $nameRaw
                    : (is_string($hubConfig['name'] ?? null) ? $hubConfig['name'] : '');
                $this->hubRepo->ensureHubExists($name, $urlRaw, $publicKey);
            }
        }

        // Validate and update name
        if ($nameRaw !== null && is_string($nameRaw) && $nameRaw !== '') {
            $hubConfig = $this->hubRepo->getHubConfig();
            if ($hubConfig !== null) {
                /** @var string $url */
                $url = is_string($hubConfig['url'] ?? null) ? $hubConfig['url'] : '';
                /** @var string $publicKey */
                $publicKey = is_string($hubConfig['public_key'] ?? null) ? $hubConfig['public_key'] : '';
                $this->hubRepo->ensureHubExists($nameRaw, $url, $publicKey);
            }
        }

        // Validate and update public key
        if ($publicKeyRaw !== null && is_string($publicKeyRaw) && $publicKeyRaw !== '') {
            $hubConfig = $this->hubRepo->getHubConfig();
            if ($hubConfig !== null) {
                /** @var string $url */
                $url = is_string($hubConfig['url'] ?? null) ? $hubConfig['url'] : '';
                /** @var string $name */
                $name = is_string($hubConfig['name'] ?? null) ? $hubConfig['name'] : '';
                $this->hubRepo->ensureHubExists($name, $url, $publicKeyRaw);
            }
        }

        // Update active flag
        if ($isActiveRaw !== null) {
            $isActive = is_bool($isActiveRaw) ? $isActiveRaw
                : (is_string($isActiveRaw) && $isActiveRaw === 'true');
            $this->hubRepo->updateActive($isActive);

            // If deactivating, disconnect from master
            if (!$isActive) {
                $this->peerManager->disconnectFromMaster();
            }
        }

        return $this->getHubConfig($request);
    }

    /**
     * `GET /api/v1/me/federation/peers` — list all registered peer hubs.
     */
    public function getPeers(Request $request): Response
    {
        $peers = $this->hubRepo->getAllPeers();

        return (new Response())->json([
            'peers' => array_map(
                static function (array $p): array {
                    /** @var mixed $relayEnabled */
                    $relayEnabled = $p['relay_enabled'] ?? null;
                    /** @var mixed $adminDelEnabled */
                    $adminDelEnabled = $p['admin_delegation_enabled'] ?? null;
                    return [
                        'id' => $p['id'] ?? '',
                        'name' => $p['name'] ?? '',
                        'url' => $p['url'] ?? '',
                        'public_key' => $p['public_key'] ?? '',
                        'relay_enabled' => is_int($relayEnabled)
                            ? $relayEnabled === 1
                            : (is_string($relayEnabled) && $relayEnabled === '1'),
                        'admin_delegation_enabled' => is_int($adminDelEnabled)
                            ? $adminDelEnabled === 1
                            : (is_string($adminDelEnabled) && $adminDelEnabled === '1'),
                        'status' => $p['status'] ?? 'pending',
                        'last_seen_at' => $p['last_seen_at'] ?? null,
                        'last_connected_at' => $p['last_connected_at'] ?? null,
                        'created_at' => $p['created_at'] ?? null,
                    ];
                },
                $peers,
            ),
        ]);
    }

    /**
     * `POST /api/v1/me/federation/peers` — register a new peer hub.
     *
     * Body: {url: string, public_key: string, name: string}
     */
    public function createPeer(Request $request): Response
    {
        $body = $request->body;
        if ($body === []) {
            return $this->badRequest('invalid_body');
        }

        /** @var mixed $urlRaw */
        $urlRaw = $body['url'] ?? null;
        /** @var mixed $publicKeyRaw */
        $publicKeyRaw = $body['public_key'] ?? null;
        /** @var mixed $nameRaw */
        $nameRaw = $body['name'] ?? null;

        if (!is_string($urlRaw) || $urlRaw === '') {
            return $this->badRequest('missing_url');
        }

        if (!is_string($publicKeyRaw) || $publicKeyRaw === '') {
            return $this->badRequest('missing_public_key');
        }

        if (!is_string($nameRaw) || $nameRaw === '') {
            return $this->badRequest('missing_name');
        }

        // Validate URL format
        if (!filter_var($urlRaw, FILTER_VALIDATE_URL)) {
            return $this->badRequest('invalid_url');
        }

        // Check for duplicate URL or public key
        $existingByUrl = $this->hubRepo->getPeerByUrl($urlRaw);
        if ($existingByUrl !== null) {
            return (new Response())->status(409)->json([
                'error' => 'Conflict',
                'code' => 'peer_url_exists',
                'message' => 'A peer with this URL already exists',
            ]);
        }

        $existingByKey = $this->hubRepo->getPeerByPublicKey($publicKeyRaw);
        if ($existingByKey !== null) {
            return (new Response())->status(409)->json([
                'error' => 'Conflict',
                'code' => 'peer_key_exists',
                'message' => 'A peer with this public key already exists',
            ]);
        }

        $peerId = $this->generateUuid();
        $this->hubRepo->createPeer($peerId, $nameRaw, $urlRaw, $publicKeyRaw);

        return (new Response())->status(201)->json([
            'id' => $peerId,
            'name' => $nameRaw,
            'url' => $urlRaw,
            'public_key' => $publicKeyRaw,
            'relay_enabled' => false,
            'admin_delegation_enabled' => false,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * `DELETE /api/v1/me/federation/peers/{id}` — remove a peer hub.
     *
     * @param array<string, string> $params Route params.
     */
    public function deletePeer(Request $request, array $params): Response
    {
        $peerId = $params['id'] ?? '';
        if ($peerId === '') {
            return $this->badRequest('missing_peer_id');
        }

        $peer = $this->hubRepo->getPeerById($peerId);
        if ($peer === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'peer_not_found',
            ]);
        }

        // If this peer is connected, disconnect first
        /** @var mixed $statusRaw */
        $statusRaw = $peer['status'] ?? null;
        $status = is_string($statusRaw) ? $statusRaw : 'pending';
        if ($status === 'connected') {
            // Disconnect any active federation sessions for this peer
            $session = $this->sessions->getActiveSession($peerId);
            if ($session !== null) {
                /** @var string $sessionId */
                $sessionId = is_string($session['id'] ?? null) ? $session['id'] : '';
                if ($sessionId !== '') {
                    $this->sessions->closeSession($sessionId);
                }
            }
        }

        $this->hubRepo->deletePeer($peerId);

        return (new Response())->status(204);
    }

    /**
     * `PUT /api/v1/me/federation/peers/{id}/relay` — enable or disable relay for a peer.
     *
     * Body: {enabled: bool}
     * @param array<string, string> $params Route params.
     */
    public function toggleRelay(Request $request, array $params): Response
    {
        $peerId = $params['id'] ?? '';
        if ($peerId === '') {
            return $this->badRequest('missing_peer_id');
        }

        $peer = $this->hubRepo->getPeerById($peerId);
        if ($peer === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'peer_not_found',
            ]);
        }

        $body = $request->body;
        if ($body === []) {
            return $this->badRequest('invalid_body');
        }

        /** @var mixed $enabledRaw */
        $enabledRaw = $body['enabled'] ?? null;
        $relayEnabled = is_bool($enabledRaw) ? $enabledRaw
            : (is_string($enabledRaw) && $enabledRaw === 'true');

        /** @var mixed $adminDelRaw */
        $adminDelRaw = $peer['admin_delegation_enabled'] ?? null;
        $adminDelEnabled = is_int($adminDelRaw)
            ? $adminDelRaw === 1
            : (is_string($adminDelRaw) && $adminDelRaw === '1');

        $this->hubRepo->updatePeerToggles($peerId, $relayEnabled, $adminDelEnabled);

        // If enabling relay and this hub is leaf, connect to master
        if ($relayEnabled) {
            $this->peerManager->connectToMaster();
        }

        $this->audit->logAdminAction(
            $request->userId ?? '',
            'federation.toggle_relay',
            $peerId,
            ['relay_enabled' => $relayEnabled],
        );

        return (new Response())->json([
            'relay_enabled' => $relayEnabled,
        ]);
    }

    /**
     * `PUT /api/v1/me/federation/peers/{id}/admin-delegation` — toggle admin delegation for a peer.
     *
     * Body: {enabled: bool}
     * @param array<string, string> $params Route params.
     */
    public function toggleAdminDelegation(Request $request, array $params): Response
    {
        $peerId = $params['id'] ?? '';
        if ($peerId === '') {
            return $this->badRequest('missing_peer_id');
        }

        $peer = $this->hubRepo->getPeerById($peerId);
        if ($peer === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'peer_not_found',
            ]);
        }

        $body = $request->body;
        if ($body === []) {
            return $this->badRequest('invalid_body');
        }

        /** @var mixed $enabledRaw */
        $enabledRaw = $body['enabled'] ?? null;
        $adminDelEnabled = is_bool($enabledRaw) ? $enabledRaw
            : (is_string($enabledRaw) && $enabledRaw === 'true');

        /** @var mixed $relayRaw */
        $relayRaw = $peer['relay_enabled'] ?? null;
        $relayEnabled = is_int($relayRaw) ? $relayRaw === 1 : (is_string($relayRaw) && $relayRaw === '1');

        $this->hubRepo->updatePeerToggles($peerId, $relayEnabled, $adminDelEnabled);

        $this->audit->logAdminAction(
            $request->userId ?? '',
            'federation.toggle_admin_delegation',
            $peerId,
            ['admin_delegation_enabled' => $adminDelEnabled],
        );

        return (new Response())->json([
            'admin_delegation_enabled' => $adminDelEnabled,
        ]);
    }

    /**
     * `GET /api/v1/me/federation/library-shares/outgoing` — list outgoing shares from this hub.
     */
    public function getOutgoingShares(Request $request): Response
    {
        $shares = $this->libraryShares->getOutgoingShares();

        return (new Response())->json([
            'outgoing_shares' => array_map(
                static fn (array $s): array => [
                    'id' => $s['id'] ?? '',
                    'library_id' => $s['library_id'] ?? '',
                    'library_name' => $s['library_name'] ?? '',
                    'peer_id' => $s['peer_id'] ?? '',
                    'permission' => $s['permission'] ?? 'read',
                    'status' => $s['status'] ?? 'pending',
                    'shared_at' => $s['shared_at'] ?? null,
                    'revoked_at' => $s['revoked_at'] ?? null,
                ],
                $shares,
            ),
        ]);
    }

    /**
     * `POST /api/v1/me/federation/library-shares/outgoing` — create an outgoing share offer.
     *
     * Body: {library_id: string, peer_id: string, permission: 'read'|'readwrite', library_name: string}
     */
    public function createOutgoingShare(Request $request): Response
    {
        $userId = $request->userId ?? '';
        $body = $request->body;

        if ($body === []) {
            return $this->badRequest('invalid_body');
        }

        /** @var mixed $libraryIdRaw */
        $libraryIdRaw = $body['library_id'] ?? null;
        /** @var mixed $peerIdRaw */
        $peerIdRaw = $body['peer_id'] ?? null;
        /** @var mixed $permissionRaw */
        $permissionRaw = $body['permission'] ?? 'read';
        /** @var mixed $libraryNameRaw */
        $libraryNameRaw = $body['library_name'] ?? '';

        if (!is_string($libraryIdRaw) || $libraryIdRaw === '') {
            return $this->badRequest('missing_library_id');
        }

        if (!is_string($peerIdRaw) || $peerIdRaw === '') {
            return $this->badRequest('missing_peer_id');
        }

        $peer = $this->hubRepo->getPeerById($peerIdRaw);
        if ($peer === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'peer_not_found',
            ]);
        }

        if (!is_string($permissionRaw) || !in_array($permissionRaw, ['read', 'readwrite'], true)) {
            return $this->badRequest('invalid_permission');
        }

        if (!is_string($libraryNameRaw) || $libraryNameRaw === '') {
            return $this->badRequest('missing_library_name');
        }

        $shareId = $this->generateUuid();
        $this->libraryShares->createOutgoingShare(
            $shareId,
            $libraryIdRaw,
            $libraryNameRaw,
            $peerIdRaw,
            $permissionRaw,
        );

        // Push share to master hub if connected
        $this->peerManager->pushLibraryShare($shareId, $libraryIdRaw, $libraryNameRaw, $permissionRaw);

        $this->audit->logLibraryShareCrossHub($peerIdRaw, $libraryIdRaw, $permissionRaw, 'created');

        return (new Response())->status(201)->json([
            'id' => $shareId,
            'library_id' => $libraryIdRaw,
            'library_name' => $libraryNameRaw,
            'peer_id' => $peerIdRaw,
            'permission' => $permissionRaw,
            'status' => 'pending',
            'shared_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * `DELETE /api/v1/me/federation/library-shares/outgoing/{id}` — revoke an outgoing share.
     * @param array<string, string> $params Route params.
     */
    public function revokeOutgoingShare(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        $shareId = $params['id'] ?? '';

        if ($shareId === '') {
            return $this->badRequest('missing_share_id');
        }

        $share = $this->libraryShares->getOutgoingShareById($shareId);
        if ($share === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'share_not_found',
            ]);
        }

        /** @var string $peerId */
        $peerId = is_string($share['peer_id'] ?? null) ? $share['peer_id'] : '';
        /** @var string $libraryId */
        $libraryId = is_string($share['library_id'] ?? null) ? $share['library_id'] : '';
        /** @var string $permission */
        $permission = is_string($share['permission'] ?? null) ? $share['permission'] : 'read';

        $this->libraryShares->revokeOutgoingShare($shareId);

        // Push revocation to master hub if connected
        $this->peerManager->pushLibraryShareRevoked($shareId);

        if ($peerId !== '' && $libraryId !== '') {
            $this->audit->logLibraryShareCrossHub($peerId, $libraryId, $permission, 'revoked');
        }

        return (new Response())->status(204);
    }

    /**
     * `GET /api/v1/me/federation/library-shares/incoming` — list incoming share offers to this hub.
     */
    public function getIncomingOffers(Request $request): Response
    {
        $offers = $this->libraryShares->getIncomingOffers();

        return (new Response())->json([
            'incoming_offers' => array_map(
                static fn (array $o): array => [
                    'id' => $o['id'] ?? '',
                    'peer_id' => $o['peer_id'] ?? '',
                    'library_id' => $o['library_id'] ?? '',
                    'library_name' => $o['library_name'] ?? '',
                    'permission' => $o['permission'] ?? 'read',
                    'status' => $o['status'] ?? 'pending',
                    'offered_at' => $o['offered_at'] ?? null,
                    'responded_at' => $o['responded_at'] ?? null,
                    'accepted_by' => $o['accepted_by'] ?? null,
                ],
                $offers,
            ),
        ]);
    }

    /**
     * `POST /api/v1/me/federation/library-shares/incoming/{id}/accept` — accept an incoming offer.
     * @param array<string, string> $params Route params.
     */
    public function acceptIncomingOffer(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        $offerId = $params['id'] ?? '';

        if ($offerId === '') {
            return $this->badRequest('missing_offer_id');
        }

        $offer = $this->libraryShares->getIncomingOfferById($offerId);
        if ($offer === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'offer_not_found',
            ]);
        }

        $status = is_string($offer['status'] ?? null) ? $offer['status'] : 'pending';
        if ($status !== 'pending') {
            return (new Response())->status(409)->json([
                'error' => 'Conflict',
                'code' => 'offer_already_responded',
                'message' => 'This offer has already been accepted or rejected',
            ]);
        }

        $this->libraryShares->acceptIncomingOffer($offerId, $userId);

        /** @var string $peerId */
        $peerId = is_string($offer['peer_id'] ?? null) ? $offer['peer_id'] : '';
        /** @var string $libraryId */
        $libraryId = is_string($offer['library_id'] ?? null) ? $offer['library_id'] : '';
        /** @var string $permission */
        $permission = is_string($offer['permission'] ?? null) ? $offer['permission'] : 'read';

        if ($peerId !== '' && $libraryId !== '') {
            $this->audit->logLibraryShareCrossHub($peerId, $libraryId, $permission, 'accepted');
        }

        return (new Response())->json([
            'id' => $offerId,
            'status' => 'accepted',
            'accepted_by' => $userId,
        ]);
    }

    /**
     * `POST /api/v1/me/federation/library-shares/incoming/{id}/reject` — reject an incoming offer.
     * @param array<string, string> $params Route params.
     */
    public function rejectIncomingOffer(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        $offerId = $params['id'] ?? '';

        if ($offerId === '') {
            return $this->badRequest('missing_offer_id');
        }

        $offer = $this->libraryShares->getIncomingOfferById($offerId);
        if ($offer === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'offer_not_found',
            ]);
        }

        $status = is_string($offer['status'] ?? null) ? $offer['status'] : 'pending';
        if ($status !== 'pending') {
            return (new Response())->status(409)->json([
                'error' => 'Conflict',
                'code' => 'offer_already_responded',
                'message' => 'This offer has already been accepted or rejected',
            ]);
        }

        $this->libraryShares->rejectIncomingOffer($offerId, $userId);

        /** @var string $peerId */
        $peerId = is_string($offer['peer_id'] ?? null) ? $offer['peer_id'] : '';
        /** @var string $libraryId */
        $libraryId = is_string($offer['library_id'] ?? null) ? $offer['library_id'] : '';
        /** @var string $permission */
        $permission = is_string($offer['permission'] ?? null) ? $offer['permission'] : 'read';

        if ($peerId !== '' && $libraryId !== '') {
            $this->audit->logLibraryShareCrossHub($peerId, $libraryId, $permission, 'rejected');
        }

        return (new Response())->json([
            'id' => $offerId,
            'status' => 'rejected',
            'accepted_by' => $userId,
        ]);
    }

    /**
     * `GET /api/v1/me/federation/admin-delegations` — list admin delegations (master hub only).
     */
    public function getAdminDelegations(Request $request): Response
    {
        // Only allow on master hub
        $hubConfig = $this->hubRepo->getHubConfig();
        /** @var mixed $isMasterRaw */
        $isMasterRaw = $hubConfig['is_master'] ?? null;
        $isMaster = is_int($isMasterRaw) ? $isMasterRaw === 1 : (is_string($isMasterRaw) && $isMasterRaw === '1');
        if ($hubConfig === null || !$isMaster) {
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code' => 'master_only',
                'message' => 'Admin delegations are only available on the master hub',
            ]);
        }

        /** @var array<int, array<string, mixed>> $delegations */
        $delegations = [];
        $peers = $this->hubRepo->getAllPeers();
        foreach ($peers as $peer) {
            /** @var string $peerId */
            $peerId = is_string($peer['id'] ?? null) ? $peer['id'] : '';
            if ($peerId === '') {
                continue;
            }
            $peerDels = $this->adminDel->getActiveDelegationsForPeer($peerId);
            $delegations = array_merge($delegations, $peerDels);
        }

        return (new Response())->json([
            'delegations' => array_map(
                static fn (array $d): array => [
                    'id' => $d['id'] ?? '',
                    'peer_id' => $d['peer_id'] ?? '',
                    'user_id' => $d['user_id'] ?? '',
                    'granted_at' => $d['granted_at'] ?? null,
                    'revoked_at' => $d['revoked_at'] ?? null,
                ],
                $delegations,
            ),
        ]);
    }

    /**
     * `POST /api/v1/me/federation/admin-delegations` — create an admin delegation (master hub only).
     *
     * Body: {peer_id: string, user_id: string}
     */
    public function createAdminDelegation(Request $request): Response
    {
        // Only allow on master hub
        $hubConfig = $this->hubRepo->getHubConfig();
        /** @var mixed $isMasterRaw */
        $isMasterRaw = $hubConfig['is_master'] ?? null;
        $isMaster = is_int($isMasterRaw) ? $isMasterRaw === 1 : (is_string($isMasterRaw) && $isMasterRaw === '1');
        if ($hubConfig === null || !$isMaster) {
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code' => 'master_only',
                'message' => 'Admin delegations are only available on the master hub',
            ]);
        }

        $body = $request->body;
        if ($body === []) {
            return $this->badRequest('invalid_body');
        }

        /** @var mixed $peerIdRaw */
        $peerIdRaw = $body['peer_id'] ?? null;
        /** @var mixed $userIdRaw */
        $userIdRaw = $body['user_id'] ?? null;

        if (!is_string($peerIdRaw) || $peerIdRaw === '') {
            return $this->badRequest('missing_peer_id');
        }

        if (!is_string($userIdRaw) || $userIdRaw === '') {
            return $this->badRequest('missing_user_id');
        }

        $peer = $this->hubRepo->getPeerById($peerIdRaw);
        if ($peer === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'peer_not_found',
            ]);
        }

        $delegationId = $this->generateUuid();
        $this->adminDel->grant($delegationId, $peerIdRaw, $userIdRaw);

        $this->audit->logAdminDelegation($peerIdRaw, $userIdRaw, 'grant');

        return (new Response())->status(201)->json([
            'id' => $delegationId,
            'peer_id' => $peerIdRaw,
            'user_id' => $userIdRaw,
            'granted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * `DELETE /api/v1/me/federation/admin-delegations/{id}` — delete an admin delegation.
     * @param array<string, string> $params Route params.
     */
    public function deleteAdminDelegation(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        $delegationId = $params['id'] ?? '';

        if ($delegationId === '') {
            return $this->badRequest('missing_delegation_id');
        }

        $delegation = $this->adminDel->getDelegationById($delegationId);
        if ($delegation === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'delegation_not_found',
            ]);
        }

        /** @var string $peerId */
        $peerId = is_string($delegation['peer_id'] ?? null) ? $delegation['peer_id'] : '';
        /** @var string $targetUserId */
        $targetUserId = is_string($delegation['user_id'] ?? null) ? $delegation['user_id'] : '';

        $this->adminDel->revoke($delegationId);

        if ($peerId !== '' && $targetUserId !== '') {
            $this->audit->logAdminDelegation($peerId, $targetUserId, 'revoke');
        }

        return (new Response())->status(204);
    }

    /**
     * Return a 400 Bad Request JSON response.
     *
     * @param string $code Error code.
     *
     * @return Response
     */
    private function badRequest(string $code): Response
    {
        $messages = [
            'invalid_body' => 'Request body must be a JSON object',
            'invalid_role' => 'Role must be "master" or "leaf"',
            'invalid_url' => 'Invalid URL format',
            'invalid_permission' => 'Permission must be "read" or "readwrite"',
            'missing_url' => 'URL is required',
            'missing_public_key' => 'Public key is required',
            'missing_name' => 'Name is required',
            'missing_peer_id' => 'Peer ID is required',
            'missing_share_id' => 'Share ID is required',
            'missing_library_id' => 'Library ID is required',
            'missing_library_name' => 'Library name is required',
            'missing_offer_id' => 'Offer ID is required',
            'missing_user_id' => 'User ID is required',
            'missing_delegation_id' => 'Delegation ID is required',
        ];

        $message = $messages[$code] ?? 'Bad Request';

        return (new Response())->status(400)->json([
            'error' => 'Bad Request',
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * Generate a random UUID v4.
     *
     * @return string
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
