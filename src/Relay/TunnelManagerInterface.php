<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Generator;
use Workerman\Connection\TcpConnection;

/**
 * Interface for tunnel management operations.
 *
 * Defines the contract for managing relay tunnels between the hub and servers.
 *
 * @package Phlix\Hub\Relay
 * @since 0.5.0
 */
interface TunnelManagerInterface
{
    /**
     * Accept a new server connection and create a tunnel.
     *
     * @param string      $serverId Server UUID from the HELLO handshake.
     * @param TcpConnection $serverWs Workerman connection to the server.
     *
     * @return Tunnel The newly created tunnel.
     */
    public function acceptServer(string $serverId, TcpConnection $serverWs): Tunnel;

    /**
     * Get the tunnel for a given server ID.
     *
     * @param string $serverId Server UUID.
     *
     * @return Tunnel|null The tunnel if found and active, null otherwise.
     */
    public function getTunnelForServer(string $serverId): ?Tunnel;

    /**
     * Accept a new client connection and attach it to the appropriate tunnel.
     *
     * @param string      $serverId Server UUID the client wants to connect to.
     * @param TcpConnection $clientWs Workerman connection to the client.
     * @param string      $clientId Client UUID assigned by the hub.
     * @param string      $sessionId Optional relay session ID for this client.
     *
     * @return ClientConnection|null The created ClientConnection, or null if tunnel not found.
     */
    public function acceptClient(
        string $serverId,
        TcpConnection $clientWs,
        string $clientId,
        string $sessionId = '',
    ): ?ClientConnection;

    /**
     * Close a tunnel by server ID.
     *
     * @param string $serverId Server UUID.
     * @param string $reason   Human-readable close reason.
     *
     * @return void
     */
    public function closeTunnel(string $serverId, string $reason): void;

    /**
     * Get all active tunnels as a generator.
     *
     * @return Generator<string, Tunnel>
     */
    public function allTunnels(): Generator;

    /**
     * Get the count of active tunnels.
     *
     * @return int Number of active tunnels.
     */
    public function getActiveTunnelCount(): int;

    /**
     * Check if an active tunnel exists for the given server ID.
     *
     * @param string $serverId Server UUID.
     *
     * @return bool True if an active tunnel exists.
     */
    public function hasTunnel(string $serverId): bool;

    /**
     * Remove a tunnel from the manager.
     *
     * @param string $serverId Server UUID.
     *
     * @return void
     */
    public function removeTunnel(string $serverId): void;
}
