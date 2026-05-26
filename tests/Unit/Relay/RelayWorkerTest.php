<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

use function chr;
use function in_array;
use function json_decode;
use function json_encode;
use function pack;
use function strlen;

/**
 * Unit tests for {@see RelayWorker} — the server-facing relay tunnel path.
 *
 * These tests pin two things that were previously unverified:
 *
 *   1. {@see RelayWorker::start()} keeps the Workerman `Websocket` application
 *      protocol bound to the worker. Nulling the protocol would disable the
 *      HTTP upgrade handshake and frame deframing (see vendor
 *      {@see \Workerman\Connection\TcpConnection::baseRead()} which only calls
 *      `protocol::input()`/`decode()` when `$this->protocol !== null`).
 *
 *   2. The worker consumes the EXACT wire bytes the media server emits: the
 *      JSON HELLO text, then binary relay frames in the shared layout
 *      `[4B seq][1B type][2B len][payload]`. This is a cross-codec conformance
 *      assertion — the server-side bytes are reconstructed independently here
 *      (not via the hub's own encoder) and fed through onMessage → Tunnel.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 *
 * @covers \Phlix\Hub\Relay\RelayWorker
 */
final class RelayWorkerTest extends TestCase
{
    private RelaySessionManager $sessionManager;
    private RelayWireCodecInterface $codec;
    private StructuredLogger $logger;
    private TunnelManager $tunnelManager;

    protected function setUp(): void
    {
        parent::setUp();

        // RelayWorker resolves nothing through LoggerFactory directly, but the
        // Tunnel it creates does not either (it takes an injected logger).
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $this->codec = new FrameDecoder();
        $this->tunnelManager = new TunnelManager($this->sessionManager, $this->codec, $this->logger);

        RelayWorker::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RelayWorker::reset();
        LoggerFactory::reset();
    }

    // ---- TASK 1: protocol must stay bound ---------------------------------

    public function testStartDoesNotNullWebsocketProtocol(): void
    {
        $worker = (new RelayWorker($this->buildContainer(), 0))->start();

        // Workerman resolves the application protocol class lazily in
        // parseSocketAddress() (called from listen() during runAll()), so
        // $worker->protocol is still null right after start(). The defect we
        // guard against is start() explicitly assigning `protocol = null` AND
        // (historically) relying on raw onConnect — both of which fight the WS
        // handshake/deframing. Resolve the protocol the same way Workerman does
        // at listen time and assert it lands on the Websocket class.
        $resolve = new \ReflectionMethod(Worker::class, 'parseSocketAddress');
        $resolve->invoke($worker);

        self::assertSame(
            'Workerman\\Protocols\\Websocket',
            $worker->protocol,
            'RelayWorker must resolve to the Websocket protocol so the WS handshake + deframing run',
        );
    }

    public function testStartWiresWebSocketConnectAndMessageHandlers(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $worker = $relay->start();

        self::assertSame([$relay, 'onWebSocketConnect'], $worker->onWebSocketConnect);
        self::assertSame([$relay, 'onMessage'], $worker->onMessage);
        self::assertSame([$relay, 'onClose'], $worker->onClose);
        self::assertSame('phlix-hub-relay-ws', $worker->name);
    }

    // ---- HELLO handshake (server's exact text bytes) ----------------------

    public function testFirstMessageHelloCreatesTunnelAndAcksWithSharedLayout(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        // Exactly the bytes the server's RelayMessageFramer::encodeHello emits.
        $helloJson = (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt.value.here',
            'server_id' => 'server-uuid-aaa',
        ], JSON_THROW_ON_ERROR);

        $serverWs = $this->createMock(TcpConnection::class);

        $ackBytes = null;
        $serverWs->method('send')->willReturnCallback(
            function (string $data) use (&$ackBytes): bool {
                $ackBytes = $data;
                return true;
            },
        );

        $relay->onMessage($serverWs, $helloJson);

        // A tunnel now exists for the server and is active.
        $tunnel = $this->tunnelManager->getTunnelForServer('server-uuid-aaa');
        self::assertInstanceOf(Tunnel::class, $tunnel);
        self::assertSame(Tunnel::STATUS_ACTIVE, $tunnel->status);
        self::assertSame(1, RelayWorker::getActiveConnectionCount());

        // The HELLO_ACK the server will parse is JSON text (not a binary frame).
        self::assertIsString($ackBytes);
        /** @var array<string, mixed> $ack */
        $ack = json_decode($ackBytes, true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('hello_ack', $ack['type'] ?? null);
        self::assertArrayHasKey('relay_session_id', $ack);
        self::assertArrayHasKey('tunnel_id', $ack);
    }

    public function testMalformedHelloClosesConnection(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->expects($this->once())->method('close')->with('invalid_hello');

        $relay->onMessage($serverWs, "\x00\x01not-json");

        self::assertSame(0, RelayWorker::getActiveConnectionCount());
    }

    public function testHelloMissingServerIdClosesConnection(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->expects($this->once())->method('close')->with('missing_server_id');

        $relay->onMessage($serverWs, (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt',
        ], JSON_THROW_ON_ERROR));
    }

    // ---- Binary frames: server's EXACT wire bytes -------------------------

    public function testServerBinaryHeartbeatFrameTouchesTunnel(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        // HELLO first to bring the tunnel ACTIVE.
        $relay->onMessage($serverWs, $this->encodeServerHello('server-bin'));
        $tunnel = $this->tunnelManager->getTunnelForServer('server-bin');
        self::assertInstanceOf(Tunnel::class, $tunnel);
        $tunnel->lastFrameAt = 0;

        // The server emits a HEARTBEAT frame with the shared byte layout.
        $heartbeat = $this->encodeServerFrame(RelayFrameType::HEARTBEAT, 1, '');
        $relay->onMessage($serverWs, $heartbeat);

        // The tunnel consumed it (lastFrameAt was touched away from 0).
        self::assertGreaterThan(0, $tunnel->lastFrameAt);
    }

    public function testServerBinaryDataFrameRoutesToOwningChannel(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $relay->onMessage($serverWs, $this->encodeServerHello('server-data'));
        $tunnel = $this->tunnelManager->getTunnelForServer('server-data');
        self::assertInstanceOf(Tunnel::class, $tunnel);

        // Register a real ClientConnection (gets channel id 1) whose underlying
        // WS captures the raw bytes the tunnel routes to it (sendToClient →
        // ClientConnection::sendRaw → clientWs->send).
        $received = '';
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturnCallback(
            function (mixed $data) use (&$received): bool {
                $received .= (string) $data;
                return true;
            },
        );
        $client = new ClientConnection(
            $clientWs,
            'server-data',
            'client-1',
            $this->logger,
            'session-1',
        );
        $tunnel->registerClient($client);
        self::assertSame(1, $client->channelId);

        // The server emits a DATA frame on channel 1 (the client's channel).
        $payload = "HTTP/1.1 200 OK\r\nContent-Type: application/vnd.apple.mpegurl\r\n\r\n#EXTM3U";
        $relay->onMessage($serverWs, $this->encodeServerFrame(RelayFrameType::DATA, $client->channelId, $payload));

        // The tunnel re-encoded and routed the DATA frame to channel 1. Decode
        // what the client received and confirm the payload + channel survived.
        $decoded = (new FrameDecoder())->decode($received);
        self::assertNotNull($decoded);
        self::assertSame(RelayFrameType::DATA, $decoded->type);
        self::assertSame($payload, $decoded->payload);
        self::assertSame(1, $decoded->channelId());
    }

    public function testServerBinaryDataFrameForUnknownChannelIsDropped(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $relay->onMessage($serverWs, $this->encodeServerHello('server-drop'));
        $tunnel = $this->tunnelManager->getTunnelForServer('server-drop');
        self::assertInstanceOf(Tunnel::class, $tunnel);

        // Register a client on channel 1.
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->expects($this->never())->method('send');
        $client = new ClientConnection($clientWs, 'server-drop', 'client-1', $this->logger, 'session-1');
        $tunnel->registerClient($client);

        // The server emits DATA on a channel that does not exist (42) — dropped.
        $relay->onMessage($serverWs, $this->encodeServerFrame(RelayFrameType::DATA, 42, 'orphan-bytes'));

        // No bytes were delivered to any client.
        self::assertSame(0, $tunnel->getBytesIn());
    }

    public function testTwoClientsOnDistinctChannelsDoNotCrossTalk(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $relay->onMessage($serverWs, $this->encodeServerHello('server-mux'));
        $tunnel = $this->tunnelManager->getTunnelForServer('server-mux');
        self::assertInstanceOf(Tunnel::class, $tunnel);

        // Two clients register and get channels 1 and 2.
        $recv1 = '';
        $clientWs1 = $this->createMock(TcpConnection::class);
        $clientWs1->method('send')->willReturnCallback(function (mixed $d) use (&$recv1): bool {
            $recv1 .= (string) $d;
            return true;
        });
        $recv2 = '';
        $clientWs2 = $this->createMock(TcpConnection::class);
        $clientWs2->method('send')->willReturnCallback(function (mixed $d) use (&$recv2): bool {
            $recv2 .= (string) $d;
            return true;
        });

        $client1 = new ClientConnection($clientWs1, 'server-mux', 'client-1', $this->logger, 's1');
        $client2 = new ClientConnection($clientWs2, 'server-mux', 'client-2', $this->logger, 's2');
        $tunnel->registerClient($client1);
        $tunnel->registerClient($client2);
        self::assertSame(1, $client1->channelId);
        self::assertSame(2, $client2->channelId);

        // Server DATA for channel 1 reaches only client 1; channel 2 only client 2.
        $relay->onMessage($serverWs, $this->encodeServerFrame(RelayFrameType::DATA, 1, 'to-one'));
        $relay->onMessage($serverWs, $this->encodeServerFrame(RelayFrameType::DATA, 2, 'to-two'));

        $d1 = (new FrameDecoder())->decode($recv1);
        $d2 = (new FrameDecoder())->decode($recv2);
        self::assertNotNull($d1);
        self::assertNotNull($d2);
        self::assertSame('to-one', $d1->payload);
        self::assertSame(1, $d1->channelId());
        self::assertSame('to-two', $d2->payload);
        self::assertSame(2, $d2->channelId());
    }

    // ---- Close lifecycle --------------------------------------------------

    public function testOnCloseTearsDownTunnelMapping(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $relay->onMessage($serverWs, $this->encodeServerHello('server-close'));
        self::assertSame(1, RelayWorker::getActiveConnectionCount());

        $relay->onClose($serverWs);
        self::assertSame(0, RelayWorker::getActiveConnectionCount());
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * Build the JSON HELLO text exactly as the media server emits it.
     */
    private function encodeServerHello(string $serverId): string
    {
        return (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt.value.here',
            'server_id' => $serverId,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Build a binary relay frame independently of the hub's own encoder, in the
     * shared `[4B seq big-endian][1B type][2B len big-endian][payload]` layout.
     * This is what the server's RelayMessageFramer::encode() puts on the wire.
     */
    private function encodeServerFrame(RelayFrameType $type, int $seq, string $payload): string
    {
        return pack('N', $seq)
            . chr($type->value)
            . pack('n', strlen($payload))
            . $payload;
    }

    /**
     * Minimal PSR-11 container exposing the TunnelManager the worker resolves.
     */
    private function buildContainer(): ContainerInterface
    {
        $tunnelManager = $this->tunnelManager;

        return new class ($tunnelManager) implements ContainerInterface {
            public function __construct(private readonly TunnelManager $tunnelManager)
            {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    TunnelManager::class, TunnelManagerInterface::class => $this->tunnelManager,
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [TunnelManager::class, TunnelManagerInterface::class], true);
            }
        };
    }
}
