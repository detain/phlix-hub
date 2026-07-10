<?php

/**
 * Phlix hub component: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\SyncPlay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Timer;
use Workerman\Worker;

use function count;
use function is_string;
use function json_decode;
use function spl_object_id;
use function time;

/**
 * WebSocket worker that handles inbound SyncPlay relay connections.
 *
 * SyncPlay clients connect via WSS to `ws://hub:8804/syncplay/{server_id}` to
 * participate in synchronized playback sessions. This worker maintains room
 * state locally and broadcasts playback messages to all clients in a room.
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
     * Map of room name => [client_id => SyncPlayClient].
     *
     * @var array<string, array<string, SyncPlayClient>>
     */
    private static array $rooms = [];

    /**
     * @param int $port  SyncPlay WS port (default 8804).
     * @param int $count Number of worker processes.
     */
    public function __construct(
        private readonly int $port = self::DEFAULT_PORT,
        private readonly int $count = 1,
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
     * Worker start hook - set up room cleanup timer.
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
    }

    /**
     * Handle WebSocket upgrade for SyncPlay client.
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

        // Create client state
        $client = new SyncPlayClient(
            $connection,
            $serverId,
            $clientId,
        );

        self::$clients[$connId] = $client;

        $logger->info('SyncPlay: client connected', [
            'client_id' => $clientId,
            'server_id' => $serverId,
        ]);
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
        $room = $message['room'] ?? null;
        if ($room === null) {
            return;
        }
        // @var guard: json message room field is string when not null
        if (!is_string($room)) {
            return;
        }

        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Leave current room if in one
        if ($client->room !== null) {
            $this->handleGroupLeave($client);
        }

        // Join new room
        $client->room = $room;
        /** @var mixed $messageDisplayName */
        $messageDisplayName = $message['display_name'];
        $displayName = is_string($messageDisplayName) ? $messageDisplayName : 'Anonymous';
        $client->displayName = $displayName;

        if (!isset(self::$rooms[$room])) {
            self::$rooms[$room] = [];
        }
        self::$rooms[$room][$client->clientId] = $client;

        // Send current room state to joining client
        $roomState = $this->getRoomState($room);
        $stateMessage = [
            'type' => 'room_state',
            'room' => $room,
            'clients' => $roomState,
        ];
        $client->connection->send(json_encode($stateMessage, JSON_THROW_ON_ERROR));

        // Notify other clients in room about new joiner
        $joinNotification = [
            'type' => 'client_joined',
            'client_id' => $client->clientId,
            'display_name' => $client->displayName,
        ];
        $this->broadcastToRoom($room, json_encode($joinNotification, JSON_THROW_ON_ERROR), $client->clientId, true);

        $logger->info('SyncPlay: client joined room', [
            'client_id' => $client->clientId,
            'room' => $room,
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
     * @param string $room         Room name.
     * @param string $message     JSON message to send.
     * @param string $excludeId   Client ID to exclude (optional).
     * @param bool   $includeSelf Include sender in broadcast (default false).
     *
     * @return void
     */
    private function broadcastToRoom(string $room, string $message, string $excludeId = '', bool $includeSelf = false): void
    {
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
     * @param string $room Room name.
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
