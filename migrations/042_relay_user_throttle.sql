-- migration: 042_relay_user_throttle
-- S41 (updates.md #50): operator-configurable per-user relay bandwidth THROTTLE
-- (a sustained rate cap in bits/sec), distinct from the monthly BYTE-CAP quota
-- (quota_bytes_in/out). Added to the existing per-user relay quota rollup,
-- mirroring the migration-038 max_concurrent_streams column exactly (same table,
-- same per-period rollup row). Default 3000000 bps (3 Mbps); 0 = Unlimited.
--
-- NOT enforced yet: S41 is schema + admin surface only. Actual rate limiting
-- (token buckets on the WS relay + HTTP proxy paths) lands in S42/S43. Existing
-- rows adopt the 3 Mbps default on ADD COLUMN, which is inert until enforcement.
--
-- Plain ADD COLUMN (no `IF NOT EXISTS` — that is MariaDB-only and the MySQL 8
-- deploy target rejects it with a 1064; the MigrationRunner tracking table is
-- what makes this file apply exactly once).

-- idempotency guard (review hub M4, 2026-11-02): the runner re-applies a file
-- whose checksum diverged (README *Database migrations*). A bare ADD would then raise
-- 1060/1061, the checksum would never refresh, and the chain would wedge. The probe
-- below skips the statement when its object already exists; fresh applies are
-- unaffected (probe returns 0 → the ALTER runs verbatim).
SET @phlix_guard_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'relay_user_quotas'
         AND COLUMN_NAME = 'throttle_bps') = 0,
    'ALTER TABLE relay_user_quotas ADD COLUMN throttle_bps BIGINT UNSIGNED NOT NULL DEFAULT 3000000',
    'SELECT 1');
PREPARE phlix_guard FROM @phlix_guard_sql;
EXECUTE phlix_guard;
DEALLOCATE PREPARE phlix_guard;
