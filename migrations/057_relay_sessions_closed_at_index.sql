-- migration: 057_relay_sessions_closed_at_index
--
-- HUB-S10 (round-2026-11-11, HUB-1). `relay_sessions` keeps one row per relay
-- tunnel the hub ever held, and nothing prunes it: every closed session stays
-- behind as byte-accounting history (bytes_in/bytes_out/close_reason). The
-- open set — rows with `closed_at IS NULL` — is at most one per server; the
-- closed history grows without bound.
--
-- 004 indexed the open set only BEHIND server_id:
--
--     KEY ix_relay_sessions_open (server_id, closed_at)
--
-- which serves the per-server probes (`server_id = ? AND closed_at IS NULL`:
-- RelaySessionManager::openSession()/getActiveSession(), the ServerInfoHandler
-- and LibrarySharingHandler correlated EXISTS/LEFT JOIN) but gives the
-- server-agnostic open-set predicates no leading column to range on. Each of
-- these therefore reads the WHOLE table — open rows and every closed row ever
-- written — on every call:
--
--     StatsRepository::dashboardCounts()           SELECT COUNT(*) ... WHERE closed_at IS NULL
--     ServerReaper::markStaleServersOffline()      ... NOT IN (SELECT DISTINCT server_id ... WHERE closed_at IS NULL)
--     RelaySessionManager::reapStaleSessions()     UPDATE ... WHERE closed_at IS NULL AND <idle>
--     RelaySessionManager::closeOrphanedSessions() UPDATE ... WHERE closed_at IS NULL [AND server_id NOT IN (...)]
--
-- The reaper/stale-sweep arms run on timer ticks, so the scan cost scales with
-- the history, not with the handful of live tunnels the predicate selects —
-- and the two UPDATEs take their row locks while they walk it.
--
--     INDEX idx_relay_sessions_closed_at (closed_at)
--
-- WHY A NULLABLE-DATETIME INDEX SERVES `IS NULL`: InnoDB stores NULLs in the
-- B-tree, and `closed_at IS NULL` is an equality-class `ref` on the NULL key
-- — an index read of exactly the open rows (one per live server), with the
-- closed history sitting in the non-NULL region the lookup never visits. The
-- secondary entry carries only closed_at + the PK (id): the smallest index that
-- removes the scan. The open set is tiny, so the back-to-table hops the
-- DISTINCT server_id / idle-arm reads still make are bounded by live tunnels,
-- not history — a wider covering (closed_at, server_id) key would charge 36+
-- more bytes per HISTORY row to save a handful of open-row lookups.
--
-- ONLINE DDL: ADD INDEX on InnoDB (MySQL 8) is INPLACE with concurrent DML
-- permitted, so the apply does not stall live relay accounting writes. Under
-- the README least-privilege grant set (SELECT, INSERT, UPDATE, DELETE, CREATE,
-- ALTER, INDEX, REFERENCES — no DROP, no SUPER) the ALTER TABLE ... ADD INDEX
-- rides ALTER, and the information_schema.STATISTICS probe needs only the
-- table-level visibility those grants already confer.
--
-- OUT OF SCOPE HERE: retention / archiving / partitioning of the closed
-- byte-accounting history is an owner policy decision, not a schema fix; this
-- file only makes the open-set predicates independent of how much history
-- accumulates.
--
-- THE GUARD (051 idiom): present under any name with exactly this single-column
-- shape → `SELECT 0` no-op (covers every checksum re-apply and every healthy
-- boot); missing → ADD INDEX. The shape probe is single-column on purpose:
-- 004's (server_id, closed_at) concatenates to 'closed_at,server_id' and must
-- NOT satisfy it — that index cannot range the server-agnostic predicate.
-- Plain DDL otherwise (AGENTS law: never IF [NOT] EXISTS on an index).

SET @phlix_rs_closed_index = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'relay_sessions'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'closed_at'
    ) phlix_rs_index_shapes
);

SET @phlix_rs_closed_sql = IF(
    @phlix_rs_closed_index > 0,
    'SELECT 0',
    'ALTER TABLE relay_sessions ADD INDEX idx_relay_sessions_closed_at (closed_at)'
);

PREPARE stmt FROM @phlix_rs_closed_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
