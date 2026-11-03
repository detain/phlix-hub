-- migration: 038_relay_user_quotas_concurrency
-- HB-3.4: operator-configurable per-user concurrent-stream cap.
-- Added to the existing per-user relay quota rollup. 0 = unlimited (default),
-- so every existing row keeps its prior "no cap" behaviour after the ALTER.
--
-- Plain ADD COLUMN (no `IF NOT EXISTS` — that is MariaDB-only and the MySQL 8
-- deploy target rejects it with 1064; the MigrationRunner tracking table is what
-- makes this file apply exactly once).

-- idempotency guard (review hub M4, 2026-11-02): the runner re-applies a file
-- whose checksum diverged (README *Database migrations*). A bare ADD would then raise
-- 1060/1061, the checksum would never refresh, and the chain would wedge. The probe
-- below skips the statement when its object already exists; fresh applies are
-- unaffected (probe returns 0 → the ALTER runs verbatim).
SET @phlix_guard_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'relay_user_quotas'
         AND COLUMN_NAME = 'max_concurrent_streams') = 0,
    'ALTER TABLE relay_user_quotas ADD COLUMN max_concurrent_streams INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE phlix_guard FROM @phlix_guard_sql;
EXECUTE phlix_guard;
DEALLOCATE PREPARE phlix_guard;
