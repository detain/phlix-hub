<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubConfig;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Http\Controllers\FederationController;
use Phlix\Hub\Http\Request;

/**
 * Unit tests for {@see FederationController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\FederationController
 */
final class FederationControllerTest extends TestCase
{
    private FederationHubRepository $hubRepo;
    private FederationSessionManager $sessions;
    private FederationLibraryShareRepository $libraryShares;
    private FederationAdminDelegationRepository $adminDel;
    private FederationPeerManager $peerManager;
    private AuditLogger $audit;
    private FederationController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->sessions = $this->createMock(FederationSessionManager::class);
        $this->libraryShares = $this->createMock(FederationLibraryShareRepository::class);
        $this->adminDel = $this->createMock(FederationAdminDelegationRepository::class);
        $this->peerManager = $this->createMock(FederationPeerManager::class);
        $this->audit = $this->createMock(AuditLogger::class);

        $this->controller = new FederationController(
            $this->hubRepo,
            $this->sessions,
            $this->libraryShares,
            $this->adminDel,
            $this->peerManager,
            $this->audit,
        );
    }

    public function testGetHubConfigReturnsConfigWhenPresent(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id' => 'hub-1',
            'name' => 'My Hub',
            'url' => 'https://hub1.example.com',
            'public_key' => 'key123',
            'role' => 'leaf',
            'is_master' => 0,
            'is_active' => 1,
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/hub-config';
        $request->method = 'GET';

        $response = $this->controller->getHubConfig($request);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertSame('hub-1', $body['id']);
        self::assertSame('My Hub', $body['name']);
        self::assertSame('leaf', $body['role']);
        self::assertFalse($body['is_master']);
        self::assertTrue($body['is_active']);
    }

    public function testGetHubConfigReturnsDefaultWhenNotConfigured(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/hub-config';
        $request->method = 'GET';

        $response = $this->controller->getHubConfig($request);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertNull($body['id']);
        self::assertSame('leaf', $body['role']);
    }

    public function testGetPeersReturnsAllPeers(): void
    {
        $this->hubRepo->method('getAllPeers')->willReturn([
            [
                'id' => 'peer-1',
                'name' => 'Peer Hub A',
                'url' => 'https://peer-a.example.com',
                'public_key' => 'key-a',
                'relay_enabled' => 1,
                'admin_delegation_enabled' => 0,
                'status' => 'connected',
                'shared_library_count' => 3,
                'last_seen_at' => '2024-01-01 00:00:00',
                'last_connected_at' => '2024-01-01 00:00:00',
                'created_at' => '2023-12-01 00:00:00',
            ],
            [
                'id' => 'peer-2',
                'name' => 'Peer Hub B',
                'url' => 'https://peer-b.example.com',
                'public_key' => 'key-b',
                'relay_enabled' => 0,
                'admin_delegation_enabled' => 0,
                'status' => 'pending',
                'shared_library_count' => 0,
                'last_seen_at' => null,
                'last_connected_at' => null,
                'created_at' => '2023-12-01 00:00:00',
            ],
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'GET';

        $response = $this->controller->getPeers($request);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertCount(2, $body['peers']);
        self::assertSame('peer-1', $body['peers'][0]['id']);
        self::assertTrue($body['peers'][0]['relay_enabled']);
        self::assertFalse($body['peers'][1]['relay_enabled']);
        self::assertSame(3, $body['peers'][0]['shared_library_count']);
        self::assertSame(0, $body['peers'][1]['shared_library_count']);
    }

    public function testGetPeersCoercesStringSharedLibraryCountAndDefaultsToZero(): void
    {
        $this->hubRepo->method('getAllPeers')->willReturn([
            // shared_library_count comes back as a numeric string from MySQL COUNT(*).
            [
                'id' => 'peer-1',
                'name' => 'Peer Hub A',
                'url' => 'https://peer-a.example.com',
                'public_key' => 'key-a',
                'relay_enabled' => 0,
                'admin_delegation_enabled' => 0,
                'status' => 'connected',
                'shared_library_count' => '5',
            ],
            // Missing column entirely → must default to 0, never error.
            [
                'id' => 'peer-2',
                'name' => 'Peer Hub B',
                'url' => 'https://peer-b.example.com',
                'public_key' => 'key-b',
                'relay_enabled' => 0,
                'admin_delegation_enabled' => 0,
                'status' => 'pending',
            ],
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'GET';

        $response = $this->controller->getPeers($request);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertSame(5, $body['peers'][0]['shared_library_count']);
        self::assertSame(0, $body['peers'][1]['shared_library_count']);
    }

    public function testCreatePeerReturns201OnSuccess(): void
    {
        $this->hubRepo->method('getPeerByUrl')->willReturn(null);
        $this->hubRepo->method('getPeerByPublicKey')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'https://new-peer.example.com',
            'public_key' => 'new-peer-key',
            'name' => 'New Peer Hub',
        ];

        $response = $this->controller->createPeer($request);

        self::assertSame(201, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertNotEmpty($body['id']);
        self::assertSame('New Peer Hub', $body['name']);
        self::assertFalse($body['relay_enabled']);
        self::assertFalse($body['admin_delegation_enabled']);
        self::assertSame('pending', $body['status']);
    }

    public function testCreatePeerReturns400WhenUrlMissing(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'public_key' => 'some-key',
            'name' => 'New Peer Hub',
        ];

        $response = $this->controller->createPeer($request);

        self::assertSame(400, $response->statusCode);
    }

    public function testCreatePeerReturns409WhenUrlExists(): void
    {
        $this->hubRepo->method('getPeerByUrl')->willReturn(['id' => 'existing-peer']);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'https://existing.example.com',
            'public_key' => 'some-key',
            'name' => 'New Peer Hub',
        ];

        $response = $this->controller->createPeer($request);

        self::assertSame(409, $response->statusCode);
    }

    public function testDeletePeerReturns204OnSuccess(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'status' => 'pending',
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers/peer-1';
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        $response = $this->controller->deletePeer($request, ['id' => 'peer-1']);

        self::assertSame(204, $response->statusCode);
    }

    public function testDeletePeerReturns404WhenPeerNotFound(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers/nonexistent';
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        $response = $this->controller->deletePeer($request, ['id' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
    }

    public function testToggleRelayEnablesRelayAndConnectsToMaster(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'relay_enabled' => 0,
            'admin_delegation_enabled' => 0,
        ]);
        $this->peerManager->expects(self::once())->method('connectToMaster');

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers/peer-1/relay';
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['enabled' => true];

        $response = $this->controller->toggleRelay($request, ['id' => 'peer-1']);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertTrue($body['relay_enabled']);
    }

    public function testToggleRelayReturns404WhenPeerNotFound(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers/nonexistent/relay';
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['enabled' => true];

        $response = $this->controller->toggleRelay($request, ['id' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
    }

    public function testToggleAdminDelegationReturns200OnSuccess(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'relay_enabled' => 0,
            'admin_delegation_enabled' => 0,
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers/peer-1/admin-delegation';
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['enabled' => true];

        $response = $this->controller->toggleAdminDelegation($request, ['id' => 'peer-1']);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertTrue($body['admin_delegation_enabled']);
    }

    public function testGetOutgoingSharesReturnsShares(): void
    {
        $this->libraryShares->method('getOutgoingShares')->willReturn([
            [
                'id' => 'share-1',
                'library_id' => 'lib-1',
                'library_name' => 'My Movies',
                'peer_id' => 'peer-1',
                'permission' => 'read',
                'status' => 'active',
                'shared_at' => '2024-01-01 00:00:00',
                'revoked_at' => null,
            ],
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/outgoing';
        $request->method = 'GET';

        $response = $this->controller->getOutgoingShares($request);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertCount(1, $body['outgoing_shares']);
        self::assertSame('share-1', $body['outgoing_shares'][0]['id']);
    }

    public function testCreateOutgoingShareReturns201OnSuccess(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'name' => 'Peer Hub',
            'url' => 'https://peer.example.com',
            'public_key' => 'peer-key',
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/outgoing';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
            'peer_id' => 'peer-1',
            'permission' => 'read',
        ];

        $response = $this->controller->createOutgoingShare($request);

        self::assertSame(201, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertNotEmpty($body['id']);
        self::assertSame('lib-1', $body['library_id']);
        self::assertSame('peer-1', $body['peer_id']);
    }

    public function testCreateOutgoingShareReturns404WhenPeerNotFound(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/outgoing';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
            'peer_id' => 'nonexistent-peer',
            'permission' => 'read',
        ];

        $response = $this->controller->createOutgoingShare($request);

        self::assertSame(404, $response->statusCode);
    }

    public function testRevokeOutgoingShareReturns204OnSuccess(): void
    {
        $this->libraryShares->method('getOutgoingShareById')->willReturn([
            'id' => 'share-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/outgoing/share-1';
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        $response = $this->controller->revokeOutgoingShare($request, ['id' => 'share-1']);

        self::assertSame(204, $response->statusCode);
    }

    public function testRevokeOutgoingShareReturns404WhenShareNotFound(): void
    {
        $this->libraryShares->method('getOutgoingShareById')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/outgoing/nonexistent';
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        $response = $this->controller->revokeOutgoingShare($request, ['id' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
    }

    public function testGetIncomingOffersReturnsOffers(): void
    {
        $this->libraryShares->method('getIncomingOffers')->willReturn([
            [
                'id' => 'offer-1',
                'peer_id' => 'peer-1',
                'library_id' => 'lib-1',
                'library_name' => 'Their Movies',
                'permission' => 'read',
                'status' => 'pending',
                'offered_at' => '2024-01-01 00:00:00',
                'responded_at' => null,
                'accepted_by' => null,
            ],
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/incoming';
        $request->method = 'GET';

        $response = $this->controller->getIncomingOffers($request);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertCount(1, $body['incoming_offers']);
        self::assertSame('offer-1', $body['incoming_offers'][0]['id']);
    }

    public function testAcceptIncomingOfferReturns200OnSuccess(): void
    {
        $this->libraryShares->method('getIncomingOfferById')->willReturn([
            'id' => 'offer-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'status' => 'pending',
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/incoming/offer-1/accept';
        $request->method = 'POST';
        $request->userId = 'admin-1';

        $response = $this->controller->acceptIncomingOffer($request, ['id' => 'offer-1']);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertSame('offer-1', $body['id']);
        self::assertSame('accepted', $body['status']);
    }

    public function testAcceptIncomingOfferReturns409WhenAlreadyResponded(): void
    {
        $this->libraryShares->method('getIncomingOfferById')->willReturn([
            'id' => 'offer-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'status' => 'accepted',
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/incoming/offer-1/accept';
        $request->method = 'POST';
        $request->userId = 'admin-1';

        $response = $this->controller->acceptIncomingOffer($request, ['id' => 'offer-1']);

        self::assertSame(409, $response->statusCode);
    }

    public function testRejectIncomingOfferReturns200OnSuccess(): void
    {
        $this->libraryShares->method('getIncomingOfferById')->willReturn([
            'id' => 'offer-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'status' => 'pending',
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/library-shares/incoming/offer-1/reject';
        $request->method = 'POST';
        $request->userId = 'admin-1';

        $response = $this->controller->rejectIncomingOffer($request, ['id' => 'offer-1']);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertSame('offer-1', $body['id']);
        self::assertSame('rejected', $body['status']);
    }

    public function testGetAdminDelegationsReturns403OnLeafHub(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id' => 'hub-1',
            'name' => 'Leaf Hub',
            'url' => 'https://leaf.example.com',
            'public_key' => 'key',
            'role' => 'leaf',
            'is_master' => 0,
            'is_active' => 1,
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/admin-delegations';
        $request->method = 'GET';

        $response = $this->controller->getAdminDelegations($request);

        self::assertSame(403, $response->statusCode);
    }

    public function testGetAdminDelegationsReturnsDelegationsOnMasterHub(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id' => 'hub-1',
            'name' => 'Master Hub',
            'url' => 'https://master.example.com',
            'public_key' => 'key',
            'role' => 'master',
            'is_master' => 1,
            'is_active' => 1,
        ]);
        $this->hubRepo->method('getAllPeers')->willReturn([
            ['id' => 'peer-1'],
        ]);
        $this->adminDel->method('getActiveDelegationsForPeer')->willReturn([
            [
                'id' => 'del-1',
                'peer_id' => 'peer-1',
                'user_id' => 'user-1',
                'granted_at' => '2024-01-01 00:00:00',
                'revoked_at' => null,
            ],
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/admin-delegations';
        $request->method = 'GET';

        $response = $this->controller->getAdminDelegations($request);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertCount(1, $body['delegations']);
    }

    public function testCreateAdminDelegationReturns403OnLeafHub(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id' => 'hub-1',
            'name' => 'Leaf Hub',
            'url' => 'https://leaf.example.com',
            'public_key' => 'key',
            'role' => 'leaf',
            'is_master' => 0,
            'is_active' => 1,
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/admin-delegations';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'peer_id' => 'peer-1',
            'user_id' => 'user-1',
        ];

        $response = $this->controller->createAdminDelegation($request);

        self::assertSame(403, $response->statusCode);
    }

    public function testCreateAdminDelegationReturns201OnSuccess(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id' => 'hub-1',
            'name' => 'Master Hub',
            'url' => 'https://master.example.com',
            'public_key' => 'key',
            'role' => 'master',
            'is_master' => 1,
            'is_active' => 1,
        ]);
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'name' => 'Peer Hub',
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/admin-delegations';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'peer_id' => 'peer-1',
            'user_id' => 'user-1',
        ];

        $response = $this->controller->createAdminDelegation($request);

        self::assertSame(201, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertNotEmpty($body['id']);
        self::assertSame('peer-1', $body['peer_id']);
        self::assertSame('user-1', $body['user_id']);
    }

    public function testDeleteAdminDelegationReturns204OnSuccess(): void
    {
        $this->adminDel->method('getDelegationById')->willReturn([
            'id' => 'del-1',
            'peer_id' => 'peer-1',
            'user_id' => 'user-1',
        ]);

        $request = new Request();
        $request->path = '/api/v1/me/federation/admin-delegations/del-1';
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        $response = $this->controller->deleteAdminDelegation($request, ['id' => 'del-1']);

        self::assertSame(204, $response->statusCode);
    }

    public function testDeleteAdminDelegationReturns404WhenNotFound(): void
    {
        $this->adminDel->method('getDelegationById')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/admin-delegations/nonexistent';
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        $response = $this->controller->deleteAdminDelegation($request, ['id' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
    }
}
