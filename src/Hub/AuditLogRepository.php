<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Workerman\MySQL\Connection;

/**
 * Repository for persisting and querying audit log entries (Step H.5b).
 *
 * All audit events that flow through {@see \Phlix\Hub\Common\Logger\AuditLogger}
 * are also written to the `audit_logs` table via this repository. The
 * repository uses the async {@see \Workerman\MySQL\Connection} client with
 * named parameterised queries (no PDO/mysqli, no string interpolation).
 *
 * @package Phlix\Hub\Hub
 * @since   H.5b (Hub audit log infrastructure — DB-backed)
 */
class AuditLogRepository
{
    /**
     * @param Connection $db Async MySQL connection used for all queries.
     *
     * @since H.5b
     */
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Persist a single audit log entry.
     *
     * @param string      $event      Event type slug (e.g. "login", "logout").
     * @param string|null  $userId     Affected user UUID (null for system events).
     * @param string|null  $sessionId  Session ID if applicable.
     * @param string|null  $deviceId    Device/client identifier.
     * @param string|null  $resource   Resource id or path targeted by the action.
     * @param string|null  $action     Action performed (e.g. "request.approve").
     * @param bool         $success    Whether the operation succeeded.
     * @param string|null  $reason     Short machine-friendly reason tag.
     * @param string|null  $ipAddress  IPv4 or IPv6 client address.
     * @param string|null  $userAgent  Client User-Agent string.
     * @param array<string, mixed> $context Additional structured context.
     *
     * @since H.5b
     */
    public function log(
        string $event,
        ?string $userId = null,
        ?string $sessionId = null,
        ?string $deviceId = null,
        ?string $resource = null,
        ?string $action = null,
        bool $success = true,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $context = [],
    ): void {
        $id = $this->generateUuid();
        $contextJson = $context !== [] ? json_encode($context, JSON_THROW_ON_ERROR) : null;

        $this->db->query(
            'INSERT INTO audit_logs'
                . ' (id, event, user_id, session_id, device_id, resource, action,'
                . '  success, reason, ip_address, user_agent, context_json)'
                . ' VALUES (:id, :event, :userId, :sessionId, :deviceId, :resource,'
                . '         :action, :success, :reason, :ipAddress, :userAgent, :contextJson)',
            [
                ':id' => $id,
                ':event' => $event,
                ':userId' => $userId,
                ':sessionId' => $sessionId,
                ':deviceId' => $deviceId,
                ':resource' => $resource,
                ':action' => $action,
                ':success' => $success ? 1 : 0,
                ':reason' => $reason,
                ':ipAddress' => $ipAddress,
                ':userAgent' => $userAgent,
                ':contextJson' => $contextJson,
            ],
        );
    }

    /**
     * Query audit log entries with optional filters.
     *
     * @param array<string, mixed> $filters Supported keys:
     *   - event (string): Filter by event type.
     *   - user_id (string): Filter by user UUID.
     *   - resource (string): Filter by resource id/path.
     *   - action (string): Filter by action tag.
     *   - success (bool): Filter by success flag.
     *   - from (int): Unix timestamp — created_at >= from.
     *   - to (int): Unix timestamp — created_at <= to.
     *   - limit (int): Number of rows to return (1-200, default 50).
     *   - offset (int): Row offset for pagination (default 0).
     *
     * @return array{entries: list<array<string, mixed>>, total: int}
     *
     * @since H.5b
     */
    public function find(array $filters = []): array
    {
        $conditions = ['1=1'];
        $params = [];

        if (isset($filters['event']) && is_string($filters['event'])) {
            $conditions[] = 'event = :event';
            $params[':event'] = $filters['event'];
        }

        if (isset($filters['user_id']) && is_string($filters['user_id'])) {
            $conditions[] = 'user_id = :userId';
            $params[':userId'] = $filters['user_id'];
        }

        if (isset($filters['resource']) && is_string($filters['resource'])) {
            $conditions[] = 'resource = :resource';
            $params[':resource'] = $filters['resource'];
        }

        if (isset($filters['action']) && is_string($filters['action'])) {
            $conditions[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }

        if (isset($filters['success']) && is_bool($filters['success'])) {
            $conditions[] = 'success = :success';
            $params[':success'] = $filters['success'] ? 1 : 0;
        }

        if (isset($filters['from']) && is_numeric($filters['from'])) {
            $conditions[] = 'created_at >= FROM_UNIXTIME(:from)';
            $params[':from'] = (int) $filters['from'];
        }

        if (isset($filters['to']) && is_numeric($filters['to'])) {
            $conditions[] = 'created_at <= FROM_UNIXTIME(:to)';
            $params[':to'] = (int) $filters['to'];
        }

        $where = implode(' AND ', $conditions);
        /** @var mixed $limitRaw */
        $limitRaw = $filters['limit'] ?? null;
        $limit = is_numeric($limitRaw) ? max(1, min(200, (int) $limitRaw)) : 50;
        /** @var mixed $offsetRaw */
        $offsetRaw = $filters['offset'] ?? null;
        $offset = is_numeric($offsetRaw) ? max(0, (int) $offsetRaw) : 0;

        /** @var mixed $countRows */
        $countRows = $this->db->query(
            "SELECT COUNT(*) as cnt FROM audit_logs WHERE {$where}",
            $params,
        );
        $total = 0;
        if (is_array($countRows) && isset($countRows[0]) && is_array($countRows[0])) {
            /** @var mixed $cnt */
            $cnt = $countRows[0]['cnt'] ?? null;
            if (is_numeric($cnt)) {
                $total = (int) $cnt;
            }
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            "SELECT * FROM audit_logs WHERE {$where}"
                . " ORDER BY created_at DESC"
                . " LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        $entries = [];
        if (is_array($rows)) {
            /** @var mixed $row */
            foreach ($rows as $row) {
                if (is_array($row) && is_string(key($row))) {
                    /** @var array<string, mixed> $typedRow */
                    $typedRow = $row;
                    $entries[] = $this->rowToEntry($typedRow);
                }
            }
        }

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * Convert a database row to an entry array with proper type coercion.
     *
     * @param array<string, mixed> $row Raw database row.
     *
     * @return array<string, mixed> Entry array with typed fields.
     *
     * @since H.5b
     */
    private function rowToEntry(array $row): array
    {
        /** @var mixed $contextJson */
        $contextJson = $row['context_json'] ?? null;
        /** @var mixed $context */
        $context = null;
        if ($contextJson !== null && is_string($contextJson) && $contextJson !== '') {
            $context = json_decode($contextJson, true);
        }

        /** @var mixed $successRaw */
        $successRaw = $row['success'] ?? 1;
        $successInt = is_numeric($successRaw) ? (int) $successRaw : 1;

        return [
            'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
            'event' => is_string($row['event'] ?? null) ? $row['event'] : '',
            'user_id' => $row['user_id'] ?? null,
            'session_id' => $row['session_id'] ?? null,
            'device_id' => $row['device_id'] ?? null,
            'resource' => $row['resource'] ?? null,
            'action' => $row['action'] ?? null,
            'success' => $successInt === 1,
            'reason' => $row['reason'] ?? null,
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'context' => $context,
            'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : null,
        ];
    }

    /**
     * Generate a random UUID v4.
     *
     * Uses the inline sprintf pattern per the hub runtime rules (no UUID
     * library required).
     *
     * @return string Formatted UUID string.
     *
     * @since H.5b
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
