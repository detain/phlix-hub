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
     * Wire-dialect latch (owner decision #14). `false` = the legacy bare room
     * vocabulary (`group_join`/`room_state`/`playback_*`), the dialect this
     * worker shipped with. `true` = the canonical `syncplay_*` catalog
     * (phlix-syncplay SPEC.md §3, the vocabulary every syncplay client and the
     * server `:8097` socket speak).
     *
     * The latch is set by {@see SyncPlayRelayWorker::onMessage()} on the first
     * `syncplay_`-prefixed frame the connection sends, and it decides which
     * vocabulary the connection's REPLIES are encoded in: canonical events fan
     * out only to canonical-latched members, bare events only to bare members.
     * Nothing is ever translated between the two — the bare vocabulary has zero
     * live room consumers in the estate (evidence pinned in the worker's
     * dialect docblock), so a room with BOTH dialects is a synthetic shape the
     * relay answers per-speaker rather than inventing a bridge for.
     */
    public bool $canonical = false;

    /**
     * UNIX SECONDS this connection joined its CURRENT room, stamped by the
     * canonical join path only. Feeds the `group_state` members dict
     * (`joined_at` is seconds-scaled per SPEC §4) and the oldest-member host
     * election. Reset to null on leave; never read for bare-dialect clients.
     */
    public ?int $roomJoinedAt = null;

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
