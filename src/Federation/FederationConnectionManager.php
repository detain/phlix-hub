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
 * H-4 verified stamp: a registration only means a socket exists — it says
 * nothing about WHO is at the other end. A connection is marked VERIFIED via
 * {@see markVerified()} exclusively after the mutual Ed25519 handshake
 * (see {@see FederationHandshake}) proves the peer holds the private key
 * belonging to the public key registered on its peer row. Share/offer/admin
 * traffic MUST consult {@see isVerified()} before acting. The stamp is bound
 * to the connection OBJECT, not just the hubId: when a hub reconnects the new
 * socket starts unverified, and any removal of the verified socket drops the
 * stamp with it.
 *
 * PROCESS-LOCAL: this manager lives in the DI container of ONE Workerman
 * process. The HTTP worker (:8800) and the FederationWorker (:8805) each
 * hold their OWN instance with separate maps, so a live sendTo()/isVerified()
 * call made from HTTP-side code cannot see (or use) the sockets the federation
 * worker accepted — those calls no-op until an inter-process channel bridge
 * exists (see the send() seam docblock in {@see FederationMasterPusher}).
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
     * hubId → spl_object_id of the connection that completed the H-4
     * handshake. Entry exists only while that exact socket stays registered.
     *
     * @var array<string, int>
     */
    private array $verifiedConns = [];

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
                // A replacement socket never inherits the old socket's
                // handshake proof — it must complete the ceremony itself.
                unset($this->verifiedConns[$hubId]);
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
        unset($this->connections[$hubId], $this->reverseMap[$connId], $this->verifiedConns[$hubId]);
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

        unset($this->connections[$hubId], $this->reverseMap[$connId], $this->verifiedConns[$hubId]);

        return true;
    }

    /**
     * Stamp a connection as VERIFIED after the mutual Ed25519 handshake
     * (H-4) proved this socket's peer holds the registered private key.
     *
     * Identity-guarded: the stamp is only laid when $conn is STILL the
     * registered socket for $hubId, so a proof completed on a connection that
     * was just replaced can never vouch for its successor.
     *
     * @param string              $hubId Leaf hub UUID.
     * @param ConnectionInterface $conn  The socket that completed the handshake.
     *
     * @return bool True when the stamp was laid; false when $conn is no longer
     *              the registered connection for $hubId.
     */
    public function markVerified(string $hubId, ConnectionInterface $conn): bool
    {
        if (($this->connections[$hubId] ?? null) !== $conn) {
            return false;
        }

        $this->verifiedConns[$hubId] = spl_object_id($conn);

        return true;
    }

    /**
     * Has the currently-registered socket for this hub completed the H-4
     * handshake proof?
     *
     * @param string $hubId Leaf hub UUID.
     *
     * @return bool
     */
    public function isVerified(string $hubId): bool
    {
        $conn = $this->connections[$hubId] ?? null;
        if ($conn === null) {
            return false;
        }

        return ($this->verifiedConns[$hubId] ?? null) === spl_object_id($conn);
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
