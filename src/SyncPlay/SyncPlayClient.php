<?php

/**
 * Phlix hub component: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\SyncPlay;

use Workerman\Connection\TcpConnection;

/**
 * Represents a SyncPlay client connection.
 *
 * @package Phlix\Hub\SyncPlay
 */
final class SyncPlayClient
{
    /**
     * @param TcpConnection $connection   Workerman TCP connection.
     * @param string       $serverId    Server UUID this client belongs to.
     * @param string       $clientId    Unique client UUID assigned by hub.
     * @param string|null  $userId      Authenticated user ID (null if not authenticated).
     * @param string|null  $room        Current SyncPlay room name (null if not in a room).
     * @param string       $displayName Display name for this client.
     */
    public function __construct(
        public readonly TcpConnection $connection,
        public readonly string $serverId,
        public readonly string $clientId,
        public ?string $userId = null,
        public ?string $room = null,
        public string $displayName = 'Anonymous',
    ) {
    }
}
