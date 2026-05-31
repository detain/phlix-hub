<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Workerman\Connection\ConnectionInterface;

/**
 * Manages active master ↔ leaf WebSocket connections on the master hub.
 *
 * On the master hub this maps hubId → WS connection for each connected leaf.
 * On a leaf hub this stores the single master connection.
 *
 * @package Phlix\Hub\Federation
 */
final class FederationConnectionManager
{
    /**
     * hubId → WS connection.
     *
     * @var array<string, ConnectionInterface>
     */
    private array $connections = [];

    /**
     * WS connection id (spl_object_id) → hubId.
     *
     * @var array<int, string>
     */
    private array $reverseMap = [];

    /**
     * Register a new leaf hub connection.
     *
     * @param string             $hubId  Leaf hub UUID.
     * @param ConnectionInterface $conn  Workerman WS connection.
     *
     * @return void
     */
    public function addConnection(string $hubId, ConnectionInterface $conn): void
    {
        $connId = spl_object_id($conn);
        $this->connections[$hubId] = $conn;
        $this->reverseMap[$connId] = $hubId;
    }

    /**
     * Remove a leaf hub connection.
     *
     * @param string $hubId Leaf hub UUID.
     *
     * @return void
     */
    public function removeConnection(string $hubId): void
    {
        if (!isset($this->connections[$hubId])) {
            return;
        }

        $conn = $this->connections[$hubId];
        $connId = spl_object_id($conn);
        unset($this->connections[$hubId], $this->reverseMap[$connId]);
    }

    /**
     * Remove a connection by its Workerman connection instance.
     *
     * @param ConnectionInterface $conn Workerman WS connection.
     *
     * @return void
     */
    public function removeConnectionByConn(ConnectionInterface $conn): void
    {
        $connId = spl_object_id($conn);
        $hubId = $this->reverseMap[$connId] ?? null;

        if ($hubId !== null) {
            unset($this->connections[$hubId], $this->reverseMap[$connId]);
        }
    }

    /**
     * Get the WS connection for a given hub.
     *
     * @param string $hubId Leaf hub UUID.
     *
     * @return ConnectionInterface|null
     */
    public function getConnection(string $hubId): ?ConnectionInterface
    {
        return $this->connections[$hubId] ?? null;
    }

    /**
     * Check whether a hub is currently connected.
     *
     * @param string $hubId Leaf hub UUID.
     *
     * @return bool
     */
    public function isConnected(string $hubId): bool
    {
        return isset($this->connections[$hubId]);
    }

    /**
     * Broadcast a frame to all connected leaf hubs.
     *
     * @param string $data     Serialised frame bytes.
     * @param int    $frameType Workerman WebSocket frame type constant.
     *
     * @return void
     */
    public function broadcastToAll(string $data, int $frameType): void
    {
        foreach ($this->connections as $conn) {
            $conn->send($data);
        }
    }

    /**
     * Send a frame to a specific leaf hub.
     *
     * @param string $hubId    Leaf hub UUID.
     * @param string $data     Serialised frame bytes.
     * @param int    $frameType Workerman WebSocket frame type constant.
     *
     * @return bool True if the hub was connected and the frame was sent.
     */
    public function sendTo(string $hubId, string $data, int $frameType): bool
    {
        $conn = $this->connections[$hubId] ?? null;
        if ($conn === null) {
            return false;
        }

        $conn->send($data);
        return true;
    }

    /**
     * Get all connected hub IDs.
     *
     * @return array<string>
     */
    public function getAllHubIds(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Get the count of active connections.
     *
     * @return int
     */
    public function connectionCount(): int
    {
        return count($this->connections);
    }
}
