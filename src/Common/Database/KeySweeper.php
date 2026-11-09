<?php

/**
 * Phlix hub component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Database;

use PDOException;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Two-phase, indexed, PK-keyed batch delete — the ServerReaper heartbeat-sweep
 * idiom promoted to a shared component.
 *
 * Review hub Minor 42: the token pruners were bare
 * `DELETE ... WHERE <wide predicate>` statements — no bound on the lock range
 * (InnoDB next-key-locks the whole swept span of the `expires_at` index and
 * fights concurrent INSERTs) and the `revoked_at IS NOT NULL` arm had no
 * index at all (migration 051 adds both). The cure is the shape this class
 * enforces:
 *
 *  1. a plain non-locking `SELECT <pk> … WHERE <arm> ORDER BY <pk> LIMIT n`
 *     (takes no row/gap locks; an index range scan keeps it tight), then
 *  2. `DELETE … WHERE <pk> IN (:id_0, …)` — locks exactly the named PRIMARY
 *     KEY rows, acquired in PK order.
 *
 * `<pk>` defaults to `id` and is constructor-validated like the table name;
 * a natural-key table (the `jti`-keyed refresh-revocation registry) passes
 * its real PK column rather than staying outside the law (SH-053).
 *
 * A deadlocked sweep is a pure loss (nothing was committed), so the whole
 * attempt retries — three tries per {@see self::MAX_ATTEMPTS} call, matching
 * the reaper's backstop — and any other PDO failure propagates untouched.
 *
 * Identifiers (table name, batch size) are constructor-validated, never
 * request-derived: the $whereSql the caller passes is a code constant.
 *
 * @package Phlix\Hub\Common\Database
 */
final class KeySweeper
{
    /**
     * Deadlock retries per sweep. A deadlock victim's transaction rolled back,
     * so re-running the two phases is safe; three bounded tries mirror
     * {@see \Phlix\Hub\ServerReaper::sweepHeartbeats()} rather than inventing
     * a second number in the family.
     */
    private const int MAX_ATTEMPTS = 3;

    /**
     * SQL identifier law: lower-case snake table names only. This rejects
     * injection-shaped strings at the boundary where they are CODE, so the
     * concatenation below can never receive anything but a literal from a
     * call site (fail-fast, not parse-later).
     */
    private const string TABLE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param Connection $db       MySQL connection (worker-pool borrow semantics unchanged).
     * @param string     $table    Swept table, SQL identifier form.
     * @param int        $batchSize Upper bound of PK rows one sweep may delete.
     * @param string     $pkColumn Name of the PRIMARY KEY column the sweep keys
     *        on (SQL identifier form). `id` is the hub-schema default; a
     *        natural-key table names its real PK instead — the
     *        `auth_revoked_refresh_tokens` registry is keyed by `jti`
     *        (migration 047) and SH-053 routes it through this same
     *        two-phase shape rather than leaving it a wide-predicate DELETE.
     *
     * @throws RuntimeException On a non-identifier table or PK column name, or
     *         a batch size below 1.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly string $table,
        private readonly int $batchSize,
        private readonly string $pkColumn = 'id',
    ) {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new RuntimeException(
                "KeySweeper: table must match " . self::TABLE_PATTERN . " (a code literal, not input), got: {$table}"
            );
        }
        if (preg_match(self::TABLE_PATTERN, $pkColumn) !== 1) {
            throw new RuntimeException(
                "KeySweeper: pk column must match " . self::TABLE_PATTERN
                    . " (a code literal, not input), got: {$pkColumn}"
            );
        }
        if ($batchSize < 1) {
            throw new RuntimeException("KeySweeper: batchSize must be >= 1, got: {$batchSize}");
        }
    }

    /**
     * Delete up to one batch of rows matching $whereSql, by primary key.
     *
     * @param string               $whereSql WHERE body without the keyword, code constant.
     * @param array<string, mixed> $params   Named placeholders for $whereSql.
     *
     * @return int Number of rows deleted (0 when nothing matched).
     */
    public function sweep(string $whereSql, array $params = []): int
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $ids = $this->selectBatchIds($whereSql, $params);

                if ($ids === []) {
                    return 0;
                }

                return $this->deleteByIds($ids);
            } catch (PDOException $e) {
                $isDeadlock = stripos($e->getMessage(), 'deadlock') !== false;
                if (!$isDeadlock || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }
                // Retry: the victim's work never committed, the phase-1 SELECT
                // re-reads a fresh snapshot, and PK order re-acquires the locks.
            }
        }

        // Unreachable: the loop returns or throws on every path.
        throw new RuntimeException('KeySweeper: unreachable state after retry loop');
    }

    /**
     * Phase 1 — non-locking PK read, PK-ordered so phase 2 locks in order.
     *
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function selectBatchIds(string $whereSql, array $params): array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT ' . $this->pkColumn . ' FROM ' . $this->table .
            ' WHERE ' . $whereSql .
            ' ORDER BY ' . $this->pkColumn . ' LIMIT ' . $this->batchSize,
            $params,
        );

        $ids = [];
        if (is_array($rows)) {
            /** @var list<array<string, mixed>> $rows */
            foreach ($rows as $row) {
                /** @var mixed $rawId */
                $rawId = $row[$this->pkColumn] ?? null;
                if (is_string($rawId) && $rawId !== '') {
                    $ids[] = $rawId;
                }
            }
        }

        return $ids;
    }

    /**
     * Phase 2 — delete exactly those rows by PRIMARY KEY. Colon-free named
     * placeholders (workerman/mysql prepends `:`); the placeholder labels
     * themselves stay `id_<n>` — they are anonymous bind names, not schema
     * identifiers.
     *
     * @param list<string> $ids
     */
    private function deleteByIds(array $ids): int
    {
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        /** @var mixed $result */
        $result = $this->db->query(
            'DELETE FROM ' . $this->table . ' WHERE ' . $this->pkColumn . ' IN (' . implode(', ', $placeholders) . ')',
            $params,
        );

        return is_numeric($result) ? (int) $result : 0;
    }
}
