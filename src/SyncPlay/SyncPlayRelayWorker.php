<?php

/**
 * Phlix hub component: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\SyncPlay;

use Channel\Client as ChannelClient;
use Psr\Container\ContainerInterface;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Timer;
use Workerman\Worker;

use function array_filter;
use function array_map;
use function count;
use function explode;
use function in_array;
use function is_string;
use function json_decode;
use function spl_object_id;
use function time;
use function trim;

/**
 * WebSocket worker that handles inbound SyncPlay relay connections.
 *
 * SyncPlay clients connect via WSS to `ws://hub:8804/syncplay/{server_id}` to
 * participate in synchronized playback sessions. This worker maintains room
 * state locally and broadcasts playback messages to all clients in a room.
 *
 * The relay token travels in the upgrade request's `Authorization: Bearer`
 * header or its `Sec-WebSocket-Protocol: bearer, <token>` subprotocol — the
 * carrier `:8803` adopted in S2b and this surface adopted in S237. It is NEVER
 * accepted from the query string: see {@see ClientRelayWorker::extractClientToken()}.
 *
 * This is separate from the main relay tunnel (ports 8802/8803) which uses
 * binary frames. SyncPlay uses native WebSocket JSON frames.
 *
 * @package Phlix\Hub\SyncPlay
 */
final class SyncPlayRelayWorker
{
    /**
     * Default SyncPlay relay WS port.
     */
    public const DEFAULT_PORT = 8804;

    /**
     * Active SyncPlay client connections keyed by connection ID.
     *
     * @var array<int, SyncPlayClient>
     */
    private static array $clients = [];

    /**
     * Map of SCOPED room key => [client_id => SyncPlayClient].
     *
     * The key is NOT the raw client-supplied room name: it is scoped to the
     * authenticated (server_id, owner) identity via {@see scopedRoomKey()} so
     * two different servers/owners that pick the same friendly room name resolve
     * to DIFFERENT internal rooms and can never control each other's playback.
     *
     * @var array<string, array<string, SyncPlayClient>>
     */
    private static array $rooms = [];

    /**
     * @param int                $port        SyncPlay WS port (default 8804).
     * @param int                $count       Number of worker processes.
     * @param ContainerInterface $container   PSR-11 container for lazy service access.
     * @param string             $channelHost `workerman/channel` broker host (S93).
     * @param int                $channelPort `workerman/channel` broker port (S93).
     *        Defaults to the SAME broker the relay proxy uses
     *        ({@see RelayProxyProtocol::DEFAULT_CHANNEL_PORT}) — there is one
     *        broker per hub process tree, not one per feature.
     *
     * Both channel parameters are TRAILING and DEFAULTED on purpose: every
     * existing call site (`Application::run()`, the unit suite) constructs this
     * worker with three arguments and must keep compiling unchanged.
     */
    public function __construct(
        private readonly int $port,
        private readonly int $count,
        private readonly ContainerInterface $container,
        private readonly string $channelHost = '127.0.0.1',
        private readonly int $channelPort = RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
    ) {
    }

    /**
     * Start the SyncPlay relay WebSocket worker.
     *
     * @return Worker The configured worker instance.
     */
    public function start(): Worker
    {
        $worker = new Worker("websocket://0.0.0.0:{$this->port}");
        $worker->name = 'phlix-hub-syncplay-relay-ws';
        $worker->count = $this->count;

        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        $worker->onWorkerStart = [$this, 'onWorkerStart'];

        // Close DB connections in onWorkerStop (still in coroutine context) so
        // hooked PDO sockets aren't destroyed at RSHUTDOWN outside a coroutine.
        \Phlix\Hub\Common\Database\ConnectionPool::armWorkerStopCleanup($worker);

        return $worker;
    }

    /**
     * Worker start hook — set up the room cleanup timer and join the channel
     * broker so HTTP workers can push pending commands into this process.
     *
     * The channel join is the S93 half: this worker is the ONLY process holding
     * the live client sockets ({@see self::$clients} is a per-process static), so
     * a pending command minted on an HTTP worker can only reach a socket by
     * crossing the `workerman/channel` broker. Mirrors
     * {@see \Phlix\Hub\Relay\RelayWorker::onWorkerStart()}.
     *
     * The join is wrapped so a broker failure logs and CONTINUES: the room
     * cleanup timer and the whole SyncPlay surface must keep working even if no
     * pending command can be delivered, and a throw here would take down a
     * resident worker at boot.
     *
     * @return void
     */
    public function onWorkerStart(): void
    {
        // Clean up empty rooms every 60 seconds
        Timer::add(60, static function (): void {
            foreach (self::$rooms as $roomName => $clients) {
                if (count($clients) === 0) {
                    unset(self::$rooms[$roomName]);
                }
            }
        });

        $logger = LoggerFactory::get(LogChannels::RELAY);

        try {
            ChannelClient::connect($this->channelHost, $this->channelPort);

            $dispatcher = new PendingCommandDispatcher($logger);
            // The vendor Channel\Client::on() callback is typed with the legacy
            // `callback` pseudo-type, which Psalm resolves to an (undefined)
            // Channel\callback class that neither an array nor a Closure can
            // satisfy. The runtime only does is_callable(), so a first-class
            // callable is correct here.
            /** @psalm-suppress InvalidArgument */
            ChannelClient::on(PendingCommandProtocol::PUSH_EVENT, $dispatcher->onPush(...));

            $logger->info('SyncPlay pending command: relay worker joined channel broker', [
                'channel_host' => $this->channelHost,
                'channel_port' => $this->channelPort,
            ]);
        } catch (Throwable $e) {
            $logger->error('SyncPlay pending command: channel init failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write `$frame` to every live client of `$userId` bound to `$serverId`.
     *
     * ## Why the match is on BOTH identities
     *
     * The `serverId` half is not decoration. A user may own two servers, and a
     * client bound to server B cannot play a media id that only exists on server
     * A — delivering there would be a command that silently fails on arrival,
     * which is exactly the class of dishonesty the delivered count exists to
     * prevent. Matching on the user alone would inflate the count with sockets
     * that could never have acted on the frame.
     *
     * ## Why room membership is irrelevant
     *
     * Delivery happens regardless of whether the client has joined a SyncPlay
     * room. A pending command is addressed to a **user's open app**, not to a
     * room: a user who has just opened Phlix and asked Alexa to start something
     * has no room, and requiring one would make the feature unreachable in its
     * primary case. This is why it does NOT go through
     * {@see self::broadcastToRoom()}.
     *
     * An empty `$userId` or `$serverId` returns 0 immediately. An empty identity
     * must never fan out: `SyncPlayClient::$userId` is nullable, and a loose
     * comparison against an unauthenticated client would turn one blank field
     * into a broadcast to every socket on the hub.
     *
     * @param string $userId   Hub user id the command is addressed to.
     * @param string $serverId Server the media id belongs to.
     * @param string $frame    The JSON frame to write.
     *
     * @return int Number of sockets actually written to.
     *
     * @since S93
     */
    public static function deliverToUser(string $userId, string $serverId, string $frame): int
    {
        if ($userId === '' || $serverId === '') {
            return 0;
        }

        $delivered = 0;
        foreach (self::$clients as $client) {
            if ($client->userId === null || $client->userId === '') {
                continue;
            }
            if ($client->userId !== $userId || $client->serverId !== $serverId) {
                continue;
            }
            $client->connection->send($frame);
            $delivered++;
        }

        return $delivered;
    }

    /**
     * Handle WebSocket upgrade for SyncPlay client.
     *
     * SV-4.7: Requires valid relay token + server ownership. Unauthenticated
     * clients may connect but cannot join rooms or control playback.
     *
     * @param TcpConnection    $connection Client connection.
     * @param WorkermanRequest $request    WS upgrade request.
     *
     * @return void
     */
    public function onWebSocketConnect(TcpConnection $connection, WorkermanRequest $request): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Parse server_id from path: /syncplay/{server_id}
        $serverId = self::parseServerId($request->path());
        if ($serverId === null) {
            $logger->warning('SyncPlay: rejected connection, missing server_id in path', [
                'path' => $request->path(),
            ]);
            $connection->close('', true);
            return;
        }

        $connId = spl_object_id($connection);
        $clientId = self::generateClientId();

        // SV-4.7 / S237: Authenticate via relay token, using the SAME CARRIER as
        // `:8803` — `ClientRelayWorker::extractClientToken()`, i.e. an
        // `Authorization: Bearer` header or a `Sec-WebSocket-Protocol: bearer,
        // <token>` subprotocol. One credential class must have exactly one
        // carrier; reading the token here a second way would be its own defect.
        //
        // ⚠ This REPLACES `$_GET['token']`, which was broken two ways at once:
        //   (1) security — the documented client URL put a live relay token in a
        //       query string, where it lands in access logs, proxy logs and
        //       `Referer` headers and outlives the token's own expiry. This is
        //       the exact form S2b removed from `:8803`.
        //   (2) function — Workerman 5 NEVER populates the `$_GET` superglobal
        //       (it carries no `$_GET` write anywhere in the package; the query
        //       is reachable only via `$request->get()`). So the read was
        //       unconditionally `null` and EVERY `:8804` connect fell through to
        //       `rejectUnauthorized()`. The surface authenticated nobody. The old
        //       unit tests passed only because they set `$_GET` by hand — a
        //       superglobal production never sets.
        $token = ClientRelayWorker::extractClientToken($request);
        $userId = null;
        if ($token !== null && $token !== '') {
            $userId = $this->validateClientAuth($token, $serverId);
        }

        if ($token === null || $token === '' || $userId === null) {
            $logger->warning('SyncPlay: rejected connection, invalid or missing relay token', [
                'server_id' => $serverId,
            ]);
            $this->rejectUnauthorized($connection);
            return;
        }

        // S355 — RFC 6455 §4.1/§4.2.2: a server that accepts a client's
        // subprotocol MUST echo EXACTLY ONE of the offered protocols in the
        // 101 response. Workerman composes the 101 from `$connection->headers`
        // (appended after onWebSocketConnect returns), and without the echo a
        // strict client — a browser or undici, exactly the S298 ui consumer's
        // `new WebSocket(url, ['bearer', token])` — aborts the handshake (no
        // open, 1006). `$token` is a non-empty string here: the guard above
        // returns on every null/empty path.
        //
        // The echo is the TOKEN ALONE, not the comma-joined `bearer, <token>`
        // carrier form: the ui consumer offers TWO protocols (`bearer` and
        // `<token>`) and a strict client rejects a response protocol that is
        // not one of the offered entries — echoing the joined form reproduces
        // the very 1006 this fixes (probed against undici 7.29.0, the ui's
        // pinned runtime). In the subprotocol-carrier shape the token is
        // always an offered entry and carries the client's own credential.
        // The echo is gated on the client HAVING offered the
        // TOKEN as a subprotocol: echoing one to an `Authorization: Bearer`
        // client — or to a both-carrier client whose subprotocol header does
        // not contain the token — would answer a negotiation the client never
        // made (RFC 6455 §4.1).
        /** @var mixed $requestedProtocol */
        $requestedProtocol = $request->header('sec-websocket-protocol');
        if (is_string($requestedProtocol) && $requestedProtocol !== '') {
            $offered = array_filter(
                array_map('trim', explode(',', $requestedProtocol)),
                static fn (string $protocol): bool => $protocol !== '',
            );
            if (in_array($token, $offered, true)) {
                $connection->headers = ['Sec-WebSocket-Protocol: ' . $token];
            }
        }

        // Create client state with authenticated userId
        $client = new SyncPlayClient(
            $connection,
            $serverId,
            $clientId,
            $userId,
        );

        self::$clients[$connId] = $client;

        $logger->info('SyncPlay: client connected (authenticated)', [
            'client_id' => $clientId,
            'server_id' => $serverId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Validate a client relay token for SyncPlay access.
     *
     * Mirrors the validation in {@see ClientRelayWorker::validateClientAuth()}.
     *
     * @param string $token    The relay token, from the upgrade request's
     *                        `Authorization` header or `bearer` subprotocol
     *                        (S237 — never from the query string).
     * @param string $serverId The server_id the client wants to join.
     *
     * @return string|null The authenticated user id, or null on failure.
     */
    private function validateClientAuth(string $token, string $serverId): ?string
    {
        // Fetch auth services from container lazily (same pattern as ClientRelayWorker)
        /** @var ClientRelayTokenService $tokenService */
        $tokenService = $this->container->get(ClientRelayTokenService::class);
        /** @var ServerInfoHandler $serverInfo */
        $serverInfo = $this->container->get(ServerInfoHandler::class);

        // Validate token with ClientRelayTokenService
        $bound = $tokenService->validate($token);
        if ($bound === null) {
            return null;
        }

        // Token must be scoped to the requested server
        if ($bound['server_id'] !== $serverId) {
            return null;
        }

        // Re-confirm current ownership: the bound user must still own the server
        $owner = $serverInfo->getOwnerAndStatus($serverId);
        if ($owner === null) {
            return null;
        }

        if ($owner['userId'] !== $bound['user_id']) {
            return null;
        }

        return $bound['user_id'];
    }

    /**
     * Reject an unauthenticated connection.
     *
     * @param TcpConnection $connection The connection to close.
     *
     * @return void
     */
    private function rejectUnauthorized(TcpConnection $connection): void
    {
        $connection->close('', true);
    }

    /**
     * Handle incoming SyncPlay message.
     *
     * SyncPlay messages are JSON with a 'type' field indicating the message kind.
     *
     * @param TcpConnection $connection Client connection.
     * @param string        $data       Raw WebSocket frame payload (JSON text).
     *
     * @return void
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        $connId = spl_object_id($connection);
        $client = self::$clients[$connId] ?? null;

        if ($client === null) {
            return;
        }

        // Parse SyncPlay JSON message
        /** @var array<string, mixed>|null $message */
        $message = json_decode($data, true);
        if (!is_array($message)) {
            return;
        }

        /** @var mixed $messageType */
        $messageType = $message['type'];
        $type = is_string($messageType) ? $messageType : null;

        switch ($type) {
            case 'group_join':
                $this->handleGroupJoin($client, $message);
                break;

            case 'playback_play':
            case 'playback_pause':
            case 'playback_seek':
                /** @var string $type */
                $this->handlePlayback($client, $message, $type);
                break;

            case 'time_sync':
                $this->handleTimeSync($client, $message);
                break;

            case 'group_leave':
                $this->handleGroupLeave($client);
                break;

            default:
                // Unknown message type - relay to room if in one
                if ($client->room !== null) {
                    $this->broadcastToRoom($client->room, $data, $client->clientId);
                }
        }
    }

    /**
     * Handle client connection close.
     *
     * @param TcpConnection $connection Client connection.
     *
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $connId = spl_object_id($connection);
        $client = self::$clients[$connId] ?? null;

        if ($client === null) {
            return;
        }

        // Remove from room if in one
        if ($client->room !== null) {
            $this->handleGroupLeave($client);
        }

        unset(self::$clients[$connId]);

        $logger = LoggerFactory::get(LogChannels::RELAY);
        $logger->info('SyncPlay: client disconnected', [
            'client_id' => $client->clientId,
            'server_id' => $client->serverId,
        ]);
    }

    /**
     * Handle group_join message - client joins a SyncPlay room.
     *
     * @param SyncPlayClient     $client  The joining client.
     * @param array<string, mixed> $message Parsed JSON message.
     *
     * @return void
     */
    private function handleGroupJoin(SyncPlayClient $client, array $message): void
    {
        // SV-4.7: Require authentication to join a SyncPlay room.
        if ($client->userId === null) {
            $logger = LoggerFactory::get(LogChannels::RELAY);
            $logger->warning('SyncPlay: rejected group_join from unauthenticated client', [
                'client_id' => $client->clientId,
            ]);
            $client->connection->close('', true);
            return;
        }

        $clientRoom = $message['room'] ?? null;
        if ($clientRoom === null) {
            return;
        }
        // @var guard: json message room field is string when not null
        if (!is_string($clientRoom)) {
            return;
        }

        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Leave current room if in one
        if ($client->room !== null) {
            $this->handleGroupLeave($client);
        }

        // Scope the room to the authenticated (server_id, owner) identity. The
        // client supplies a friendly name; two different servers/owners that
        // pick the same friendly name must land in DIFFERENT internal rooms so
        // a control/broadcast never crosses the (server_id, owner) boundary.
        $scopedRoom = self::scopedRoomKey($client, $clientRoom);

        // Join new room (keyed by the scoped key, never the raw client string)
        $client->room = $scopedRoom;
        /** @var mixed $messageDisplayName */
        $messageDisplayName = $message['display_name'] ?? null;
        $displayName = is_string($messageDisplayName) ? $messageDisplayName : 'Anonymous';
        $client->displayName = $displayName;

        if (!isset(self::$rooms[$scopedRoom])) {
            self::$rooms[$scopedRoom] = [];
        }
        self::$rooms[$scopedRoom][$client->clientId] = $client;

        // Send current room state to joining client (echo the FRIENDLY name back)
        $roomState = $this->getRoomState($scopedRoom);
        $stateMessage = [
            'type' => 'room_state',
            'room' => $clientRoom,
            'clients' => $roomState,
        ];
        $client->connection->send(json_encode($stateMessage, JSON_THROW_ON_ERROR));

        // Notify other clients in the (scoped) room about the new joiner
        $joinNotification = [
            'type' => 'client_joined',
            'client_id' => $client->clientId,
            'display_name' => $client->displayName,
        ];
        $this->broadcastToRoom(
            $scopedRoom,
            json_encode($joinNotification, JSON_THROW_ON_ERROR),
            $client->clientId,
            true,
        );

        $logger->info('SyncPlay: client joined room', [
            'client_id' => $client->clientId,
            'room' => $clientRoom,
            'server_id' => $client->serverId,
        ]);
    }

    /**
     * Handle group_leave message - client leaves their current room.
     *
     * @param SyncPlayClient $client The leaving client.
     *
     * @return void
     */
    private function handleGroupLeave(SyncPlayClient $client): void
    {
        if ($client->room === null) {
            return;
        }

        $room = $client->room;
        $clientId = $client->clientId;

        unset(self::$rooms[$room][$clientId]);
        $client->room = null;

        // Notify room about departure
        $leaveNotification = [
            'type' => 'client_left',
            'client_id' => $clientId,
        ];
        $this->broadcastToRoom($room, json_encode($leaveNotification, JSON_THROW_ON_ERROR), $clientId, true);
    }

    /**
     * Handle playback messages (play, pause, seek) - broadcast to room.
     *
     * @param SyncPlayClient     $client  The sending client.
     * @param array<string, mixed> $message Parsed JSON message.
     * @param string             $type   Playback message type.
     *
     * @return void
     */
    private function handlePlayback(SyncPlayClient $client, array $message, string $type): void
    {
        if ($client->room === null) {
            return;
        }

        // Enrich message with sender info and broadcast
        $message['type'] = $type;
        $message['from_client_id'] = $client->clientId;
        $message['timestamp'] = time();

        // Broadcast to all other clients in the room (not the sender)
        $this->broadcastToRoom($client->room, json_encode($message, JSON_THROW_ON_ERROR), $client->clientId, true);
    }

    /**
      * Handle time_sync message - respond with server time for sync.
      *
      * @param SyncPlayClient     $client  The requesting client.
      * @param array<string, mixed> $message Parsed JSON message.
      *
      * @return void
      */
    private function handleTimeSync(SyncPlayClient $client, array $message): void
    {
        // Respond with time sync reply containing server timestamp
        $reply = [
            'type' => 'time_sync_reply',
            'server_time' => time(),
            'client_time' => $message['client_time'] ?? null,
        ];

        $client->connection->send(json_encode($reply, JSON_THROW_ON_ERROR));
    }

    /**
     * Broadcast a message to all clients in a room.
     *
     * @param string $room         Scoped room key (see {@see scopedRoomKey()}).
     * @param string $message     JSON message to send.
     * @param string $excludeId   Client ID to exclude (optional).
     * @param bool   $includeSelf Include sender in broadcast (default false).
     *
     * @return void
     */
    private function broadcastToRoom(
        string $room,
        string $message,
        string $excludeId = '',
        bool $includeSelf = false,
    ): void {
        $clients = self::$rooms[$room] ?? [];
        foreach ($clients as $client) {
            if (!$includeSelf && $client->clientId === $excludeId) {
                continue;
            }
            $client->connection->send($message);
        }
    }

    /**
     * Get current state of a room.
     *
     * @param string $room Scoped room key (see {@see scopedRoomKey()}).
     *
     * @return array<string, array{client_id: string, display_name: string}> Client list.
     */
    private function getRoomState(string $room): array
    {
        $clients = self::$rooms[$room] ?? [];
        $state = [];
        foreach ($clients as $client) {
            $state[$client->clientId] = [
                'client_id' => $client->clientId,
                'display_name' => $client->displayName,
            ];
        }
        return $state;
    }

    /**
     * Parse server_id from /syncplay/{server_id} path.
     *
     * @param string $path Request path.
     *
     * @return string|null Server ID or null if not found.
     */
    public static function parseServerId(string $path): ?string
    {
        if (preg_match('~^/syncplay/([^/?#]+)/?(?:[?#].*)?$~', $path, $matches) !== 1) {
            return null;
        }
        $serverId = trim(rawurldecode($matches[1]));
        return $serverId !== '' ? $serverId : null;
    }

    /**
     * Compose the internal, scoped room key for a client's friendly room name.
     *
     * The effective room namespace is scoped to the authenticated
     * (server_id, owner) identity established in {@see validateClientAuth()}.
     * `server_id` and `owner` are UUIDs (hex + hyphens, never a colon), so the
     * first two `:` delimiters unambiguously separate the scope prefix from the
     * arbitrary client-supplied friendly name — two different servers/owners can
     * never resolve to the same internal room even with identical friendly names.
     *
     * @param SyncPlayClient $client     The authenticated client (server_id + owner).
     * @param string         $clientRoom The friendly room name from the client.
     *
     * @return string The scoped room key.
     */
    private static function scopedRoomKey(SyncPlayClient $client, string $clientRoom): string
    {
        return $client->serverId . ':' . (string) $client->userId . ':' . $clientRoom;
    }

    /**
     * Generate a unique client ID.
     *
     * @return string UUID-like client identifier.
     */
    private static function generateClientId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }

    /**
     * Get active connection count (diagnostics).
     *
     * @return int Active connection count.
     */
    public static function getActiveConnectionCount(): int
    {
        return count(self::$clients);
    }

    /**
     * Get room count (diagnostics).
     *
     * @return int Active room count.
     */
    public static function getActiveRoomCount(): int
    {
        return count(self::$rooms);
    }

    /**
     * Clear all static state (for test isolation).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$clients = [];
        self::$rooms = [];
    }
}
