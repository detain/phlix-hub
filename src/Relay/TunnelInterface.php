<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use SplObjectStorage;

/**
 * Interface for tunnel operations.
 *
 * Defines the contract for a bidirectional WebSocket tunnel between
 * the hub and a server.
 *
 * @package Phlix\Hub\Relay
 */
interface TunnelInterface
{
    /**
     * @var string Tunnel is awaiting the HELLO handshake from the server.
     */
    public const string STATUS_PENDING = 'pending';

    /**
     * @var string Tunnel is active and frames can be exchanged.
     */
    public const string STATUS_ACTIVE = 'active';

    /**
     * @var string Tunnel is being closed (clean shutdown in progress).
     */
    public const string STATUS_CLOSING = 'closing';

    /**
     * @var string Tunnel is fully closed and all resources released.
     */
    public const string STATUS_CLOSED = 'closed';

    /**
     * @return string Unique tunnel UUID.
     */
    public function getTunnelId(): string;

    /**
     * @return string Server UUID.
     */
    public function getServerId(): string;

    /**
     * @return int Timestamp of the last frame received from the server.
     */
    public function getLastFrameAt(): int;

    /**
     * @return string Current tunnel status (STATUS_PENDING|STATUS_ACTIVE|STATUS_CLOSING|STATUS_CLOSED).
     */
    public function getStatus(): string;

    /**
     * @return SplObjectStorage<ClientConnection, mixed> Client connections attached to this tunnel.
     */
    public function getClientConnections(): SplObjectStorage;

    /**
     * @return int Total bytes sent to the server through this tunnel.
     */
    public function getBytesOut(): int;

    /**
     * @return int Total bytes received from the server and sent to clients.
     */
    public function getBytesIn(): int;

    /**
     * Check if the tunnel is stale (no frames received within the threshold).
     *
     * @param int $staleThresholdSeconds Threshold in seconds to consider stale.
     *
     * @return bool True if the tunnel is stale.
     */
    public function isStale(int $staleThresholdSeconds = 90): bool;

    /**
     * Close the tunnel with the given reason.
     *
     * @param string $reason Human-readable close reason.
     *
     * @return void
     */
    public function close(string $reason = 'normal'): void;

    /**
     * Send a heartbeat frame to the server.
     *
     * @return void
     */
    public function sendHeartbeat(): void;
}
