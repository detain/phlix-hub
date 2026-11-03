-- migration: 006_server_heartbeats_sent_at
-- Adds `sent_at` to `server_heartbeats` so the hub can persist the
-- server-side send timestamp (HeartbeatDto::$timestamp) alongside the
-- hub-side `received_at`. The pair powers clock-skew detection in the
-- heartbeat handler.
--
-- `sent_at` is nullable because rows written before this migration
-- (and any heartbeat where the sender omits the field) carry no
-- server timestamp.
--
-- Idempotency is provided by the MigrationRunner tracking table; plain
-- `ALTER` keeps the SQL portable across MySQL 8 and MariaDB.

-- idempotency guard (review hub M4, 2026-11-02): the runner re-applies a file
-- whose checksum diverged (README *Database migrations*). A bare ADD would then raise
-- 1060/1061, the checksum would never refresh, and the chain would wedge. The probe
-- below skips the statement when its object already exists; fresh applies are
-- unaffected (probe returns 0 → the ALTER runs verbatim).
SET @phlix_guard_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'server_heartbeats'
         AND COLUMN_NAME = 'sent_at') = 0,
    'ALTER TABLE server_heartbeats ADD COLUMN sent_at DATETIME NULL AFTER received_at',
    'SELECT 1');
PREPARE phlix_guard FROM @phlix_guard_sql;
EXECUTE phlix_guard;
DEALLOCATE PREPARE phlix_guard;
