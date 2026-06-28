<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Shared\Hub\ServerInfoDto;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function hash;

/**
 * Unit tests for {@see ClientRelayWorker} — the client-facing relay path.
 *
 * Covers per-user relay-token accept/reject (step S2b), path parsing, token
 * extraction from the upgrade request (header / subprotocol only — the legacy
 * `?token=` query path was removed), ownership enforcement at mount, binding
 * to an existing tunnel, the no-tunnel-available close, and a DATA frame
 * round-trip through the router to the server tunnel.
 *
 * The worker no longer accepts the server's long-lived enrollment JWT as a
 * client credential. It validates a short-lived, revocable, per-user hub relay
 * token via {@see ClientRelayTokenService} and re-confirms the bound user owns
 * the target server via {@see ServerInfoHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 *
 * @covers \Phlix\Hub\Relay\ClientRelayWorker
 */
final class ClientRelayWorkerTest extends TestCase
{
    /** Server id used by the "owned, online" happy-path fixtures. */
    private const OWNER_USER_ID = 'user-owner-1';

    private string $tmpDir;
    private RelaySessionManager $sessionManager;
    private RelayWireCodecInterface $codec;
    private StructuredLogger $logger;
    private TunnelManager $tunnelManager;
    private ClientMountController $controller;

    /**
     * Map of plaintext relay token => bound {user_id, server_id} the fake
     * token service treats as ACTIVE (not expired, not revoked).
     *
     * @var array<string, array{user_id: string, server_id: string}>
     */
    private array $activeTokens = [];

    /**
     * Map of server_id => owning user_id used by the fake ServerInfoHandler.
     *
     * @var array<string, string>
     */
    private array $serverOwners = [];

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

    // ---- Token extraction ------------------------------------------------

    public function testExtractClientTokenFromAuthorizationHeader(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1', ['Authorization' => 'Bearer relay-tok-123']);
        self::assertSame('relay-tok-123', ClientRelayWorker::extractClientToken($request));
    }

    public function testExtractClientTokenFromSecWebSocketProtocol(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1', ['Sec-WebSocket-Protocol' => 'bearer, relay-tok-123']);
        self::assertSame('relay-tok-123', ClientRelayWorker::extractClientToken($request));
    }

    public function testExtractClientTokenIgnoresQueryParam(): void
    {
        // The legacy `?token=` query path was removed in S2b so secrets never
        // land in access logs — it must NOT be honoured even when present.
        $request = $this->makeUpgradeRequest('/client/s1?token=query.tok');
        self::assertNull(ClientRelayWorker::extractClientToken($request));
    }

    public function testExtractClientTokenReturnsNullWhenAbsent(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1');
        self::assertNull(ClientRelayWorker::extractClientToken($request));
    }

    // ---- Token validation + ownership -----------------------------------

    public function testValidateClientAuthAcceptsValidOwnedToken(): void
    {
        $serverId = 'server-uuid-aaa';
        $token = 'valid-relay-token';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertTrue($worker->validateClientAuth($token, $serverId));
    }

    public function testValidateClientAuthRejectsServerIdMismatch(): void
    {
        // Token is minted for server A but presented at server B's mount path.
        $token = 'token-for-server-a';
        $this->grantToken($token, self::OWNER_USER_ID, 'server-a');
        $this->setServerOwner('server-a', self::OWNER_USER_ID);
        $this->setServerOwner('server-b', self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertFalse($worker->validateClientAuth($token, 'server-b'));
    }

    public function testValidateClientAuthRejectsNonOwnedServer(): void
    {
        // Token is valid and scoped to the server, but the server is now owned
        // by someone else — mount must be rejected.
        $serverId = 'server-uuid-shared';
        $token = 'valid-but-not-owner';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, 'a-different-owner');

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertFalse($worker->validateClientAuth($token, $serverId));
    }

    public function testValidateClientAuthRejectsUnknownOrRevokedToken(): void
    {
        // No token granted => the fake service returns null (covers unknown,
        // expired, and revoked — all return null from validate()).
        $serverId = 'server-uuid-aaa';
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertFalse($worker->validateClientAuth('never-minted', $serverId));
        self::assertFalse($worker->validateClientAuth('', $serverId));
    }

    public function testValidateClientAuthRejectsRevokedToken(): void
    {
        // Mint then revoke the token before the mount: validate() must fail.
        $serverId = 'server-uuid-rev';
        $token = 'soon-to-be-revoked';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());
        self::assertTrue($worker->validateClientAuth($token, $serverId));

        // Revoke: drop it from the active set.
        unset($this->activeTokens[hash('sha256', $token)]);

        self::assertFalse($worker->validateClientAuth($token, $serverId));
    }

    public function testValidateClientAuthRejectsEnrollmentJwt(): void
    {
        // A JWT-looking credential is NOT a relay token — it is unknown to the
        // token table and must be rejected (closes the S2 hole).
        $serverId = 'server-uuid-aaa';
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertFalse($worker->validateClientAuth('header.payload.signature', $serverId));
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

    public function testOnWebSocketConnectRejectsMissingToken(): void
    {
        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/server-uuid-aaa');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_UNAUTHORIZED, true);

        $worker->onWebSocketConnect($connection, $request);
    }

    public function testOnWebSocketConnectRejectsEnrollmentJwtOnly(): void
    {
        // Presenting only the server enrollment JWT (no relay token) is the
        // exact attack S2 closes: it must be rejected.
        $serverId = 'server-uuid-aaa';
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer header.payload.signature',
        ]);

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_UNAUTHORIZED, true);

        $worker->onWebSocketConnect($connection, $request);
    }

    public function testOnWebSocketConnectRejectsNonOwnedServer(): void
    {
        $serverId = 'server-uuid-foreign';
        $token = 'valid-token-foreign';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, 'a-different-owner');

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
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
        $token = 'valid-token-offline';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

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
        $token = 'valid-token-online';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

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
        $token = 'valid-token-relay';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

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
        $token = 'valid-token-close';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

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
     * Mark a plaintext token as ACTIVE and bound to (userId, serverId) in the
     * fake token service.
     */
    private function grantToken(string $token, string $userId, string $serverId): void
    {
        $this->activeTokens[hash('sha256', $token)] = [
            'user_id' => $userId,
            'server_id' => $serverId,
        ];
    }

    /**
     * Record that $serverId is owned by $userId for the fake ServerInfoHandler.
     */
    private function setServerOwner(string $serverId, string $userId): void
    {
        $this->serverOwners[$serverId] = $userId;
    }

    /**
     * Build a real {@see ClientRelayTokenService} over a mock Connection whose
     * `query()` resolves the active-token lookup against {@see $activeTokens}.
     *
     * The service's validate() SQL filters by `token_hash` (a colon-free named
     * param) and expects a `[{user_id, server_id}]` row shape.
     */
    private function buildTokenService(): ClientRelayTokenService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'SELECT')) {
                    return [];
                }
                $hash = $params['token_hash'] ?? null;
                if (is_string($hash) && isset($this->activeTokens[$hash])) {
                    return [$this->activeTokens[$hash]];
                }
                return [];
            },
        );

        return new ClientRelayTokenService($db);
    }

    /**
     * Build a {@see ServerInfoHandler} test double whose getServerInfo()
     * resolves ownership from {@see $serverOwners}.
     */
    private function buildServerInfoHandler(): ServerInfoHandler
    {
        $handler = $this->createMock(ServerInfoHandler::class);
        $handler->method('getServerInfo')->willReturnCallback(
            function (string $serverId): ?ServerInfoDto {
                if (!isset($this->serverOwners[$serverId])) {
                    return null;
                }

                return new ServerInfoDto(
                    serverId: $serverId,
                    userId: $this->serverOwners[$serverId],
                    serverName: 'Test Server',
                    version: '1.0.0',
                    lastSeenAt: null,
                    status: 'online',
                    hostnameCandidates: [],
                    relayActive: true,
                    libraryCount: null,
                );
            },
        );

        return $handler;
    }

    /**
     * Build a minimal PSR-11 container exposing the relay services the
     * worker and controller resolve.
     */
    private function buildContainer(): ContainerInterface
    {
        $tokenService = $this->buildTokenService();
        $serverInfo = $this->buildServerInfoHandler();
        $tunnelManager = $this->tunnelManager;
        $controllerFactory = fn (): ClientMountController => $this->controller;

        return new class ($tokenService, $serverInfo, $tunnelManager, $controllerFactory) implements ContainerInterface {
            /** @param callable():ClientMountController $controllerFactory */
            public function __construct(
                private readonly ClientRelayTokenService $tokenService,
                private readonly ServerInfoHandler $serverInfo,
                private readonly TunnelManager $tunnelManager,
                private $controllerFactory,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ClientRelayTokenService::class => $this->tokenService,
                    ServerInfoHandler::class => $this->serverInfo,
                    TunnelManager::class, TunnelManagerInterface::class => $this->tunnelManager,
                    ClientMountController::class => ($this->controllerFactory)(),
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    ClientRelayTokenService::class,
                    ServerInfoHandler::class,
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
