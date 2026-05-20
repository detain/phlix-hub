<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Manages relay sessions between the hub and enrolled servers.
 *
 * Responsibilities:
 *   - Register a new relay session when a server connects
 *   - Route an inbound HTTP request to the correct server via its relay session
 *   - Track bytes sent/received per session
 *   - Close a relay session when the server disconnects
 *
 * @package Phlix\Hub\Hub
 * @since 0.12.0
 */
class RelaySessionManager
{
    /**
     * @param Connection       $db     MySQL connection.
     * @param StructuredLogger $logger Application logger.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Register a new relay session for a connected server.
     *
     * @param string $serverId Hub-assigned server UUID.
     * @param string $workerNode Identifier of the Workerman worker handling this connection.
     *
     * @return string The relay session UUID.
     *
     * @throws InvalidArgumentException When server is not found (404).
     *
     * @since 0.12.0
     */
    public function registerServer(string $serverId, string $workerNode): string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM servers WHERE id = :id LIMIT 1',
            ['id' => $serverId],
        );

        if (empty($rows)) {
            throw new InvalidArgumentException('SERVER_NOT_FOUND');
        }

        $sessionId = $this->generateUuid();

        $this->db->query(
            'INSERT INTO relay_sessions (id, server_id, worker_node, opened_at, bytes_in, bytes_out)
             VALUES (:id, :server_id, :worker_node, NOW(), 0, 0)',
            [
                'id' => $sessionId,
                'server_id' => $serverId,
                'worker_node' => $workerNode,
            ],
        );

        $this->logger->info('Relay session registered', [
            'session_id' => $sessionId,
            'server_id' => $serverId,
            'worker_node' => $workerNode,
        ]);

        return $sessionId;
    }

    /**
     * Route an inbound HTTP request to the server via its relay session.
     *
     * Returns the relay session record if the server is connected, or null if no
     * active session exists for this server.
     *
     * @param string $serverId   The target server UUID.
     * @param string $method     HTTP method.
     * @param string $path       HTTP request path.
     * @param array<string, string> $headers HTTP headers.
     * @param string $body       HTTP request body.
     *
     * @return array<string, mixed>|null Relay session record or null if not connected.
     *
     * @since 0.12.0
     */
    public function routeRequest(
        string $serverId,
        string $method,
        string $path,
        array $headers,
        string $body,
    ): ?array {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT rs.*, s.status FROM relay_sessions rs
             JOIN servers s ON s.id = rs.server_id
             WHERE rs.server_id = :server_id AND rs.closed_at IS NULL
             LIMIT 1',
            ['server_id' => $serverId],
        );

        if (empty($rows)) {
            return null;
        }

        /** @var array<string, mixed> $session */
        $session = $rows[0];

        $bodyLen = strlen($body);
        $this->db->query(
            'UPDATE relay_sessions SET bytes_in = bytes_in + :bytes_in,
             last_frame_at = UNIX_TIMESTAMP() WHERE id = :id',
            [
                'bytes_in' => $bodyLen,
                'id' => $session['id'],
            ],
        );

        return $session;
    }

    /**
     * Record bytes sent to a server over its relay session.
     *
     * @param string $sessionId Relay session UUID.
     * @param int    $bytes     Number of bytes sent.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function recordBytesOut(string $sessionId, int $bytes): void
    {
        $this->db->query(
            'UPDATE relay_sessions SET bytes_out = bytes_out + :bytes,
             last_frame_at = UNIX_TIMESTAMP() WHERE id = :id',
            [
                'bytes' => $bytes,
                'id' => $sessionId,
            ],
        );
    }

    /**
     * Close a relay session.
     *
     * @param string $sessionId   Relay session UUID.
     * @param string $reason       Human-readable close reason.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function closeSession(string $sessionId, string $reason): void
    {
        $this->db->query(
            'UPDATE relay_sessions SET closed_at = NOW(), close_reason = :reason
             WHERE id = :id',
            [
                'reason' => $reason,
                'id' => $sessionId,
            ],
        );

        $this->logger->info('Relay session closed', [
            'session_id' => $sessionId,
            'reason' => $reason,
        ]);
    }

    /**
     * Get the active relay session for a server, if any.
     *
     * @param string $serverId Server UUID.
     *
     * @return array<string, mixed>|null Session record or null.
     *
     * @since 0.12.0
     */
    public function getActiveSession(string $serverId): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM relay_sessions
             WHERE server_id = :server_id AND closed_at IS NULL
             LIMIT 1',
            ['server_id' => $serverId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Generate a random UUID v4.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
