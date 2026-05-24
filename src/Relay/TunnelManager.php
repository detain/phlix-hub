<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Generator;
use Workerman\Connection\TcpConnection;

use function gethostname;
use function time;

/**
 * Manages all active relay tunnels between the hub and servers.
 *
 * Provides:
 *   - Registration of new server tunnels via acceptServer()
 *   - Lookup of tunnels by server ID
 *   - Client connection routing via acceptClient()
 *   - Tunnel lifecycle (close, reaper)
 *
 * @package Phlix\Hub\Relay
 * @since 0.5.0
 */
final class TunnelManager
{
    /**
     * @param RelaySessionManager       $sessionManager Session manager for byte accounting.
     * @param RelayWireCodecInterface   $codec         Wire codec for frame encoding/decoding.
     * @param StructuredLogger          $logger        Structured logger for relay events.
     */
    public function __construct(
        private readonly RelaySessionManager $sessionManager,
        private readonly RelayWireCodecInterface $codec,
        private readonly StructuredLogger $logger,
    ) {
        $this->tunnels = [];
    }

    /**
     * @var array<string, Tunnel> Active tunnels keyed by server ID.
     */
    private array $tunnels;

    /**
     * Accept a new server connection and create a tunnel.
     *
     * If a tunnel already exists for this server_id, it is closed first
     * (server reconnect scenario) before a new one is created.
     *
     * @param string      $serverId Server UUID from the HELLO handshake.
     * @param TcpConnection $serverWs Workerman connection to the server.
     *
     * @return Tunnel The newly created tunnel (in PENDING state until HELLO is received).
     */
    public function acceptServer(string $serverId, TcpConnection $serverWs): Tunnel
    {
        // If a tunnel already exists for this server, close it first (server reconnect)
        if (isset($this->tunnels[$serverId])) {
            $this->logger->info('Relay: closing existing tunnel for reconnecting server', [
                'server_id' => $serverId,
            ]);
            $this->closeTunnel($serverId, 'server_replaced');
        }

        /** @var non-falsy-string $workerNode */
        $workerNode = (string) (@gethostname() ?: 'unknown');

        $tunnel = new Tunnel(
            $serverId,
            $serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $this->tunnels[$serverId] = $tunnel;

        $this->logger->info('Relay: server accepted, tunnel created', [
            'server_id' => $serverId,
            'tunnel_id' => $tunnel->tunnelId,
            'worker_node' => $workerNode,
        ]);

        return $tunnel;
    }

    /**
     * Get the tunnel for a given server ID.
     *
     * @param string $serverId Server UUID.
     *
     * @return Tunnel|null The tunnel if found and active, null otherwise.
     */
    public function getTunnelForServer(string $serverId): ?Tunnel
    {
        return $this->tunnels[$serverId] ?? null;
    }

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
    ): ?ClientConnection {
        $tunnel = $this->getTunnelForServer($serverId);

        if ($tunnel === null) {
            $this->logger->warning('Relay: client connection rejected, server not connected', [
                'server_id' => $serverId,
                'client_id' => $clientId,
            ]);
            return null;
        }

        if ($tunnel->status !== Tunnel::STATUS_ACTIVE) {
            $this->logger->warning('Relay: client connection rejected, tunnel not active', [
                'server_id' => $serverId,
                'tunnel_id' => $tunnel->tunnelId,
                'client_id' => $clientId,
                'tunnel_status' => $tunnel->status,
            ]);
            return null;
        }

        $client = new ClientConnection($clientWs, $serverId, $clientId, $this->logger, $sessionId);
        $client->tunnel = $tunnel;
        $tunnel->registerClient($client);

        $this->logger->info('Relay: client connected to tunnel', [
            'server_id' => $serverId,
            'tunnel_id' => $tunnel->tunnelId,
            'client_id' => $clientId,
        ]);

        return $client;
    }

    /**
     * Close a tunnel by server ID.
     *
     * Marks the tunnel as closed, sends TYPE_DISCONNECTED to all clients,
     * closes the server connection, and removes the tunnel from the map.
     *
     * @param string $serverId Server UUID.
     * @param string $reason   Human-readable close reason.
     *
     * @return void
     */
    public function closeTunnel(string $serverId, string $reason): void
    {
        $tunnel = $this->tunnels[$serverId] ?? null;

        if ($tunnel === null) {
            return;
        }

        $tunnel->close($reason);

        unset($this->tunnels[$serverId]);

        $this->logger->info('Relay: tunnel closed and removed', [
            'server_id' => $serverId,
            'tunnel_id' => $tunnel->tunnelId,
            'reason' => $reason,
        ]);
    }

    /**
     * Get all active tunnels as a generator.
     *
     * Yields [serverId => Tunnel] for all tunnels in ACTIVE status.
     * Used by heartbeat timer and idle reaper to iterate without modifying
     * the underlying array during iteration.
     *
     * @return Generator<string, Tunnel>
     */
    public function allTunnels(): Generator
    {
        foreach ($this->tunnels as $serverId => $tunnel) {
            if ($tunnel->status === Tunnel::STATUS_ACTIVE) {
                yield $serverId => $tunnel;
            }
        }
    }

    /**
     * Get the count of active tunnels.
     *
     * @return int Number of active tunnels.
     */
    public function getActiveTunnelCount(): int
    {
        $count = 0;
        foreach ($this->tunnels as $tunnel) {
            if ($tunnel->status === Tunnel::STATUS_ACTIVE) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Check if an active tunnel exists for the given server ID.
     *
     * @param string $serverId Server UUID.
     *
     * @return bool True if an active tunnel exists.
     */
    public function hasTunnel(string $serverId): bool
    {
        return isset($this->tunnels[$serverId])
            && $this->tunnels[$serverId]->status === Tunnel::STATUS_ACTIVE;
    }

    /**
     * Remove a tunnel from the manager (called after cleanup).
     *
     * @param string $serverId Server UUID.
     *
     * @return void
     */
    public function removeTunnel(string $serverId): void
    {
        unset($this->tunnels[$serverId]);
    }
}
