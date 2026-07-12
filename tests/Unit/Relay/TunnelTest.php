<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

class TunnelTest extends TestCase
{
    private RelayWireCodecInterface $codec;
    private StructuredLogger $logger;
    private StructuredLogger $clientLogger;
    private RelaySessionManager $sessionManager;
    private TcpConnection $serverWs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameDecoder();
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->clientLogger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->serverWs = $this->createMock(TcpConnection::class);
    }

    public function test_tunnel_initializes_with_pending_status(): void
    {
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $this->assertSame(Tunnel::STATUS_PENDING, $tunnel->status);
        $this->assertSame('server-123', $tunnel->serverId);
        $this->assertCount(0, $tunnel->clientConnections);
        $this->assertSame(0, $tunnel->seq);
        $this->assertNotEmpty($tunnel->tunnelId);
    }

    public function test_tunnel_transitions_to_active_on_hello(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->expects($this->once())
            ->method('registerServer')
            ->with('server-123', $this->anything())
            ->willReturn($sessionId);

        $this->serverWs
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (string $data): bool {
                // Should be JSON hello_ack
                $decoded = json_decode($data, true);
                return is_array($decoded)
                    && ($decoded['type'] ?? null) === 'hello_ack'
                    && isset($decoded['relay_session_id'])
                    && isset($decoded['tunnel_id']);
            }));

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $helloPayload = json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt.test.test',
            'server_id' => 'server-123',
        ]);

        $tunnel->onServerMessage($helloPayload);

        $this->assertSame(Tunnel::STATUS_ACTIVE, $tunnel->status);
        $this->assertSame($sessionId, $tunnel->relaySessionId);
    }

    public function test_tunnel_closes_on_malformed_hello(): void
    {
        $this->sessionManager
            ->expects($this->never())
            ->method('registerServer');

        $this->serverWs
            ->expects($this->once())
            ->method('close');

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        // Send malformed HELLO (not JSON)
        $tunnel->onServerMessage('not valid json');

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    public function test_send_to_client_routes_only_to_the_owning_channel(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // Set up tunnel in ACTIVE state
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $this->serverWs->method('send');

        // Register two clients — they receive channel ids 1 and 2.
        $clientWs1 = $this->createMock(TcpConnection::class);
        $clientWs2 = $this->createMock(TcpConnection::class);

        $sentData1 = null;
        $sentData2 = null;

        // Only client 1 must receive the channel-1 DATA frame.
        $clientWs1
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData1): void {
                $sentData1 = $data;
            });
        $clientWs2
            ->expects($this->never())
            ->method('send');

        $client1 = new ClientConnection($clientWs1, 'server-123', 'client-1', $this->clientLogger, '');
        $client2 = new ClientConnection($clientWs2, 'server-123', 'client-2', $this->clientLogger, '');

        $tunnel->registerClient($client1);
        $tunnel->registerClient($client2);

        $this->assertSame(1, $client1->channelId);
        $this->assertSame(2, $client2->channelId);

        // DATA for channel 1 must reach only client 1.
        $frame = new RelayFrame(RelayFrameType::DATA, $client1->channelId, 'hello world');
        $tunnel->sendToClient($client1->channelId, $frame);

        $this->assertNotNull($sentData1);

        // Decode what client 1 received and confirm the payload survived.
        $decoded = $this->codec->decode($sentData1);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame('hello world', $decoded->payload);
        $this->assertSame(1, $decoded->channelId());
    }

    public function test_send_to_client_drops_data_for_unknown_channel(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $this->serverWs->method('send');

        // One registered client on channel 1.
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->expects($this->never())->method('send');
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client);

        // DATA for a channel that was never assigned (99) must be dropped.
        $frame = new RelayFrame(RelayFrameType::DATA, 99, 'orphan');
        $tunnel->sendToClient(99, $frame);

        // bytesIn untouched — nothing delivered.
        $this->assertSame(0, $tunnel->getBytesIn());
    }

    public function test_send_to_server_encodes_and_records_bytes(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // Set up tunnel in ACTIVE state
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $sentData = null;
        $this->serverWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $this->sessionManager
            ->expects($this->once())
            ->method('recordBytesOut')
            ->with($sessionId, $this->greaterThan(0));

        $frame = new RelayFrame(RelayFrameType::DATA, 1, 'hello server');

        $tunnel->sendToServer($frame);

        $this->assertNotNull($sentData);
    }

    public function test_server_close_closes_all_clients_and_session(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // Set up tunnel in ACTIVE state
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Add a mock client connection
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->expects($this->once())->method('send');
        $clientWs->expects($this->once())->method('close');

        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->clientConnections->attach($client);

        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with($sessionId, 'server_disconnected');

        $tunnel->onServerClose();

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
        $this->assertCount(0, $tunnel->clientConnections);
    }

    public function test_is_stale_returns_true_when_idle(): void
    {
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        // Set lastFrameAt to 100 seconds ago
        $tunnel->lastFrameAt = time() - 100;

        $this->assertTrue($tunnel->isStale(90));
        $this->assertFalse($tunnel->isStale(120));
    }

    public function test_register_client_sends_client_connect_to_server(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $sentData = null;
        $this->serverWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $clientWs = $this->createMock(TcpConnection::class);
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, 'relay-session-1');

        $tunnel->registerClient($client);

        $this->assertCount(1, $tunnel->clientConnections);
        $this->assertNotNull($sentData);

        // Verify CLIENT_CONNECT frame was sent
        $decoded = $this->codec->decode($sentData);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::CLIENT_CONNECT, $decoded->type);
    }

    public function test_remove_client_sends_client_disconnect_to_server(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $clientWs = $this->createMock(TcpConnection::class);
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, 'relay-session-1');

        // Capture every frame sent to the server (CLIENT_CONNECT then DISCONNECT).
        $sent = [];
        $this->serverWs
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sent): void {
                $sent[] = $data;
            });

        $tunnel->registerClient($client); // assigns channel 1, sends CLIENT_CONNECT
        $channelId = $client->channelId;
        $this->assertSame(1, $channelId);

        $tunnel->removeClient($client);

        $this->assertCount(0, $tunnel->clientConnections);
        $this->assertNotEmpty($sent);

        // The LAST frame sent is the CLIENT_DISCONNECT, tagged with the channel id.
        $decoded = $this->codec->decode($sent[count($sent) - 1]);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::CLIENT_DISCONNECT, $decoded->type);
        $this->assertSame($channelId, $decoded->channelId());
    }

    public function test_send_heartbeat_does_not_touch_last_frame_at(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $initialLastFrameAt = $tunnel->lastFrameAt;

        usleep(1000);
        $tunnel->sendHeartbeat();

        $this->assertSame($initialLastFrameAt, $tunnel->lastFrameAt);
    }

    public function test_is_stale_reflects_last_inbound_frame_not_outbound_heartbeat(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $tunnel->lastFrameAt = time() - 100;
        $this->assertTrue($tunnel->isStale(90));

        usleep(1000);
        $tunnel->sendHeartbeat();

        $this->assertTrue($tunnel->isStale(90), 'Tunnel should still be stale after outbound heartbeat (lastFrameAt must not be refreshed by sendHeartbeat)');

        $tunnel->onServerMessage($this->codec->encode(RelayFrameType::HEARTBEAT, 0, ''));

        $this->assertFalse($tunnel->isStale(90), 'Tunnel should not be stale after inbound heartbeat (lastFrameAt updated by onServerMessage)');
    }

    public function test_send_to_client_records_bytes_in_for_the_target_only(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $this->serverWs->method('send');

        // Three registered clients (channels 1, 2, 3).
        $clientWs1 = $this->createMock(TcpConnection::class);
        $clientWs2 = $this->createMock(TcpConnection::class);
        $clientWs3 = $this->createMock(TcpConnection::class);
        $clientWs1->method('send');
        $clientWs2->method('send');
        $clientWs3->method('send');

        $client1 = new ClientConnection($clientWs1, 'server-123', 'client-1', $this->clientLogger, '');
        $client2 = new ClientConnection($clientWs2, 'server-123', 'client-2', $this->clientLogger, '');
        $client3 = new ClientConnection($clientWs3, 'server-123', 'client-3', $this->clientLogger, '');

        $tunnel->registerClient($client1);
        $tunnel->registerClient($client2);
        $tunnel->registerClient($client3);

        // recordBytesIn is called exactly once — only for the routed client.
        $this->sessionManager
            ->expects($this->once())
            ->method('recordBytesIn')
            ->with($sessionId, $this->greaterThan(0));

        $frame = new RelayFrame(RelayFrameType::DATA, $client2->channelId, 'hello world');
        $tunnel->sendToClient($client2->channelId, $frame);

        $this->assertGreaterThan(0, $tunnel->getBytesIn());
    }

    public function test_send_to_server_increments_bytes_out(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $this->serverWs->method('send');

        $this->assertSame(0, $tunnel->getBytesOut());

        $frame = new RelayFrame(RelayFrameType::DATA, 1, 'hello');
        $tunnel->sendToServer($frame);

        $this->assertGreaterThan(0, $tunnel->getBytesOut());
    }

    public function test_send_to_client_increments_bytes_in(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $this->serverWs->method('send');

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send');

        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client);

        $this->assertSame(0, $tunnel->getBytesIn());

        $frame = new RelayFrame(RelayFrameType::DATA, $client->channelId, 'hello world');
        $tunnel->sendToClient($client->channelId, $frame);

        $this->assertGreaterThan(0, $tunnel->getBytesIn());
    }

    public function test_client_to_server_data_is_tagged_with_channel_id(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $sent = [];
        $this->serverWs
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sent): bool {
                $sent[] = $data;
                return true;
            });

        $client = new ClientConnection(
            $this->createMock(TcpConnection::class),
            'server-123',
            'client-1',
            $this->clientLogger,
            '',
        );
        $tunnel->registerClient($client); // channel 1; CLIENT_CONNECT is sent

        // Client sends DATA with an arbitrary seq — the hub must overwrite it
        // with the client's channel id before forwarding to the server.
        $clientFrame = new RelayFrame(RelayFrameType::DATA, 999, 'client-bytes');
        $tunnel->sendClientData($client, $clientFrame);

        // Last frame sent to the server is the tagged DATA frame.
        $decoded = $this->codec->decode($sent[count($sent) - 1]);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::DATA, $decoded->type);
        $this->assertSame($client->channelId, $decoded->channelId());
        $this->assertSame('client-bytes', $decoded->payload);
    }

    /**
     * Build a syntactically valid JWT whose header carries the given kid.
     */
    private function jwtWithKid(string $kid): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64((string) json_encode(['alg' => 'EdDSA', 'kid' => $kid]))
            . '.' . $b64('{}')
            . '.' . $b64('signature');
    }

    public function test_hello_with_valid_jwt_activates_tunnel(): void
    {
        $jwt = $this->jwtWithKid('k1');

        $jwtService = $this->createMock(\Phlix\Hub\Hub\EnrollmentJwtService::class);
        $jwtService->expects($this->once())
            ->method('validateEnrollmentJwt')
            ->with($jwt, 'k1')
            ->willReturn(['server_id' => 'server-123']);

        $this->sessionManager->method('registerServer')->willReturn('sess-1');

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            $jwtService,
        );

        $tunnel->onServerMessage((string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => $jwt,
            'server_id' => 'server-123',
        ]));

        $this->assertSame(Tunnel::STATUS_ACTIVE, $tunnel->status);
    }

    public function test_hello_with_invalid_jwt_rejects_tunnel(): void
    {
        $jwt = $this->jwtWithKid('k1');

        $jwtService = $this->createMock(\Phlix\Hub\Hub\EnrollmentJwtService::class);
        $jwtService->method('validateEnrollmentJwt')->willReturn(null);

        $this->sessionManager->expects($this->never())->method('registerServer');
        $this->serverWs->expects($this->once())->method('close');

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            $jwtService,
        );

        $tunnel->onServerMessage((string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => $jwt,
            'server_id' => 'server-123',
        ]));

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    public function test_hello_with_mismatched_server_id_in_jwt_rejects_tunnel(): void
    {
        $jwt = $this->jwtWithKid('k1');

        $jwtService = $this->createMock(\Phlix\Hub\Hub\EnrollmentJwtService::class);
        // Token is valid but minted for a different server.
        $jwtService->method('validateEnrollmentJwt')->willReturn(['server_id' => 'other-server']);

        $this->sessionManager->expects($this->never())->method('registerServer');

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            $jwtService,
        );

        $tunnel->onServerMessage((string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => $jwt,
            'server_id' => 'server-123',
        ]));

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    public function test_http_response_frame_is_routed_to_proxy_manager(): void
    {
        $managerLogger = $this->createMock(StructuredLogger::class);
        // An HTTP_RESPONSE for an unknown request id makes the manager log a
        // warning — proving the tunnel routed the frame to it.
        $managerLogger->expects($this->atLeastOnce())->method('warning');

        $proxyManager = new \Phlix\Hub\Relay\RelayProxyManager(
            $this->createMock(\Phlix\Hub\Relay\TunnelManagerInterface::class),
            $managerLogger,
            30,
            static function (string $e, array $d): void {
            },
        );

        $this->sessionManager->method('registerServer')->willReturn('sess-1');

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            null,
            $proxyManager,
        );

        // Activate.
        $tunnel->onServerMessage((string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'a.b.c',
            'server_id' => 'server-123',
        ]));

        // Feed an HTTP_RESPONSE frame (END chunk) for an unknown request id.
        $frame = $this->codec->encode(
            RelayFrameType::HTTP_RESPONSE,
            12345,
            \Phlix\Shared\Relay\RelayHttpResponseCodec::encodeEnd(),
        );
        $tunnel->onServerMessage($frame);

        $this->assertSame(Tunnel::STATUS_ACTIVE, $tunnel->status);
    }

    public function test_active_tunnel_closes_cleanly_on_undecodable_frame(): void
    {
        // A JSON HELLO/HELLO_ACK ({"type":...) arriving on an already-ACTIVE
        // tunnel is read by the binary FrameDecoder as frame type 0x70 ('p', the
        // 5th byte of `{"type"`). This must NOT bubble the InvalidFrameTypeException
        // out of the Workerman message callback; the tunnel must close cleanly so
        // the server reconnects and re-handshakes.
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Relay: undecodable frame from server, closing tunnel to resync',
                $this->callback(static fn (array $ctx): bool => isset($ctx['error'])
                    && str_contains((string) $ctx['error'], '0x70')),
            );

        // Does not throw (the assertion below is only reached if it returned).
        $tunnel->onServerMessage('{"type":"hello","enrollment_jwt":"a.b.c","server_id":"server-123"}');

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    // ---------------------------------------------------------------------
    // HB-1.2 — data-plane backpressure: no silent drop of a DATA/body frame.
    // ---------------------------------------------------------------------

    /**
     * Build an already-ACTIVE tunnel wired to the shared mocks.
     */
    private function activeTunnel(string $sessionId = 'session-456'): Tunnel
    {
        $this->sessionManager->method('registerServer')->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        return $tunnel;
    }

    /**
     * CLIENT DATA path: when a client's send buffer is full, the server→client
     * DATA frame must NOT be dropped (Workerman discards the package on a full
     * buffer). It must be re-queued, the SERVER paused (upstream backpressure),
     * and the frame delivered byte-exact when the client's buffer drains.
     */
    public function test_send_to_client_requeues_dropped_frame_and_delivers_it_on_drain(): void
    {
        $tunnel = $this->activeTunnel();

        // AC (b)/(c): server recv paused when the client fills, resumed on drain.
        $this->serverWs->expects($this->once())->method('pauseRecv');
        $this->serverWs->expects($this->once())->method('resumeRecv');
        $this->serverWs->method('send')->willReturn(true); // CLIENT_CONNECT

        // Client whose buffer is "full" for the first send, then drains.
        $clientWs = $this->createMock(TcpConnection::class);
        $full = true;
        $delivered = [];
        $clientWs->method('send')->willReturnCallback(
            function (string $data) use (&$full, &$delivered): bool {
                if ($full) {
                    return false; // buffer full — Workerman would DROP this frame
                }
                $delivered[] = $data;
                return true;
            }
        );

        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client); // channel 1

        $frame = new RelayFrame(RelayFrameType::DATA, $client->channelId, 'STREAM-PAYLOAD');
        $tunnel->sendToClient($client->channelId, $frame);

        // Nothing delivered yet and no bytes counted — the frame is held, not lost.
        $this->assertSame([], $delivered, 'frame must not be delivered while the buffer is full');
        $this->assertSame(0, $tunnel->getBytesIn());

        // Buffer drains — invoke the onBufferDrain handler the tunnel armed.
        $this->assertIsCallable($clientWs->onBufferDrain);
        $full = false;
        ($clientWs->onBufferDrain)();

        // AC (a): the previously-failing frame is delivered byte-exact — zero loss.
        $this->assertCount(1, $delivered);
        $decoded = $this->codec->decode($delivered[0]);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame('STREAM-PAYLOAD', $decoded->payload);
        $this->assertSame($client->channelId, $decoded->channelId());
        $this->assertGreaterThan(0, $tunnel->getBytesIn());
    }

    /**
     * CLIENT DATA path: if the client's buffer never drains, the safety timeout
     * closes the tunnel with the backpressure-timeout reason (a hard, visible
     * failure) rather than leaving a silently corrupt stream.
     */
    public function test_client_backpressure_timeout_closes_tunnel(): void
    {
        $tunnel = $this->activeTunnel();
        $this->serverWs->method('send')->willReturn(true);
        $this->serverWs->method('pauseRecv');
        $this->serverWs->method('resumeRecv');
        $this->serverWs->expects($this->once())->method('close');

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturn(false); // permanently full

        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client);

        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with('session-456', 'backpressure_timeout');

        $frame = new RelayFrame(RelayFrameType::DATA, $client->channelId, 'x');
        $tunnel->sendToClient($client->channelId, $frame); // congested; drain never comes

        // Simulate the safety timer firing (armed via Timer::add, a no-op in tests).
        $method = new \ReflectionMethod($tunnel, 'handleClientBackpressureTimeout');
        $method->setAccessible(true);
        $method->invoke($tunnel);

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    /**
     * H-R6 reconnect-drain: a VALIDATED reconnect moves the incumbent to CLOSING
     * and keeps its clients + server connection alive for the grace period, then
     * hard-closes when the grace timer fires — WITHOUT failing the server's
     * in-flight proxy requests (those now belong to the promoted tunnel, and
     * failServer() is keyed by server_id).
     */
    public function test_begin_drain_keeps_clients_during_grace_then_closes_without_failserver(): void
    {
        // RelayProxyManager is final (cannot be mocked); use a real one and seed
        // one in-flight request for this server. The drain-end close must LEAVE it
        // pending — failServer() (keyed by server_id) would kill the newly
        // promoted tunnel's requests too, defeating the drain.
        $proxyManager = $this->newProxyManager();
        $this->seedPendingRequest($proxyManager, 'server-123');

        $this->sessionManager->method('registerServer')->willReturn('session-456');
        $this->serverWs->method('send')->willReturn(true);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            null,
            $proxyManager,
        );
        $tunnel->relaySessionId = 'session-456';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturn(true);
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client);

        // Begin draining: CLOSING, but clients stay connected during the grace and
        // the in-flight request is untouched.
        $tunnel->beginDrain(5.0, 'server_replaced');
        $this->assertSame(Tunnel::STATUS_CLOSING, $tunnel->status);
        $this->assertCount(1, $tunnel->clientConnections, 'client must stay connected during drain');
        $this->assertCount(1, $this->pendingRequests($proxyManager), 'in-flight request survives during drain');

        // Grace expires → hard close: client disconnected, session closed.
        $clientWs->expects($this->once())->method('close');
        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with('session-456', 'server_replaced');

        $method = new \ReflectionMethod($tunnel, 'handleDrainTimeout');
        $method->setAccessible(true);
        $method->invoke($tunnel);

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
        $this->assertCount(0, $tunnel->clientConnections);
        // The in-flight request is NOT failed at drain end (belongs to the
        // promoted tunnel now).
        $this->assertCount(
            1,
            $this->pendingRequests($proxyManager),
            'drain-end close must NOT failServer() the promoted tunnel\'s requests',
        );
    }

    /**
     * A rejected tunnel that never activated (no relay session) must NOT fail the
     * server's in-flight requests when it closes — otherwise a bad HELLO would
     * 503 the legitimate incumbent's requests (HB-2.2 residual DoS via
     * failServer(), which is keyed by server_id).
     */
    public function test_close_of_never_activated_tunnel_does_not_failserver(): void
    {
        $proxyManager = $this->newProxyManager();
        $this->seedPendingRequest($proxyManager, 'server-123');

        $this->serverWs->method('send')->willReturn(true);

        // PENDING tunnel: no relaySessionId assigned (never validated/activated).
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            null,
            $proxyManager,
        );
        $this->assertSame(Tunnel::STATUS_PENDING, $tunnel->status);
        $this->assertNull($tunnel->relaySessionId);

        $tunnel->close('unauthorized');

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
        // The incumbent's in-flight request must survive the rejected tunnel's close.
        $this->assertCount(
            1,
            $this->pendingRequests($proxyManager),
            'a never-activated tunnel must NOT failServer() the incumbent\'s requests',
        );
    }

    /**
     * Build a real {@see \Phlix\Hub\Relay\RelayProxyManager} (it is final and
     * cannot be doubled) with a stub tunnel manager and a no-op error emitter.
     */
    private function newProxyManager(): \Phlix\Hub\Relay\RelayProxyManager
    {
        return new \Phlix\Hub\Relay\RelayProxyManager(
            $this->createMock(\Phlix\Hub\Relay\TunnelManagerInterface::class),
            $this->createMock(StructuredLogger::class),
            30,
            static function (string $e, array $d): void {
            },
        );
    }

    /**
     * Seed one in-flight pending request for $serverId into the proxy manager's
     * private map (the request-registration path needs a live tunnel + channel).
     */
    private function seedPendingRequest(\Phlix\Hub\Relay\RelayProxyManager $pm, string $serverId): void
    {
        $prop = new \ReflectionProperty(\Phlix\Hub\Relay\RelayProxyManager::class, 'pending');
        $prop->setAccessible(true);
        $prop->setValue($pm, [
            42 => [
                'reply_event' => 'reply-x',
                'request_id' => 'client-req-1',
                'server_id' => $serverId,
                'head' => null,
                'body' => '',
                'stream' => false,
                'stream_started' => false,
                'timeout' => 30.0,
                'stream_opened_at' => microtime(true),
                'sent_at' => microtime(true),
            ],
        ]);
    }

    /**
     * Read the proxy manager's private pending-request map for assertions.
     *
     * @return array<int, mixed>
     */
    private function pendingRequests(\Phlix\Hub\Relay\RelayProxyManager $pm): array
    {
        $prop = new \ReflectionProperty(\Phlix\Hub\Relay\RelayProxyManager::class, 'pending');
        $prop->setAccessible(true);
        /** @var array<int, mixed> $pending */
        $pending = $prop->getValue($pm);
        return $pending;
    }

    /**
     * SERVER low-priority BODY path: when the server's send buffer is full, an
     * HTTP_REQUEST body chunk must NOT be dropped. It must be re-queued, all
     * clients paused (upstream backpressure), and the frame delivered byte-exact
     * when the server's buffer drains.
     */
    public function test_send_to_server_body_requeues_dropped_frame_and_delivers_it_on_drain(): void
    {
        $tunnel = $this->activeTunnel();

        // AC (b)/(c): the OPPOSITE side (all clients) is paused/resumed.
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturn(true);
        $clientWs->expects($this->once())->method('pauseRecv');
        $clientWs->expects($this->once())->method('resumeRecv');
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');

        $full = false;
        $sent = [];
        $this->serverWs->method('send')->willReturnCallback(
            function (string $data) use (&$full, &$sent): bool {
                if ($full) {
                    return false; // buffer full — Workerman would DROP this frame
                }
                $sent[] = $data;
                return true;
            }
        );

        $tunnel->registerClient($client); // CLIENT_CONNECT sent while not full
        $sentBeforeBody = count($sent);

        // Now the server's send buffer is full.
        $full = true;

        // REAL wire codec: a chunked HTTP_REQUEST BODY sub-frame is a tag-byte
        // payload (chr(0x02) . bytes), NOT {"kind":"body"} JSON. This is the
        // production classification path — the old json-shaped payload masked
        // the fault where json_decode threw on the tag byte.
        $bodyPayload = RelayHttpRequestCodec::encodeBody('BODY-BYTES');
        $bodyFrame = new RelayFrame(RelayFrameType::HTTP_REQUEST, 7, $bodyPayload);
        $tunnel->sendToServer($bodyFrame);

        // Held, not sent — the frame is queued rather than dropped.
        $this->assertCount($sentBeforeBody, $sent, 'body frame must not be sent while the buffer is full');

        // Buffer drains — invoke the server onBufferDrain handler the tunnel armed.
        $this->assertIsCallable($this->serverWs->onBufferDrain);
        $full = false;
        ($this->serverWs->onBufferDrain)();

        // AC (a): the previously-failing body frame is delivered byte-exact.
        $this->assertCount($sentBeforeBody + 1, $sent);
        $decoded = $this->codec->decode($sent[count($sent) - 1]);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::HTTP_REQUEST, $decoded->type);
        $this->assertSame($bodyPayload, $decoded->payload);
    }

    /**
     * SERVER low-priority BODY path: if the server's buffer never drains, the
     * safety timeout closes the tunnel with the backpressure-timeout reason.
     */
    public function test_server_backpressure_timeout_closes_tunnel(): void
    {
        $tunnel = $this->activeTunnel();

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturn(true);
        $clientWs->method('pauseRecv');
        $clientWs->method('resumeRecv');
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');

        $full = false;
        $this->serverWs->method('send')->willReturnCallback(
            function () use (&$full): bool {
                return $full ? false : true;
            }
        );
        $this->serverWs->expects($this->once())->method('close');

        $tunnel->registerClient($client);
        $full = true;

        $bodyFrame = new RelayFrame(
            RelayFrameType::HTTP_REQUEST,
            7,
            RelayHttpRequestCodec::encodeBody('x'),
        );
        $tunnel->sendToServer($bodyFrame); // congested; drain never comes

        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with('session-456', 'backpressure_timeout');

        $method = new \ReflectionMethod($tunnel, 'handleServerBackpressureTimeout');
        $method->setAccessible(true);
        $method->invoke($tunnel);

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    // ---------------------------------------------------------------------
    // HB-1.2 fix-2 — FIFO ordering, overflow-close, multi-client, release.
    // ---------------------------------------------------------------------

    /**
     * Decode a single complete wire frame with a fresh decoder (so decoder
     * buffer state never leaks between assertions).
     */
    private function decodeFrame(string $wire): RelayFrame
    {
        $frame = (new FrameDecoder())->decode($wire);
        $this->assertInstanceOf(RelayFrame::class, $frame);
        return $frame;
    }

    /**
     * HIGH-PRIORITY server path (finding #1 regression guard): a control frame
     * generated while a control backlog exists must NOT be sent directly ahead
     * of the still-queued frames — even in the window where send() succeeds
     * again (buffer below the high-watermark) but onBufferDrain has not yet
     * fired. Frames must be delivered in strict enqueue order, and any body
     * frame queued behind them must stay after the control frames.
     */
    public function test_high_priority_frames_preserve_fifo_and_never_overtake_backlog(): void
    {
        $tunnel = $this->activeTunnel();

        $full = true;
        $sent = [];
        $this->serverWs->method('send')->willReturnCallback(
            function (string $data) use (&$full, &$sent): bool {
                if ($full) {
                    return false; // buffer full — Workerman would DROP this frame
                }
                $sent[] = $data;
                return true;
            }
        );
        $this->serverWs->method('pauseRecv');
        $this->serverWs->method('resumeRecv');

        // Buffer full: first control frame fails send and is queued.
        $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_CANCEL, 0, 'CTRL-1'));
        $this->assertSame([], $sent, 'first control frame must be queued, not sent');

        // Buffer drops below the high-watermark so send() would succeed again —
        // but onBufferDrain has NOT fired. A newly generated control frame must
        // queue BEHIND the backlog, never overtake it (the #1 reordering bug).
        $full = false;
        $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_CANCEL, 0, 'CTRL-2'));
        $this->assertSame([], $sent, 'second control frame must queue behind the backlog, not overtake it');

        // A body frame while a control backlog exists must queue behind the
        // control frames (control-first-then-body preserved).
        $full = true;
        $bodyPayload = RelayHttpRequestCodec::encodeBody('BODY-1');
        $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_REQUEST, 0, $bodyPayload));
        $this->assertSame([], $sent);

        // Drain: control queue FIFO first, then the body frame.
        $this->assertIsCallable($this->serverWs->onBufferDrain);
        $full = false;
        ($this->serverWs->onBufferDrain)();

        $this->assertCount(3, $sent);
        $this->assertSame('CTRL-1', $this->decodeFrame($sent[0])->payload);
        $this->assertSame('CTRL-2', $this->decodeFrame($sent[1])->payload);
        $this->assertSame($bodyPayload, $this->decodeFrame($sent[2])->payload);
        $this->assertSame(RelayFrameType::HTTP_REQUEST, $this->decodeFrame($sent[2])->type);
    }

    /**
     * CLIENT DATA path FIFO: two frames re-queued for one congested client must
     * be delivered in enqueue order once its buffer drains (a reordering
     * regression on the client path would flip them).
     */
    public function test_client_queue_delivers_multiple_frames_in_fifo_order(): void
    {
        $tunnel = $this->activeTunnel();
        $this->serverWs->method('send')->willReturn(true);
        $this->serverWs->method('pauseRecv');
        $this->serverWs->method('resumeRecv');

        $clientWs = $this->createMock(TcpConnection::class);
        $full = true;
        $delivered = [];
        $clientWs->method('send')->willReturnCallback(
            function (string $data) use (&$full, &$delivered): bool {
                if ($full) {
                    return false;
                }
                $delivered[] = $data;
                return true;
            }
        );
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client);

        // Two frames while congested — first fails send, second sees the backlog.
        $tunnel->sendToClient($client->channelId, new RelayFrame(RelayFrameType::DATA, $client->channelId, 'FIRST'));
        $tunnel->sendToClient($client->channelId, new RelayFrame(RelayFrameType::DATA, $client->channelId, 'SECOND'));
        $this->assertSame([], $delivered);

        $full = false;
        $this->assertIsCallable($clientWs->onBufferDrain);
        ($clientWs->onBufferDrain)();

        $this->assertCount(2, $delivered);
        $this->assertSame('FIRST', $this->decodeFrame($delivered[0])->payload);
        $this->assertSame('SECOND', $this->decodeFrame($delivered[1])->payload);
    }

    /**
     * removeClient must release a congested client's backpressure slot and
     * resume the server (the anti-stranding path): a congested client that
     * disconnects will never fire its drain handler, so if its slot is not
     * released the server stays paused forever.
     */
    public function test_remove_client_releases_backpressure_slot_and_resumes_server(): void
    {
        $tunnel = $this->activeTunnel();
        $this->serverWs->method('send')->willReturn(true); // CLIENT_CONNECT / DISCONNECT
        $this->serverWs->expects($this->once())->method('pauseRecv');
        $this->serverWs->expects($this->once())->method('resumeRecv');

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturn(false); // permanently full
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client);

        $tunnel->sendToClient($client->channelId, new RelayFrame(RelayFrameType::DATA, $client->channelId, 'x'));

        $count = new \ReflectionProperty($tunnel, 'serverBackpressureCount');
        $count->setAccessible(true);
        $this->assertSame(1, $count->getValue($tunnel), 'server paused while the client is congested');

        $tunnel->removeClient($client);

        $this->assertSame(0, $count->getValue($tunnel), 'removing the client released its slot');
    }

    /**
     * Two congested clients: the server must resume only after BOTH drain (the
     * count semantics). Draining one leaves the server paused (count 2→1);
     * draining the second resumes it (count 1→0).
     */
    public function test_two_congested_clients_resume_server_only_after_both_drain(): void
    {
        $tunnel = $this->activeTunnel();
        $this->serverWs->method('send')->willReturn(true);
        $this->serverWs->expects($this->once())->method('pauseRecv');
        $this->serverWs->expects($this->once())->method('resumeRecv');

        $full1 = true;
        $ws1 = $this->createMock(TcpConnection::class);
        $ws1->method('send')->willReturnCallback(function () use (&$full1): bool {
            return !$full1;
        });
        $c1 = new ClientConnection($ws1, 'server-123', 'client-1', $this->clientLogger, '');

        $full2 = true;
        $ws2 = $this->createMock(TcpConnection::class);
        $ws2->method('send')->willReturnCallback(function () use (&$full2): bool {
            return !$full2;
        });
        $c2 = new ClientConnection($ws2, 'server-123', 'client-2', $this->clientLogger, '');

        $tunnel->registerClient($c1);
        $tunnel->registerClient($c2);

        $tunnel->sendToClient($c1->channelId, new RelayFrame(RelayFrameType::DATA, $c1->channelId, 'a'));
        $tunnel->sendToClient($c2->channelId, new RelayFrame(RelayFrameType::DATA, $c2->channelId, 'b'));

        $count = new \ReflectionProperty($tunnel, 'serverBackpressureCount');
        $count->setAccessible(true);
        $this->assertSame(2, $count->getValue($tunnel));

        // First client drains — server must STAY paused.
        $full1 = false;
        $this->assertIsCallable($ws1->onBufferDrain);
        ($ws1->onBufferDrain)();
        $this->assertSame(1, $count->getValue($tunnel), 'server stays paused while a second client is congested');

        // Second client drains — server resumes.
        $full2 = false;
        $this->assertIsCallable($ws2->onBufferDrain);
        ($ws2->onBufferDrain)();
        $this->assertSame(0, $count->getValue($tunnel));
    }

    /**
     * CLIENT queue overflow: exceeding MAX_CLIENT_QUEUE closes the tunnel with
     * backpressure_overflow (a hard, visible failure) rather than dropping a
     * DATA frame (silent corruption).
     */
    public function test_client_queue_overflow_closes_tunnel(): void
    {
        $tunnel = $this->activeTunnel();
        $this->serverWs->method('send')->willReturn(true);
        $this->serverWs->method('pauseRecv');
        $this->serverWs->method('resumeRecv');

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturn(false); // always full — every frame re-queues
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client);

        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with('session-456', 'backpressure_overflow');

        $max = (int) (new \ReflectionClassConstant(Tunnel::class, 'MAX_CLIENT_QUEUE'))->getValue();
        for ($i = 0; $i <= $max; $i++) {
            $tunnel->sendToClient($client->channelId, new RelayFrame(RelayFrameType::DATA, $client->channelId, 'x'));
        }

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    /**
     * BODY queue overflow: exceeding MAX_BODY_QUEUE closes the tunnel with
     * backpressure_overflow rather than dropping a body frame.
     */
    public function test_body_queue_overflow_closes_tunnel(): void
    {
        $tunnel = $this->activeTunnel();
        $this->serverWs->method('send')->willReturn(false); // server always full
        $this->serverWs->method('pauseRecv');
        $this->serverWs->method('resumeRecv');

        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with('session-456', 'backpressure_overflow');

        $max = (int) (new \ReflectionClassConstant(Tunnel::class, 'MAX_BODY_QUEUE'))->getValue();
        $bodyPayload = RelayHttpRequestCodec::encodeBody('x');
        for ($i = 0; $i <= $max; $i++) {
            $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_REQUEST, 0, $bodyPayload));
        }

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    /**
     * HIGH-PRIORITY queue overflow (finding #2): exceeding MAX_HIGH_PRIORITY_QUEUE
     * closes the tunnel with backpressure_overflow rather than silently dropping
     * a control frame (a dropped CANCEL/CLIENT_DISCONNECT would strand server
     * state / leak an in-flight request).
     */
    public function test_high_priority_queue_overflow_closes_tunnel(): void
    {
        $tunnel = $this->activeTunnel();
        $this->serverWs->method('send')->willReturn(false); // always full
        $this->serverWs->method('pauseRecv');
        $this->serverWs->method('resumeRecv');

        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with('session-456', 'backpressure_overflow');

        $max = (int) (new \ReflectionClassConstant(Tunnel::class, 'MAX_HIGH_PRIORITY_QUEUE'))->getValue();
        for ($i = 0; $i <= $max; $i++) {
            $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_CANCEL, 0, 'c'));
        }

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    // ---------------------------------------------------------------------
    // FIX-3 — type-based classification (no json_decode) + within-request order.
    // ---------------------------------------------------------------------

    /**
     * Frame classification is a pure type switch: the REAL tag-byte
     * HEAD/BODY/END payloads produced by {@see RelayHttpRequestCodec} must never
     * be json_decoded (they are not valid JSON — a leading 0x01/0x02/0x03 tag
     * byte). isHighPriorityFrame must NOT throw on any of them and must classify
     * every HTTP_REQUEST / DATA stream frame as LOW priority, while the genuine
     * out-of-band control frames (HEARTBEAT/CANCEL/CLIENT_CONNECT/DISCONNECT)
     * are HIGH. This is the regression guard for the fault where json_decode
     * threw JsonException on the tag byte and faulted every chunked bodied relay.
     */
    public function test_is_high_priority_frame_classifies_by_type_without_decoding_payload(): void
    {
        $tunnel = $this->activeTunnel();
        $method = new \ReflectionMethod($tunnel, 'isHighPriorityFrame');
        $method->setAccessible(true);

        $head = new RelayHttpRequestHead('POST', '/api/v1/x', '', ['content-type' => 'application/json']);

        // Real wire-codec stream sub-frames: must classify LOW and NOT throw.
        $streamLow = [
            'HEAD' => new RelayFrame(RelayFrameType::HTTP_REQUEST, 1, RelayHttpRequestCodec::encodeHead($head)),
            'BODY' => new RelayFrame(RelayFrameType::HTTP_REQUEST, 1, RelayHttpRequestCodec::encodeBody("\x00\xFF binary\x01")),
            'END'  => new RelayFrame(RelayFrameType::HTTP_REQUEST, 1, RelayHttpRequestCodec::encodeEnd()),
            'DATA' => new RelayFrame(RelayFrameType::DATA, 1, "\x00\x01\x02 raw bytes"),
        ];
        foreach ($streamLow as $label => $frame) {
            $this->assertFalse(
                $method->invoke($tunnel, $frame),
                "$label stream frame must classify LOW (not high priority)",
            );
        }

        // Genuine out-of-band control frames: HIGH priority.
        $controlHigh = [
            RelayFrameType::HEARTBEAT,
            RelayFrameType::HTTP_CANCEL,
            RelayFrameType::CLIENT_CONNECT,
            RelayFrameType::CLIENT_DISCONNECT,
        ];
        foreach ($controlHigh as $type) {
            $this->assertTrue(
                $method->invoke($tunnel, new RelayFrame($type, 0, '')),
                $type->label() . ' control frame must classify HIGH priority',
            );
        }
    }

    /**
     * Within-request ordering (finding #2): for a single chunked request the
     * sub-frame sequence HEAD → BODY → BODY → END must be delivered to the
     * server in exactly that order under backpressure — END must NEVER overtake
     * a still-queued BODY chunk. Because #1 makes every HTTP_REQUEST sub-frame
     * the SAME (LOW) priority class, they share one FIFO body queue and order is
     * preserved; a residual END-jumps-BODY reorder would fail this.
     */
    public function test_chunked_request_head_body_end_deliver_in_order_under_backpressure(): void
    {
        $tunnel = $this->activeTunnel();

        $full = true; // buffer full from the start: every sub-frame queues
        $sent = [];
        $this->serverWs->method('send')->willReturnCallback(
            function (string $data) use (&$full, &$sent): bool {
                if ($full) {
                    return false;
                }
                $sent[] = $data;
                return true;
            }
        );
        $this->serverWs->method('pauseRecv');
        $this->serverWs->method('resumeRecv');

        $head = new RelayHttpRequestHead('POST', '/api/v1/watched', '', ['content-type' => 'application/json']);
        $headPayload = RelayHttpRequestCodec::encodeHead($head->withBodySize(20));
        $body1 = RelayHttpRequestCodec::encodeBody('AAAAAAAAAA');
        $body2 = RelayHttpRequestCodec::encodeBody('BBBBBBBBBB');
        $endPayload = RelayHttpRequestCodec::encodeEnd();

        // Producer order (mirrors RelayProxyManager chunked path): HEAD, BODY×2, END.
        $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_REQUEST, 42, $headPayload));
        $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_REQUEST, 42, $body1));
        $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_REQUEST, 42, $body2));
        $tunnel->sendToServer(new RelayFrame(RelayFrameType::HTTP_REQUEST, 42, $endPayload));

        // Nothing on the wire yet — all four queued behind the full buffer.
        $this->assertSame([], $sent, 'all sub-frames must queue, none dropped, while congested');

        // Buffer drains — the tunnel flushes the body FIFO.
        $this->assertIsCallable($this->serverWs->onBufferDrain);
        $full = false;
        ($this->serverWs->onBufferDrain)();

        $this->assertCount(4, $sent);
        $this->assertSame($headPayload, $this->decodeFrame($sent[0])->payload, 'HEAD first');
        $this->assertSame($body1, $this->decodeFrame($sent[1])->payload, 'BODY-1 second');
        $this->assertSame($body2, $this->decodeFrame($sent[2])->payload, 'BODY-2 third');
        $this->assertSame($endPayload, $this->decodeFrame($sent[3])->payload, 'END last — never overtakes BODY');
    }

    /**
     * AC guard (H-R7 / HB-2.3): a server that grows the decode buffer past the
     * 128 KB cap without ever completing a frame must NOT leak an uncaught
     * exception out of the Workerman message callback. Instead the tunnel closes
     * cleanly on the SAME path an invalid frame uses — clients are notified and
     * closed, the DB session is closed, and the server WS is torn down.
     *
     * This drives a REAL Tunnel via onServerMessage (as RelayWorker::onMessage
     * does) and asserts the tunnel state, not merely that decode() throws.
     */
    public function test_tunnel_closes_on_frame_buffer_overflow(): void
    {
        $sessionId = 'session-overflow';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $this->serverWs->method('send');

        // A client is attached: on overflow it must be notified (DISCONNECTED)
        // and closed, proving the clean close path ran (not an escaping fatal).
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->expects($this->once())->method('send');
        $clientWs->expects($this->once())->method('close');
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->registerClient($client);

        // The DB session must be closed with the overflow reason.
        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with($sessionId, 'frame_buffer_overflow');

        // The server WS must be torn down.
        $this->serverWs->expects($this->once())->method('close');

        // Feed a single binary WS message larger than MAX_BUFFER_SIZE (131072).
        // The accumulation guard trips on the very first append (before any frame
        // parsing), which is exactly the oversized-length attack from H-R7.
        $oversized = str_repeat("\x00", 140000);

        // Must NOT throw out of onServerMessage — the overflow is caught inside.
        $tunnel->onServerMessage($oversized);

        $this->assertSame(
            Tunnel::STATUS_CLOSED,
            $tunnel->status,
            'tunnel must close on frame-buffer overflow instead of leaking the exception',
        );
        $this->assertCount(0, $tunnel->clientConnections, 'all clients detached on overflow close');
    }
}
