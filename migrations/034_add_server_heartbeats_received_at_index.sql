-- migration: 034_add_server_heartbeats_received_at_index
-- HB-2.6: Add index on server_heartbeats.received_at for retention DELETE queries.
--
-- The existing index ix_server_heartbeats_server_time (server_id, received_at)
-- has server_id as its leading column, so a plain WHERE received_at < ...
-- filter cannot use it as a range scan and falls back to a full table scan.
-- The sweepHeartbeats() DELETE in ServerReaper only filters on received_at,
-- so a dedicated index on received_at alone fixes this.
--
-- Idempotency: CORRECTED 2026-11-02 (review hub M4). This paragraph predated
-- migration 041_migrations_checksum: the runner now records a checksum per file
-- and RE-APPLIES a file whose recorded checksum diverged from disk (README
-- *Database migrations*), so a plain CREATE INDEX is NOT sufficient — the 048
-- existence-guard idiom below makes the re-apply a no-op. (MySQL 8 does not
-- support `CREATE INDEX IF NOT EXISTS` — that is MariaDB-only.)

-- idempotency guard (review hub M4, 2026-11-02): the runner re-applies a file
-- whose checksum diverged (README *Database migrations*). A bare ADD would then raise
-- 1060/1061, the checksum would never refresh, and the chain would wedge. The probe
-- below skips the statement when its object already exists; fresh applies are
-- unaffected (probe returns 0 → the ALTER runs verbatim).
SET @phlix_guard_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'server_heartbeats'
         AND INDEX_NAME = 'idx_server_heartbeats_received_at') = 0,
    'CREATE INDEX idx_server_heartbeats_received_at ON server_heartbeats (received_at)',
    'SELECT 1');
PREPARE phlix_guard FROM @phlix_guard_sql;
EXECUTE phlix_guard;
DEALLOCATE PREPARE phlix_guard;
