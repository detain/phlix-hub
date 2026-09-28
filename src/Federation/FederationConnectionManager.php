<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
        // Close and clean up any prior connection for this hubId before
        // overwriting. Guard against re-adding the same connection object
        // (e.g. the same WS object that was just closed and hasn't been
        // garbage-collected yet).
        if (isset($this->connections[$hubId])) {
            $existing = $this->connections[$hubId];
            $existingId = spl_object_id($existing);
            if ($existingId !== spl_object_id($conn)) {
                $existing->close();
                unset($this->reverseMap[$existingId]);
            }
        }

        $connId = spl_object_id($conn);
        $this->connections[$hubId] = $conn;
        $this->reverseMap[$connId] = $hubId;
    }

    /**
     * Remove a leaf hub connection.
     *
     * Prefer {@see removeConnectionByConn()} whenever the closing connection
     * object is known: removing by hubId alone lets a SUPERSEDED connection's
     * late onClose unmap the NEW connection registered under the same hubId
     * (M-6 race). This variant stays for hub-keyed cleanup without an object
     * at hand (e.g. explicit admin disconnect).
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
     * Remove a connection by its Workerman connection instance (identity-safe).
     *
     * The mapping is dropped only when the reverse map STILL points at this
     * exact object, so a stale close event from a replaced connection is a
     * no-op instead of evicting the live registration.
     *
     * @param ConnectionInterface $conn Workerman WS connection.
     *
     * @return bool True when this connection was the registered one and was
     *              unmapped; false when it was already superseded or absent.
     */
    public function removeConnectionByConn(ConnectionInterface $conn): bool
    {
        $connId = spl_object_id($conn);
        $hubId = $this->reverseMap[$connId] ?? null;

        if ($hubId === null) {
            return false;
        }

        unset($this->connections[$hubId], $this->reverseMap[$connId]);

        return true;
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
     * The bytes are a fully-encoded relay/text payload; the WS frame class is
     * decided by Workerman from the payload type, so no frame-type parameter
     * is needed (L-7: the old `int $frameType` was never read).
     *
     * @param string $data Serialised frame bytes.
     *
     * @return void
     */
    public function broadcastToAll(string $data): void
    {
        foreach ($this->connections as $conn) {
            $conn->send($data);
        }
    }

    /**
     * Send a frame to a specific leaf hub.
     *
     * @param string $hubId Leaf hub UUID.
     * @param string $data  Serialised frame bytes.
     *
     * @return bool True if the hub was connected and the frame was sent.
     */
    public function sendTo(string $hubId, string $data): bool
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
