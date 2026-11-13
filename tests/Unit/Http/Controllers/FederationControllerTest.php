<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationMasterPusher;
use Phlix\Hub\Federation\FederationPushBridge;
use Phlix\Hub\Federation\FederationPushProtocol;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Http\Controllers\FederationController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;

/**
 * Unit tests for {@see FederationController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class FederationControllerTest extends TestCase
{
    use DecodedJsonAssertions;

    private FederationHubRepository&MockObject $hubRepo;
    private FederationSessionManager&MockObject $sessions;
    private FederationLibraryShareRepository&MockObject $libraryShares;
    private FederationAdminDelegationRepository&MockObject $adminDel;
    private AuditLogger&MockObject $audit;
    private FederationMasterPusher&MockObject $masterPusher;
    private FederationController $controller;

    /**
     * Leaf commands the controller published (review hub__asphp rework): the
     * controller holds NO FederationPeerManager any more — leaf intents leave
     * this process only as bridge commands, so THAT is what these tests
     * observe now. A real FederationPushBridge (final — never mock the
     * transport) answers synchronously through its injected publisher,
     * exactly the round-trip harness idiom from
     * FederationPushChannelRoundTripTest.
     *
     * @var list<array{event: string, data: array<string, mixed>}>
     */
    private array $leafCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->sessions = $this->createMock(FederationSessionManager::class);
        $this->libraryShares = $this->createMock(FederationLibraryShareRepository::class);
        $this->adminDel = $this->createMock(FederationAdminDelegationRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);
        $this->masterPusher = $this->createMock(FederationMasterPusher::class);

        $this->controller = new FederationController(
            $this->hubRepo,
            $this->sessions,
            $this->libraryShares,
            $this->adminDel,
            $this->audit,
            $this->recordingLeafBridge($this->leafCommands, true),
            $this->masterPusher,
        );
    }

    /**
     * Real bridge whose publisher captures the command and answers the reply
     * synchronously with a fixed delivered verdict (the fake of the whole
     * :8805 process at this test's boundary).
     *
     * @param list<array{event: string, data: array<string, mixed>}> $commands
     */
    private function recordingLeafBridge(array &$commands, bool $delivered): FederationPushBridge
    {
        $commands = [];

        $bridge = null;
        $bridge = new FederationPushBridge(
            new StructuredLogger('fed-controller-leaf-test', []),
            static function (string $event, array $data) use (&$commands, &$bridge, $delivered): void {
                $commands[] = ['event' => $event, 'data' => $data];
                /** @var FederationPushBridge $bridge */
                $bridge->onReply([
                    'request_id' => $data['request_id'],
                    'delivered' => $delivered,
                ]);
            },
        );

        return $bridge;
    }

    /**
     * Actions of the leaf commands published so far, in order.
     *
     * @return list<string>
     */
    private function leafCommandActions(): array
    {
        return array_map(
            static fn (array $c): string => is_string($c['data']['action'] ?? null) ? $c['data']['action'] : '',
            $this->leafCommands,
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
        $body = self::arrayNode(json_decode($response->body, true));
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
        $body = self::arrayNode(json_decode($response->body, true));
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
        $body = self::arrayNode(json_decode($response->body, true));
        $peers = self::arrayNode($body['peers']);
        $firstPeer = self::arrayNode($peers[0]);
        $secondPeer = self::arrayNode($peers[1]);
        self::assertCount(2, $peers);
        self::assertSame('peer-1', $firstPeer['id']);
        self::assertTrue($firstPeer['relay_enabled']);
        self::assertFalse($secondPeer['relay_enabled']);
        self::assertSame(3, $firstPeer['shared_library_count']);
        self::assertSame(0, $secondPeer['shared_library_count']);
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
        $body = self::arrayNode(json_decode($response->body, true));
        $peers = self::arrayNode($body['peers']);
        self::assertSame(5, self::arrayNode($peers[0])['shared_library_count']);
        self::assertSame(0, self::arrayNode($peers[1])['shared_library_count']);
    }

    public function testCreatePeerEchoesStoredCreatedAt(): void
    {
        $this->hubRepo->method('getPeerByUrl')->willReturn(null);
        $this->hubRepo->method('getPeerByPublicKey')->willReturn(null);
        $this->hubRepo->method('getPeerById')->willReturn(['created_at' => '2001-02-03 04:05:06']);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'https://echo-peer.example.com',
            'public_key' => 'echo-peer-key',
            'name' => 'Echo Peer',
        ];

        $body = self::arrayNode(json_decode($this->controller->createPeer($request)->body, true));

        self::assertSame('2001-02-03 04:05:06', $body['created_at']);
    }

    public function testCreatePeerKeepsStringShapeWhenReselectMisses(): void
    {
        $this->hubRepo->method('getPeerByUrl')->willReturn(null);
        $this->hubRepo->method('getPeerByPublicKey')->willReturn(null);
        $this->hubRepo->method('getPeerById')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'https://miss-peer.example.com',
            'public_key' => 'miss-peer-key',
            'name' => 'Miss Peer',
        ];

        $body = self::arrayNode(json_decode($this->controller->createPeer($request)->body, true));

        self::assertIsString($body['created_at'], 'a missed re-SELECT must not emit a null timestamp');
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
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertNotEmpty($body['id']);
        self::assertSame('New Peer Hub', $body['name']);
        self::assertFalse($body['relay_enabled']);
        self::assertFalse($body['admin_delegation_enabled']);
        self::assertSame('pending', $body['status']);
    }

    /**
     * Scheme-acceptance law (2026-10-06 lane): boundary validation must
     * ACCEPT wss:// master URLs — the dial side maps them onto
     * ws://-URI + transport='ssl', so a reject-at-PUT would strand the very
     * TLS-terminating-master deployments (nginx/Caddy in front) the dial
     * fix exists for. Garbage schemes still die (invalid_url sibling).
     */
    public function testCreatePeerAcceptsWssMasterUrl(): void
    {
        $this->hubRepo->method('getPeerByUrl')->willReturn(null);
        $this->hubRepo->method('getPeerByPublicKey')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'wss://master.example.com:8805',
            'public_key' => 'wss-peer-key',
            'name' => 'Wss Master',
        ];

        $response = $this->controller->createPeer($request);

        self::assertSame(201, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('wss://master.example.com:8805', $body['url']);
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

    /**
     * Store-time scheme allowlist (review Minor 47): shape-valid URLs whose
     * scheme the federation dialer cannot act on (everything outside
     * http|https|ws|wss) are refused with the registered `invalid_url` code
     * instead of landing in the row a later dial reaches for. The
     * `FederationPeerManager` dial side forcing TLS on unknown schemes is
     * backstop, not admission.
     */
    public function testCreatePeerRejectsForeignTransportSchemes(): void
    {
        foreach (['ftp://peer.example.com', 'gopher://169.254.169.254/x', 'file:///etc/passwd'] as $url) {
            $this->hubRepo->method('getPeerByUrl')->willReturn(null);
            $this->hubRepo->method('getPeerByPublicKey')->willReturn(null);

            $request = new Request();
            $request->path = '/api/v1/me/federation/peers';
            $request->method = 'POST';
            $request->userId = 'admin-1';
            $request->body = [
                'url' => $url,
                'public_key' => 'scheme-key',
                'name' => 'Scheme Peer',
            ];

            $response = $this->controller->createPeer($request);

            self::assertSame(400, $response->statusCode, 'scheme of ' . $url . ' must be refused at the store gate');
            $body = self::arrayNode(json_decode($response->body, true));
            self::assertSame('invalid_url', $body['code'] ?? null);
            self::assertIsString($body['reason'] ?? null);
            self::assertStringContainsString('scheme must be one of', $body['reason']);
        }
    }

    /**
     * The allowlist is exactly the dialer's mapping table — plaintext ws://
     * (same-host masters) must stay storable, mirroring the existing
     * https/wss acceptance laws.
     */
    public function testCreatePeerAcceptsPlaintextWsUrl(): void
    {
        $this->hubRepo->method('getPeerByUrl')->willReturn(null);
        $this->hubRepo->method('getPeerByPublicKey')->willReturn(null);

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'ws://master.example.com:8805',
            'public_key' => 'ws-peer-key',
            'name' => 'Ws Master',
        ];

        $response = $this->controller->createPeer($request);

        self::assertSame(201, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('ws://master.example.com:8805', $body['url']);
    }

    /**
     * The hub's OWN advertised URL (putHubConfig) earns the same store-time
     * law (Minor 47). The arm previously accepted any non-empty string, so
     * malformed and foreign-scheme values reached federation_hubs and went
     * out to followers. Rejection must happen before any write.
     */
    public function testPutHubConfigRejectsForeignTransportScheme(): void
    {
        $this->hubRepo->expects(self::never())->method('ensureHubExists');

        $request = new Request();
        $request->path = '/api/v1/me/federation/hub-config';
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['url' => 'ftp://hub.example.com'];

        $response = $this->controller->putHubConfig($request);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('invalid_url', $body['code'] ?? null);
        self::assertIsString($body['reason'] ?? null);
        self::assertStringContainsString('scheme must be one of', $body['reason']);
    }

    public function testPutHubConfigRejectsMalformedUrl(): void
    {
        $this->hubRepo->expects(self::never())->method('ensureHubExists');

        $request = new Request();
        $request->path = '/api/v1/me/federation/hub-config';
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['url' => 'not a url at all'];

        $response = $this->controller->putHubConfig($request);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('invalid_url', $body['code'] ?? null);
    }

    public function testPutHubConfigAcceptsTransportSchemeUrl(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id' => 'hub-1',
            'name' => 'Hub',
            'url' => 'https://old.example.com',
            'public_key' => 'key',
            'role' => 'master',
            'is_master' => 1,
            'is_active' => 1,
        ]);
        $this->hubRepo->expects(self::once())
            ->method('ensureHubExists')
            ->with('Hub', 'https://advertised.example.com', 'key');

        $request = new Request();
        $request->path = '/api/v1/me/federation/hub-config';
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['url' => 'https://advertised.example.com'];

        $response = $this->controller->putHubConfig($request);

        self::assertSame(200, $response->statusCode);
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

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers/peer-1/relay';
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['enabled' => true];

        $response = $this->controller->toggleRelay($request, ['id' => 'peer-1']);

        self::assertSame(200, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertTrue($body['relay_enabled']);
        // Review hub__asphp: relay enable on a leaf hub REQUESTS the worker
        // dial; it no longer touches a peer manager that could not own the
        // link (this test's unconfigured hub config is leaf).
        self::assertSame(
            [FederationPushProtocol::ACTION_LEAF_CONNECT],
            $this->leafCommandActions(),
            'toggleRelay must request exactly the worker-side connect',
        );
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
        $body = self::arrayNode(json_decode($response->body, true));
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
        $body = self::arrayNode(json_decode($response->body, true));
        $shares = self::arrayNode($body['outgoing_shares']);
        self::assertCount(1, $shares);
        self::assertSame('share-1', self::arrayNode($shares[0])['id']);
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
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertNotEmpty($body['id']);
        self::assertSame('lib-1', $body['library_id']);
        self::assertSame('peer-1', $body['peer_id']);
    }

    private function outgoingShareRequest(): Request
    {
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

        return $request;
    }

    private function givenKnownPeer(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'name' => 'Peer Hub',
            'url' => 'https://peer.example.com',
            'public_key' => 'peer-key',
            'created_at' => '2001-02-03 04:05:06',
        ]);
    }

    public function testCreateOutgoingShareEchoesStoredSharedAt(): void
    {
        $this->givenKnownPeer();
        $this->libraryShares->method('getOutgoingShareById')->willReturn(['shared_at' => '2002-03-04 05:06:07']);

        $response = $this->controller->createOutgoingShare($this->outgoingShareRequest());
        $body = self::arrayNode(json_decode($response->body, true));

        self::assertSame('2002-03-04 05:06:07', $body['shared_at']);
    }

    public function testCreateOutgoingShareKeepsStringShapeWhenReselectMisses(): void
    {
        $this->givenKnownPeer();
        $this->libraryShares->method('getOutgoingShareById')->willReturn(null);

        $response = $this->controller->createOutgoingShare($this->outgoingShareRequest());
        $body = self::arrayNode(json_decode($response->body, true));

        self::assertIsString($body['shared_at'], 'a missed re-SELECT must not emit a null timestamp');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['shared_at']);
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
        $body = self::arrayNode(json_decode($response->body, true));
        $offers = self::arrayNode($body['incoming_offers']);
        self::assertCount(1, $offers);
        self::assertSame('offer-1', self::arrayNode($offers[0])['id']);
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
        $body = self::arrayNode(json_decode($response->body, true));
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
        $body = self::arrayNode(json_decode($response->body, true));
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
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertCount(1, self::arrayNode($body['delegations']));
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

    /** @return array<string, mixed> */
    private function masterHubConfig(): array
    {
        return [
            'id' => 'hub-1',
            'name' => 'Master Hub',
            'url' => 'https://master.example.com',
            'public_key' => 'key',
            'role' => 'master',
            'is_master' => 1,
            'is_active' => 1,
        ];
    }

    private function adminDelegationRequest(): Request
    {
        $request = new Request();
        $request->path = '/api/v1/me/federation/admin-delegations';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = ['peer_id' => 'peer-1', 'user_id' => 'user-1'];

        return $request;
    }

    public function testCreateAdminDelegationEchoesStoredGrantedAt(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn($this->masterHubConfig());
        $this->hubRepo->method('getPeerById')->willReturn(['id' => 'peer-1', 'name' => 'Peer Hub']);
        $this->adminDel->method('getDelegationById')->willReturn(['granted_at' => '2003-04-05 06:07:08']);

        $response = $this->controller->createAdminDelegation($this->adminDelegationRequest());
        $body = self::arrayNode(json_decode($response->body, true));

        self::assertSame('2003-04-05 06:07:08', $body['granted_at']);
    }

    public function testCreateAdminDelegationKeepsStringShapeWhenReselectMisses(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn($this->masterHubConfig());
        $this->hubRepo->method('getPeerById')->willReturn(['id' => 'peer-1', 'name' => 'Peer Hub']);
        $this->adminDel->method('getDelegationById')->willReturn(null);

        $response = $this->controller->createAdminDelegation($this->adminDelegationRequest());
        $body = self::arrayNode(json_decode($response->body, true));

        self::assertIsString($body['granted_at'], 'a missed re-SELECT must not emit a null timestamp');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['granted_at']);
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
        $body = self::arrayNode(json_decode($response->body, true));
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

    // ------------------------------------------------- peer bootstrap binding

    public function testCreatePeerPassesValidLeafHubIdToRepository(): void
    {
        $this->hubRepo->method('getPeerByUrl')->willReturn(null);
        $this->hubRepo->method('getPeerByPublicKey')->willReturn(null);
        $this->hubRepo->expects(self::once())
            ->method('createPeer')
            ->with(
                self::isType('string'),
                'New Peer Hub',
                'https://new-peer.example.com',
                'new-peer-key',
                'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            );

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'https://new-peer.example.com',
            'public_key' => 'new-peer-key',
            'name' => 'New Peer Hub',
            'leaf_hub_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ];

        $response = $this->controller->createPeer($request);

        self::assertSame(201, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $body['leaf_hub_id']);
    }

    public function testCreatePeerRejectsMalformedLeafHubId(): void
    {
        $this->hubRepo->expects(self::never())->method('createPeer');

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'https://new-peer.example.com',
            'public_key' => 'new-peer-key',
            'name' => 'New Peer Hub',
            'leaf_hub_id' => 'not-a-uuid',
        ];

        $response = $this->controller->createPeer($request);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        // Wire-law shape: registered dedicated code on `code`, the specific
        // condition rides `reason` — never an unregistered code literal.
        self::assertSame('leaf_hub_id_already_bound', $body['code']);
        self::assertSame('leaf_hub_id must be a UUID', $body['reason']);
    }

    public function testBindPeerLeafHubIdSetsBindingOnUnboundPeer(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'name' => 'Peer',
            'leaf_hub_id' => '',
        ]);
        $this->hubRepo->expects(self::once())
            ->method('setPeerLeafHubId')
            ->with('peer-1', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $request = new Request();
        $request->path = '/api/v1/me/federation/peers/peer-1/leaf-hub-id';
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['leaf_hub_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];

        $response = $this->controller->bindPeerLeafHubId($request, ['id' => 'peer-1']);

        self::assertSame(200, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $body['leaf_hub_id']);
    }

    public function testBindPeerLeafHubIdIsIdempotentForSameValue(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'name' => 'Peer',
            'leaf_hub_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);
        $this->hubRepo->expects(self::once())->method('setPeerLeafHubId');

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['leaf_hub_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];

        $response = $this->controller->bindPeerLeafHubId($request, ['id' => 'peer-1']);

        self::assertSame(200, $response->statusCode);
    }

    public function testBindPeerLeafHubIdReturns409WhenBoundToDifferentPeer(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'name' => 'Peer',
            'leaf_hub_id' => '11111111-2222-3333-4444-555555555555',
        ]);
        $this->hubRepo->expects(self::never())->method('setPeerLeafHubId');

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['leaf_hub_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];

        $response = $this->controller->bindPeerLeafHubId($request, ['id' => 'peer-1']);

        self::assertSame(409, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        // Dedicated leaf-hub-id conflict code per the contracts wire law
        // (minted v0.5.3; the specific condition still rides reason).
        self::assertSame('leaf_hub_id_already_bound', $body['code']);
        self::assertStringContainsString('already bound', self::stringNode($body['reason']));
    }

    public function testBindPeerLeafHubIdReturns404ForUnknownPeer(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn(null);

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['leaf_hub_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];

        $response = $this->controller->bindPeerLeafHubId($request, ['id' => 'ghost']);

        self::assertSame(404, $response->statusCode);
    }

    public function testBindPeerLeafHubIdRejectsMalformedUuid(): void
    {
        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['leaf_hub_id' => 'nope'];

        $response = $this->controller->bindPeerLeafHubId($request, ['id' => 'peer-1']);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        // Wire-law shape: registered dedicated code on `code`, the specific
        // condition rides `reason` — never an unregistered code literal.
        self::assertSame('leaf_hub_id_already_bound', $body['code']);
        self::assertSame('leaf_hub_id must be a UUID', $body['reason']);
    }

    // ------------------------------------------------ master-side share push

    private function asMaster(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id' => 'master-hub-uuid',
            'is_master' => 1,
        ]);
    }

    public function testMasterCreateOutgoingSharePushesOfferDownToLeaf(): void
    {
        $this->asMaster();
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'name' => 'Leaf',
            'leaf_hub_id' => 'leaf-own-uuid',
        ]);
        $this->libraryShares->method('getOutgoingShareById')->willReturn([
            'id' => 'share-x',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
            'permission' => 'read',
            'status' => 'active',
        ]);

        $this->masterPusher->expects(self::once())
            ->method('pushOffer')
            ->with('peer-1', self::callback(
                static fn (array $row): bool => $row['id'] === 'share-x'
                    && $row['library_id'] === 'lib-1'
            ));
        // Leaf-side commands must NOT run on a master (review hub__asphp:
        // the master's pushes go DOWN via the master pusher only).
        self::assertSame([], $this->leafCommandActions());

        $request = new Request();
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
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('active', $body['status']);
    }

    public function testLeafCreateOutgoingShareRequestsWorkerUpPush(): void
    {
        // getHubConfig null → leaf path (mirrors unconfigured/leaf deployments).
        $this->hubRepo->method('getHubConfig')->willReturn(null);
        $this->hubRepo->method('getPeerById')->willReturn(['id' => 'peer-1']);

        $this->masterPusher->expects(self::never())->method('pushOffer');

        $request = new Request();
        $request->method = 'POST';
        $request->userId = 'admin-1';
        $request->body = [
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
            'peer_id' => 'peer-1',
            'permission' => 'read',
        ];

        self::assertSame(201, $this->controller->createOutgoingShare($request)->statusCode);

        self::assertSame(
            [FederationPushProtocol::ACTION_LEAF_SHARE_OFFER],
            $this->leafCommandActions(),
            'leaf share create must REQUEST the worker-side up-push',
        );
        $offerCommand = $this->leafCommands[0]['data'];
        self::assertSame('', $offerCommand['leaf_hub_id'], 'leaf family addresses the worker itself');
        self::assertIsString($offerCommand['payload']);
        $offerPayload = self::arrayNode(json_decode($offerCommand['payload'], true));
        self::assertSame('My Movies', $offerPayload['library_name']);
        self::assertSame('lib-1', $offerPayload['library_id']);
        self::assertSame('read', $offerPayload['permission']);
        self::assertIsString($offerPayload['share_id']);
    }

    public function testMasterRevokeOutgoingSharePushesRevocationDownToLeaf(): void
    {
        $this->asMaster();
        $this->libraryShares->method('getOutgoingShareById')->willReturn([
            'id' => 'share-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
        ]);

        $this->masterPusher->expects(self::once())
            ->method('pushRevocation')
            ->with('peer-1', 'share-1');
        self::assertSame([], $this->leafCommandActions(), 'master revoke emits no leaf commands');

        $request = new Request();
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        self::assertSame(204, $this->controller->revokeOutgoingShare($request, ['id' => 'share-1'])->statusCode);
    }

    public function testLeafRevokeOutgoingShareRequestsWorkerUpPush(): void
    {
        // getHubConfig null → leaf path (same posture as the leaf-create test).
        $this->hubRepo->method('getHubConfig')->willReturn(null);
        $this->libraryShares->method('getOutgoingShareById')->willReturn([
            'id' => 'share-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
        ]);

        $this->masterPusher->expects(self::never())->method('pushRevocation');

        $request = new Request();
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        self::assertSame(204, $this->controller->revokeOutgoingShare($request, ['id' => 'share-1'])->statusCode);
        self::assertSame([FederationPushProtocol::ACTION_LEAF_SHARE_REVOCATION], $this->leafCommandActions());
        $rawRevocation = $this->leafCommands[0]['data']['payload'];
        self::assertIsString($rawRevocation);
        $payload = self::arrayNode(json_decode($rawRevocation, true));
        self::assertSame('share-1', $payload['share_id']);
    }

    public function testRoleChangeToLeafRequestsWorkerDisconnectThenConnect(): void
    {
        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['role' => 'leaf'];

        $response = $this->controller->putHubConfig($request);

        self::assertSame(200, $response->statusCode);
        // Review hub__asphp: the pair crosses as REQUESTS in order — the old
        // code ran them against a process-local instance that owned neither.
        self::assertSame(
            [
                FederationPushProtocol::ACTION_LEAF_DISCONNECT,
                FederationPushProtocol::ACTION_LEAF_CONNECT,
            ],
            $this->leafCommandActions(),
        );
    }

    public function testDeactivationRequestsWorkerDisconnectOnly(): void
    {
        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['is_active' => true];

        self::assertSame(200, $this->controller->putHubConfig($request)->statusCode);
        self::assertSame([], $this->leafCommandActions(), 'activating sends no leaf command');

        $this->leafCommands = [];
        $request->body = ['is_active' => false];
        self::assertSame(200, $this->controller->putHubConfig($request)->statusCode);
        self::assertSame([FederationPushProtocol::ACTION_LEAF_DISCONNECT], $this->leafCommandActions());
    }

    public function testDeletePeerClosesLiveFederationConnection(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'name' => 'Peer',
            'status' => 'connected',
        ]);
        $this->masterPusher->expects(self::once())
            ->method('closePeerConnection')
            ->with('peer-1');

        $request = new Request();
        $request->method = 'DELETE';
        $request->userId = 'admin-1';

        self::assertSame(204, $this->controller->deletePeer($request, ['id' => 'peer-1'])->statusCode);
    }

    // ── SH-077 (review hub__webux #3/#4/#5) — write-path atomicity, strict
    //    toggles, honest responder fields ────────────────────────────────────

    /**
     * #3: a `{role, bad url}` body must be a clean 400 — the old sequential
     * composition persisted the role switch (and fired the worker intents)
     * before validating the URL, so the caller's "failed" request had already
     * moved the hub to the other side.
     */
    public function testPutHubConfigRoleWithMalformedUrlWritesNothingAndSendsNoIntents(): void
    {
        $this->hubRepo->expects(self::never())->method('updateRole');
        $this->hubRepo->expects(self::never())->method('ensureHubExists');

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['role' => 'leaf', 'url' => 'not a url at all'];

        $response = $this->controller->putHubConfig($request);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('invalid_url', $body['code'] ?? null);
        self::assertSame([], $this->leafCommandActions(), 'rejected body must publish zero worker intents');
    }

    public function testPutHubConfigRoleWithForeignSchemeUrlWritesNothingAndSendsNoIntents(): void
    {
        $this->hubRepo->expects(self::never())->method('updateRole');
        $this->hubRepo->expects(self::never())->method('ensureHubExists');

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['role' => 'master', 'url' => 'ftp://hub.example.com'];

        $response = $this->controller->putHubConfig($request);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('invalid_url', $body['code'] ?? null);
        self::assertSame([], $this->leafCommandActions());
    }

    /**
     * #4: the `enabled` field is strictly parsed now — ambiguous spellings
     * (ints outside 1/0, prose, arrays) and absent fields reject with the
     * registered invalid_payload code instead of silently writing FALSE.
     */
    public function testToggleRelayRejectsAmbiguousEnabledValues(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'relay_enabled' => 1,
            'admin_delegation_enabled' => 0,
        ]);
        $this->hubRepo->expects(self::never())->method('updatePeerToggles');

        foreach ([2, 'yes', '', [], null, ['true']] as $ambiguous) {
            $request = new Request();
            $request->method = 'PUT';
            $request->userId = 'admin-1';
            $request->body = ['enabled' => $ambiguous];

            $response = $this->controller->toggleRelay($request, ['id' => 'peer-1']);

            self::assertSame(400, $response->statusCode, 'ambiguous enabled value must reject');
            $body = self::arrayNode(json_decode($response->body, true));
            self::assertSame('invalid_payload', $body['code'] ?? null);
        }
    }

    public function testToggleRelayRejectsMissingEnabledField(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'relay_enabled' => 1,
            'admin_delegation_enabled' => 0,
        ]);
        $this->hubRepo->expects(self::never())->method('updatePeerToggles');

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['note' => 'flip it'];

        $response = $this->controller->toggleRelay($request, ['id' => 'peer-1']);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('invalid_payload', $body['code'] ?? null);
        // The old coercion treated a missing field as false AND ANSWERED 200
        // while tearing relay down; nothing may leave this process now.
        self::assertSame([], $this->leafCommandActions());
    }

    /**
     * The documented accepted spellings — real booleans plus the string/int
     * forms senders already use — all still parse (byte-compat law for the
     * 'true'/'false' strings; 1/0 ride the DB row form).
     */
    public function testToggleRelayAcceptsDocumentedBooleanSpellings(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'relay_enabled' => 0,
            'admin_delegation_enabled' => 0,
        ]);

        $truthy = [true, 'true', 1, '1'];
        $falsy = [false, 'false', 0, '0'];

        foreach ($truthy as $raw) {
            $request = new Request();
            $request->method = 'PUT';
            $request->userId = 'admin-1';
            $request->body = ['enabled' => $raw];

            $response = $this->controller->toggleRelay($request, ['id' => 'peer-1']);
            self::assertSame(200, $response->statusCode);
            $body = self::arrayNode(json_decode($response->body, true));
            self::assertTrue($body['relay_enabled'], var_export($raw, true) . ' must parse true');
        }

        foreach ($falsy as $raw) {
            $request = new Request();
            $request->method = 'PUT';
            $request->userId = 'admin-1';
            $request->body = ['enabled' => $raw];

            $response = $this->controller->toggleRelay($request, ['id' => 'peer-1']);
            self::assertSame(200, $response->statusCode);
            $body = self::arrayNode(json_decode($response->body, true));
            self::assertFalse($body['relay_enabled'], var_export($raw, true) . ' must parse false');
        }
    }

    public function testToggleAdminDelegationRejectsNonBooleanEnabled(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-1',
            'relay_enabled' => 1,
            'admin_delegation_enabled' => 1,
        ]);
        $this->hubRepo->expects(self::never())->method('updatePeerToggles');

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['enabled' => 'yes'];

        $response = $this->controller->toggleAdminDelegation($request, ['id' => 'peer-1']);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('invalid_payload', $body['code'] ?? null);
    }

    /**
     * #4 family cure: hub-config `is_active` parses strictly too — and the
     * optional-field law keeps an ABSENT field a no-op rather than a rejection.
     */
    public function testPutHubConfigRejectsNonBooleanIsActive(): void
    {
        $this->hubRepo->expects(self::never())->method('updateActive');

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['is_active' => 'yes'];

        $response = $this->controller->putHubConfig($request);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('invalid_payload', $body['code'] ?? null);
        self::assertSame([], $this->leafCommandActions());
    }

    public function testPutHubConfigTreatsAbsentIsActiveAsNoOp(): void
    {
        $this->hubRepo->expects(self::never())->method('updateActive');

        $request = new Request();
        $request->method = 'PUT';
        $request->userId = 'admin-1';
        $request->body = ['name' => 'Renamed'];

        self::assertSame(200, $this->controller->putHubConfig($request)->statusCode);
    }

    /**
     * #5: one DB column records WHOEVER responded; the list frame must not
     * publish a rejection's actor as an acceptance.
     */
    public function testIncomingOfferListNamesResponderHonestly(): void
    {
        $this->libraryShares->method('getIncomingOffers')->willReturn([
            ['id' => 'o-accept', 'peer_id' => 'p', 'library_id' => 'l', 'permission' => 'read',
                'status' => 'accepted', 'offered_at' => null, 'responded_at' => 'x', 'accepted_by' => 'u-1'],
            ['id' => 'o-reject', 'peer_id' => 'p', 'library_id' => 'l', 'permission' => 'read',
                'status' => 'rejected', 'offered_at' => null, 'responded_at' => 'y', 'accepted_by' => 'u-2'],
            ['id' => 'o-pending', 'peer_id' => 'p', 'library_id' => 'l', 'permission' => 'read',
                'status' => 'pending', 'offered_at' => null, 'responded_at' => null, 'accepted_by' => null],
        ]);

        $request = new Request();
        $request->method = 'GET';

        $response = $this->controller->getIncomingOffers($request);
        self::assertSame(200, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        $offers = self::arrayNode($body['incoming_offers']);

        $byId = [];
        foreach ($offers as $offer) {
            $offer = self::arrayNode($offer);
            $offerId = $offer['id'];
            self::assertIsString($offerId);
            $byId[$offerId] = $offer;
        }

        self::assertSame('u-1', $byId['o-accept']['accepted_by']);
        self::assertSame('u-1', $byId['o-accept']['responded_by']);
        self::assertNull($byId['o-reject']['accepted_by'], 'a rejection must not claim an accepter');
        self::assertSame('u-2', $byId['o-reject']['responded_by']);
        self::assertNull($byId['o-pending']['accepted_by']);
        self::assertNull($byId['o-pending']['responded_by']);
    }

    public function testAcceptIncomingOfferRespondsBothFaces(): void
    {
        $this->libraryShares->method('getIncomingOfferById')->willReturn([
            'id' => 'offer-1', 'peer_id' => 'peer-1', 'library_id' => 'lib-1',
            'permission' => 'read', 'status' => 'pending',
        ]);

        $request = new Request();
        $request->method = 'POST';
        $request->userId = 'admin-1';

        $response = $this->controller->acceptIncomingOffer($request, ['id' => 'offer-1']);

        self::assertSame(200, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('accepted', $body['status']);
        self::assertSame('admin-1', $body['accepted_by']);
        self::assertSame('admin-1', $body['responded_by']);
    }

    public function testRejectIncomingOfferNeverClaimsAcceptance(): void
    {
        $this->libraryShares->method('getIncomingOfferById')->willReturn([
            'id' => 'offer-1', 'peer_id' => 'peer-1', 'library_id' => 'lib-1',
            'permission' => 'read', 'status' => 'pending',
        ]);

        $request = new Request();
        $request->method = 'POST';
        $request->userId = 'admin-1';

        $response = $this->controller->rejectIncomingOffer($request, ['id' => 'offer-1']);

        self::assertSame(200, $response->statusCode);
        $body = self::arrayNode(json_decode($response->body, true));
        self::assertSame('rejected', $body['status']);
        self::assertArrayNotHasKey('accepted_by', $body, 'reject frame must not carry an acceptance claim');
        self::assertSame('admin-1', $body['responded_by']);
    }
}
