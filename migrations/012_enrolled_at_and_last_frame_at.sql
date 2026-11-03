-- migration: 012_enrolled_at_and_last_frame_at
--
-- Adds two columns that were referenced by application code but never
-- created by an earlier migration, so a fresh DB could not complete
-- a server claim or record relay activity:
--
--   - `servers.enrolled_at` — INT UNSIGNED unix timestamp written by
--     `Phlix\Hub\Hub\ClaimRequestHandler::handle()` when a paired
--     claim is promoted into a `servers` row. Earlier rows are
--     backfilled from `created_at` so dashboards and any
--     `enrolled_at`-keyed queries keep working.
--
--   - `relay_sessions.last_frame_at` — INT UNSIGNED unix timestamp
--     updated by `Phlix\Hub\Hub\RelaySessionManager::recordBytesIn()`
--     / `recordBytesOut()` every time a frame flows over a relay
--     tunnel. Nullable because sessions that opened before this
--     migration have no activity data; the application treats NULL
--     as "no frames yet".
--
-- Idempotency is provided by the MigrationRunner tracking table; plain
-- `ADD COLUMN` keeps the SQL portable across MySQL 8 and MariaDB.

-- idempotency guard (review hub M4, 2026-11-02): the runner re-applies a file
-- whose checksum diverged (README *Database migrations*). A bare ADD would then raise
-- 1060/1061, the checksum would never refresh, and the chain would wedge. The probe
-- below skips the statement when its object already exists; fresh applies are
-- unaffected (probe returns 0 → the ALTER runs verbatim).
SET @phlix_guard_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'servers'
         AND COLUMN_NAME = 'enrolled_at') = 0,
    'ALTER TABLE servers ADD COLUMN enrolled_at INT UNSIGNED NULL AFTER last_seen_at',
    'SELECT 1');
PREPARE phlix_guard FROM @phlix_guard_sql;
EXECUTE phlix_guard;
DEALLOCATE PREPARE phlix_guard;

UPDATE servers
   SET enrolled_at = UNIX_TIMESTAMP(created_at)
 WHERE enrolled_at IS NULL;

-- idempotency guard (review hub M4, 2026-11-02): the runner re-applies a file
-- whose checksum diverged (README *Database migrations*). A bare ADD would then raise
-- 1060/1061, the checksum would never refresh, and the chain would wedge. The probe
-- below skips the statement when its object already exists; fresh applies are
-- unaffected (probe returns 0 → the ALTER runs verbatim).
SET @phlix_guard_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'relay_sessions'
         AND COLUMN_NAME = 'last_frame_at') = 0,
    'ALTER TABLE relay_sessions ADD COLUMN last_frame_at INT UNSIGNED NULL AFTER bytes_out',
    'SELECT 1');
PREPARE phlix_guard FROM @phlix_guard_sql;
EXECUTE phlix_guard;
DEALLOCATE PREPARE phlix_guard;
