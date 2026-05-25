<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * Unit tests for {@see ClientRelayWorker} — the client-facing relay path.
 *
 * Covers JWT accept/reject, path parsing, JWT extraction from the upgrade
 * request, binding to an existing tunnel, the no-tunnel-available close, and
 * a DATA frame round-trip through the router to the server tunnel.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 * @since 0.5.0
 *
 * @covers \Phlix\Hub\Relay\ClientRelayWorker
 */
final class ClientRelayWorkerTest extends TestCase
{
    private string $tmpDir;
    private Ed25519KeyManager $keyManager;
    private EnrollmentJwtService $jwtService;
    private RelaySessionManager $sessionManager;
    private RelayWireCodecInterface $codec;
    private StructuredLogger $logger;
    private TunnelManager $tunnelManager;
    private ClientMountController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-client-relay-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        // The production ClientMountController resolves its logger through the
        // static LoggerFactory. Point it at a memory-stream config so tests do
        // not write to real log files or emit output. Reset in tearDown().
        $loggerConfig = $this->tmpDir . '/logger.php';
        file_put_contents(
            $loggerConfig,
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($loggerConfig);

        $this->keyManager = new Ed25519KeyManager($this->tmpDir . '/signing-key.pem');
        $this->jwtService = new EnrollmentJwtService($this->keyManager, 'https://hub.example.com');

        $this->logger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $this->codec = new FrameDecoder();
        $this->tunnelManager = new TunnelManager($this->sessionManager, $this->codec, $this->logger);
        $this->controller = new ClientMountController($this->buildContainer());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        LoggerFactory::reset();

        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ---- Path parsing ----------------------------------------------------

    public function testParseServerIdExtractsSegment(): void
    {
        self::assertSame('abc-123', ClientRelayWorker::parseServerId('/client/abc-123'));
        self::assertSame('abc-123', ClientRelayWorker::parseServerId('/client/abc-123/'));
        self::assertSame('abc-123', ClientRelayWorker::parseServerId('/client/abc-123?token=x'));
    }

    public function testParseServerIdUrlDecodes(): void
    {
        self::assertSame('a b', ClientRelayWorker::parseServerId('/client/a%20b'));
    }

    public function testParseServerIdRejectsNonClientPaths(): void
    {
        self::assertNull(ClientRelayWorker::parseServerId('/relay/abc-123'));
        self::assertNull(ClientRelayWorker::parseServerId('/client/'));
        self::assertNull(ClientRelayWorker::parseServerId('/client'));
        self::assertNull(ClientRelayWorker::parseServerId('/client/a/b'));
    }

    // ---- JWT extraction --------------------------------------------------

    public function testExtractJwtFromAuthorizationHeader(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1', ['Authorization' => 'Bearer my.jwt.token']);
        self::assertSame('my.jwt.token', ClientRelayWorker::extractJwt($request));
    }

    public function testExtractJwtFromSecWebSocketProtocol(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1', ['Sec-WebSocket-Protocol' => 'bearer, my.jwt.token']);
        self::assertSame('my.jwt.token', ClientRelayWorker::extractJwt($request));
    }

    public function testExtractJwtFromQueryParam(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1?token=query.jwt.token');
        self::assertSame('query.jwt.token', ClientRelayWorker::extractJwt($request));
    }

    public function testExtractJwtReturnsNullWhenAbsent(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1');
        self::assertNull(ClientRelayWorker::extractJwt($request));
    }

    // ---- JWT validation --------------------------------------------------

    public function testValidateClientAuthAcceptsValidToken(): void
    {
        $serverId = 'server-uuid-aaa';
        $token = $this->jwtService->createEnrollmentJwt($serverId);

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertTrue($worker->validateClientAuth($token, $serverId));
    }

    public function testValidateClientAuthRejectsServerIdMismatch(): void
    {
        $token = $this->jwtService->createEnrollmentJwt('server-uuid-aaa');
        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertFalse($worker->validateClientAuth($token, 'a-different-server'));
    }

    public function testValidateClientAuthRejectsGarbageToken(): void
    {
        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertFalse($worker->validateClientAuth('not.a.jwt', 'server-uuid-aaa'));
        self::assertFalse($worker->validateClientAuth('garbage', 'server-uuid-aaa'));
    }

    public function testValidateClientAuthRejectsTamperedToken(): void
    {
        $token = $this->jwtService->createEnrollmentJwt('server-uuid-aaa');

        // Re-sign-proof tampering: replace the payload segment with a forged
        // one (same server_id) but keep the original signature. The Ed25519
        // signature no longer covers the new message, so validation must fail.
        [$header, , $signature] = explode('.', $token);
        $forgedPayload = rtrim(strtr(base64_encode(
            json_encode([
                'iss' => 'phlix-hub',
                'sub' => 'server-uuid-aaa',
                'aud' => 'server',
                'exp' => time() + 3600,
                'iat' => time(),
                'server_id' => 'server-uuid-aaa',
            ], JSON_THROW_ON_ERROR),
        ), '+/', '-_'), '=');
        $tampered = "{$header}.{$forgedPayload}.{$signature}";

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertFalse($worker->validateClientAuth($tampered, 'server-uuid-aaa'));
    }

    // ---- WS connect: rejection paths ------------------------------------

    public function testOnWebSocketConnectRejectsMissingServerId(): void
    {
        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/relay/not-a-client-path');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('close');

        $worker->onWebSocketConnect($connection, $request);
    }

    public function testOnWebSocketConnectRejectsMissingJwt(): void
    {
        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/server-uuid-aaa');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_UNAUTHORIZED, true);

        $worker->onWebSocketConnect($connection, $request);
    }

    public function testOnWebSocketConnectRejectsInvalidJwt(): void
    {
        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/server-uuid-aaa', [
            'Authorization' => 'Bearer not.a.valid.jwt',
        ]);

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_UNAUTHORIZED, true);

        $worker->onWebSocketConnect($connection, $request);
    }

    public function testOnWebSocketConnectClosesWhenNoTunnelAvailable(): void
    {
        $serverId = 'server-uuid-offline';
        $token = $this->jwtService->createEnrollmentJwt($serverId);

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        // No tunnel registered for this server_id → controller closes the conn.
        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())
            ->method('close')
            ->with('server_offline');

        $worker->onWebSocketConnect($connection, $request);
    }

    // ---- WS connect: success / binding ----------------------------------

    public function testOnWebSocketConnectBindsToActiveTunnel(): void
    {
        $serverId = 'server-uuid-online';
        $token = $this->jwtService->createEnrollmentJwt($serverId);

        // Bring up an ACTIVE tunnel for this server.
        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $clientWs = $this->createMock(TcpConnection::class);
        // The client connection should NOT be closed on a successful bind.
        $clientWs->expects($this->never())->method('close');

        $worker->onWebSocketConnect($clientWs, $request);

        // A client connection is now attached to the tunnel.
        self::assertCount(1, $tunnel->clientConnections);
    }

    // ---- Frame round-trip through the router ----------------------------

    public function testClientDataFrameIsRelayedToServerTunnel(): void
    {
        $serverId = 'server-uuid-relay';
        $token = $this->jwtService->createEnrollmentJwt($serverId);

        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Capture everything written to the server side. registerClient emits
        // a CLIENT_CONNECT frame first; the DATA frame we send should follow.
        $sentToServer = [];
        $serverWs->method('send')->willReturnCallback(
            function (string $data) use (&$sentToServer): bool {
                $sentToServer[] = $data;
                return true;
            },
        );

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $clientWs = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect($clientWs, $request);

        // Client sends a DATA frame; the worker routes it to onClientMessage,
        // which forwards it through the tunnel to the server.
        $encoder = new FrameEncoder();
        $dataFrame = $encoder->encode(RelayFrameType::DATA, 7, 'hello-server');

        $worker->onMessage($clientWs, $dataFrame);

        // Decode each server-bound payload and confirm a DATA frame with our
        // payload reached the server.
        $sawData = false;
        foreach ($sentToServer as $bytes) {
            $decoded = (new FrameDecoder())->decode($bytes);
            if ($decoded instanceof RelayFrame && $decoded->type === RelayFrameType::DATA) {
                self::assertSame('hello-server', $decoded->payload);
                $sawData = true;
            }
        }

        self::assertTrue($sawData, 'Expected a DATA frame to be relayed to the server tunnel');
    }

    public function testOnCloseDetachesClientAndNotifiesServer(): void
    {
        $serverId = 'server-uuid-close';
        $token = $this->jwtService->createEnrollmentJwt($serverId);

        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $serverWs->method('send')->willReturn(true);

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $clientWs = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect($clientWs, $request);
        self::assertCount(1, $tunnel->clientConnections);

        // Client disconnects — worker dispatches to onClientClose, which
        // detaches the client from the tunnel (sending CLIENT_DISCONNECT).
        $worker->onClose($clientWs);

        self::assertCount(0, $tunnel->clientConnections);
    }

    // ---- Helpers ---------------------------------------------------------

    /**
     * Build a minimal PSR-11 container exposing the relay services the
     * worker and controller resolve.
     */
    private function buildContainer(): ContainerInterface
    {
        $jwtService = $this->jwtService;
        $tunnelManager = $this->tunnelManager;
        $controllerFactory = fn (): ClientMountController => $this->controller;

        return new class ($jwtService, $tunnelManager, $controllerFactory) implements ContainerInterface {
            /** @param callable():ClientMountController $controllerFactory */
            public function __construct(
                private readonly EnrollmentJwtService $jwtService,
                private readonly TunnelManager $tunnelManager,
                private $controllerFactory,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    EnrollmentJwtService::class => $this->jwtService,
                    TunnelManager::class, TunnelManagerInterface::class => $this->tunnelManager,
                    ClientMountController::class => ($this->controllerFactory)(),
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    EnrollmentJwtService::class,
                    TunnelManager::class,
                    TunnelManagerInterface::class,
                    ClientMountController::class,
                ], true);
            }
        };
    }

    /**
     * Build a real Workerman WS-upgrade Request from a raw HTTP buffer.
     *
     * @param string                $path    Request path (with optional query).
     * @param array<string, string> $headers Extra headers to inject.
     */
    private function makeUpgradeRequest(string $path, array $headers = []): WorkermanRequest
    {
        $allHeaders = array_merge([
            'Host' => 'hub.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ], $headers);

        $lines = ["GET {$path} HTTP/1.1"];
        foreach ($allHeaders as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = implode("\r\n", $lines) . "\r\n\r\n";

        return new WorkermanRequest($raw);
    }
}
