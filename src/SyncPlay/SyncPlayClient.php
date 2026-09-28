<?php

/**
 * Phlix hub component: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\SyncPlay;

use Phlix\Hub\Relay\TokenBucket;
use Workerman\Connection\TcpConnection;

/**
 * Represents a SyncPlay client connection.
 *
 * @package Phlix\Hub\SyncPlay
 */
final class SyncPlayClient
{
    /**
     * Per-connection inbound byte budget (audit M-3), created by
     * {@see SyncPlayRelayWorker::onWebSocketConnect()} from the worker's
     * injected rate/burst config and carried HERE so it dies with the client —
     * never a `static`/`global` (the resident-worker leak rule TokenBucket's
     * own cardinal-note exists to protect). Null only for clients built outside
     * the production connect path; the worker then treats the socket as
     * unbudgeted.
     */
    public ?TokenBucket $inboundBucket = null;

    /**
     * One-warning-per-connection latch for inbound-throttle drops: logging every
     * dropped frame would turn the flood into a log-noise amplifier — the exact
     * failure class the limiter exists to remove.
     */
    public bool $inboundThrottleLogged = false;

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
