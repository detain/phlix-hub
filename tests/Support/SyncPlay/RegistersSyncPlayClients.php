<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\SyncPlay;

use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function end;
use function hash;
use function implode;
use function in_array;
use function is_string;
use function str_contains;

/**
 * Register SyncPlay clients in {@see SyncPlayRelayWorker}'s process-global map
 * **exactly as production registers them** (S93 test support).
 *
 * ## Why the long way round
 *
 * The map is a `private static`, and the tempting shortcut is to write into it by
 * reflection. That would make every delivery assertion in the suite a statement
 * about a map shape the TEST built. `onWebSocketConnect()` is the only thing that
 * puts a client in there in production, and it does so only after a relay token
 * on a sanctioned carrier validates AND the token's user is re-confirmed as the
 * server's current owner. Registering through it means the fixtures inherit all
 * of that — including the fact that a connection which fails authentication is
 * never registered at all, which is itself one of the guards under test.
 *
 * ⚠ Consequence worth knowing before writing a fixture: because ownership is
 * re-confirmed, **two different users cannot both be registered against the same
 * `server_id`**. A "different user, same server" pair of live sockets is not
 * constructible here, and a suite that needs to isolate the user half of a match
 * must do it by addressing a different user rather than by registering one.
 *
 * The token carrier is `Authorization: Bearer` on a REAL {@see WorkermanRequest}
 * parsed from raw upgrade text — S237 removed the query-string carrier, and a
 * fixture that set `$_GET` by hand is exactly how the pre-S237 suite stayed green
 * against a surface that authenticated nobody.
 *
 * @package Phlix\Hub\Tests\Support\SyncPlay
 */
trait RegistersSyncPlayClients
{
    /**
     * Token hash (sha256 of the plaintext) => the bound identity the fake token
     * lookup treats as ACTIVE.
     *
     * @var array<string, array{user_id: string, server_id: string}>
     */
    private array $syncPlayTokens = [];

    /**
     * server_id => owning user_id, for the ownership re-check.
     *
     * @var array<string, string>
     */
    private array $syncPlayServerOwners = [];

    private ?SyncPlayRelayWorker $syncPlayWorker = null;

    /**
     * Every connection handed to {@see connectSyncPlayClient()}, in order — so a
     * test can drive `onMessage()`/`onClose()` against the same object the worker
     * registered rather than a second mock it built itself.
     *
     * @var list<TcpConnection>
     */
    private array $syncPlayConnections = [];

    /**
     * Mint a relay token bound to `(userId, serverId)`.
     */
    private function grantToken(string $token, string $userId, string $serverId): void
    {
        $this->syncPlayTokens[hash('sha256', $token)] = ['user_id' => $userId, 'server_id' => $serverId];
    }

    /**
     * Declare who owns `$serverId`. Only the owner can authenticate on it.
     */
    private function setServerOwner(string $serverId, string $userId): void
    {
        $this->syncPlayServerOwners[$serverId] = $userId;
    }

    /**
     * The worker under test, built once per test.
     */
    private function syncPlayWorker(): SyncPlayRelayWorker
    {
        if ($this->syncPlayWorker === null) {
            $this->syncPlayWorker = new SyncPlayRelayWorker(
                SyncPlayRelayWorker::DEFAULT_PORT,
                1,
                $this->buildSyncPlayContainer(),
            );
        }

        return $this->syncPlayWorker;
    }

    /**
     * Drive one authenticated WebSocket upgrade. Every frame the worker writes to
     * this connection is appended to `$sink`.
     *
     * A token the fixture never granted leaves the connection UNREGISTERED, which
     * is what production does and what makes "an unauthenticated socket received
     * nothing" a real assertion rather than a tautology.
     *
     * @param list<string> $sink
     */
    private function connectSyncPlayClient(string $path, string $token, array &$sink): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('send')->willReturnCallback(
            function (mixed $data) use (&$sink): bool {
                if (is_string($data)) {
                    $sink[] = $data;
                }

                return true;
            },
        );

        $this->syncPlayConnections[] = $connection;
        $this->syncPlayWorker()->onWebSocketConnect($connection, self::makeSyncPlayUpgradeRequest($path, $token));
    }

    /**
     * The connection most recently handed to {@see connectSyncPlayClient()}.
     */
    private function lastSyncPlayConnection(): TcpConnection
    {
        $last = end($this->syncPlayConnections);
        if (!$last instanceof TcpConnection) {
            throw new RuntimeException('no SyncPlay client has been connected in this test');
        }

        return $last;
    }

    /**
     * The lazy service container `onWebSocketConnect()` authenticates through.
     */
    private function buildSyncPlayContainer(): ContainerInterface
    {
        // ClientRelayTokenService is final and cannot be mocked; build a real one
        // over a mock Connection whose query() resolves the lookup against the
        // granted tokens, keyed by the sha256 token_hash param exactly as
        // production filters.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'SELECT')) {
                    return [];
                }
                /** @var mixed $tokenHash */
                $tokenHash = $params['token_hash'] ?? null;
                if (is_string($tokenHash) && isset($this->syncPlayTokens[$tokenHash])) {
                    return [$this->syncPlayTokens[$tokenHash]];
                }

                return [];
            },
        );

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getOwnerAndStatus')->willReturnCallback(
            function (string $serverId): ?array {
                if (!isset($this->syncPlayServerOwners[$serverId])) {
                    return null;
                }

                return [
                    'userId' => $this->syncPlayServerOwners[$serverId],
                    'status' => 'online',
                    'relayActive' => true,
                ];
            },
        );

        return new class (new ClientRelayTokenService($db), $serverInfo) implements ContainerInterface {
            public function __construct(
                private readonly ClientRelayTokenService $tokenService,
                private readonly ServerInfoHandler $serverInfo,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ClientRelayTokenService::class => $this->tokenService,
                    ServerInfoHandler::class => $this->serverInfo,
                    default => throw new RuntimeException('Unknown service: ' . $id),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    ClientRelayTokenService::class,
                    ServerInfoHandler::class,
                ], true);
            }
        };
    }

    /**
     * A REAL {@see WorkermanRequest} parsed from raw upgrade text, so the token
     * travels through the same header parsing production uses.
     */
    private static function makeSyncPlayUpgradeRequest(string $path, string $token): WorkermanRequest
    {
        $headers = [
            'Host' => 'hub.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Authorization' => 'Bearer ' . $token,
        ];

        $lines = ['GET ' . $path . ' HTTP/1.1'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return new WorkermanRequest(implode("\r\n", $lines) . "\r\n\r\n");
    }
}
