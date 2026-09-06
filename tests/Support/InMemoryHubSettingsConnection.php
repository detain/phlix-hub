<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Workerman\MySQL\Connection;

/**
 * Socket-free {@see Connection} double that emulates the `hub_settings` table
 * well enough for {@see \Phlix\Hub\Hub\HubSettingsRepository} to round-trip.
 *
 * WHY A ROUND-TRIPPING DOUBLE, NOT A `willReturn()` STUB — the S75 acceptance
 * criterion is that the STATUS ENDPOINT reports "update available" after a
 * newer marker is seen. That is a two-hop claim: the check WRITES and the
 * endpoint READS. A stub that simply returns a canned SELECT result would let
 * a service that never persisted anything still read green. This double stores
 * what the repository writes and serves it back to what the repository reads,
 * so the write and the read have to agree.
 *
 * It understands exactly the three statements the repository issues:
 *   - `INSERT INTO hub_settings ... ON DUPLICATE KEY UPDATE`  (upsert)
 *   - `SELECT setting_value, value_type FROM hub_settings WHERE setting_key = ?`
 *   - `SELECT setting_key, setting_value, value_type FROM hub_settings`
 * Anything else fails loudly rather than silently returning `[]`.
 *
 * @package Phlix\Hub\Tests\Support
 */
final class InMemoryHubSettingsConnection extends Connection
{
    /** @var array<string, array{setting_key: string, setting_value: string, value_type: string}> */
    public array $rows = [];

    /** @var list<string> Ordered log of statements, for assertions about I/O shape. */
    public array $statements = [];

    /**
     * Clear the statement log so the next assertion measures only the I/O of
     * the call under test. (A method, not a literal `= []` at the call site, so
     * static analysis keeps the declared list<string> shape for later reads.)
     */
    public function resetStatementLog(): void
    {
        $this->statements = [];
    }

    /**
     * @psalm-suppress MissingParentConstructorCall Intentional: never open a socket.
     */
    public function __construct()
    {
        // Deliberately NOT calling parent::__construct() — no socket in tests.
    }

    /**
     * @param string                        $query
     * @param array<int|string, mixed>|null $params
     * @param int                           $fetchmode
     *
     * @return mixed
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        $this->statements[] = $query;
        $args = is_array($params) ? array_values($params) : [];

        if (str_contains($query, 'INSERT INTO hub_settings')) {
            $key = isset($args[1]) && is_scalar($args[1]) ? (string) $args[1] : '';
            $this->rows[$key] = [
                'setting_key'   => $key,
                'setting_value' => isset($args[2]) && is_scalar($args[2]) ? (string) $args[2] : '',
                'value_type'    => isset($args[3]) && is_scalar($args[3]) ? (string) $args[3] : 'string',
            ];

            return true;
        }

        if (str_contains($query, 'WHERE setting_key')) {
            $key = isset($args[0]) && is_scalar($args[0]) ? (string) $args[0] : '';

            return isset($this->rows[$key]) ? [$this->rows[$key]] : [];
        }

        if (str_contains($query, 'SELECT setting_key')) {
            return array_values($this->rows);
        }

        throw new \RuntimeException('InMemoryHubSettingsConnection: unexpected statement: ' . $query);
    }
}
