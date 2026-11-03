-- migration: 039_relay_cancel_metric
-- Step H-W4 HB-4.9: cancel-to-stop relay metric
-- Adds a cancel counter column to the existing metrics_rollup table:
--   - relay_cancels: cumulative count of HTTP_CANCEL frames the hub emitted to a
--     server (client abandoned an in-flight proxied request; pairs with the
--     shared HTTP_CANCEL=0x12 contract and server-side SV-4.2 stop-work half).
--
-- Idempotency comes from the `migrations` tracking table (the runner applies
-- each file once); MySQL 8 does not support `ADD COLUMN IF NOT EXISTS`, so use
-- plain ADD COLUMN. InnoDB + utf8mb4_unicode_ci to match the rest of the schema.

-- idempotency guard (review hub M4, 2026-11-02): the runner re-applies a file
-- whose checksum diverged (README *Database migrations*). A bare ADD would then raise
-- 1060/1061, the checksum would never refresh, and the chain would wedge. The probe
-- below skips the statement when its object already exists; fresh applies are
-- unaffected (probe returns 0 → the ALTER runs verbatim).
SET @phlix_guard_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'metrics_rollup'
         AND COLUMN_NAME = 'relay_cancels') = 0,
    'ALTER TABLE metrics_rollup ADD COLUMN relay_cancels INT NOT NULL DEFAULT 0 AFTER relay_decode_buffer_bytes',
    'SELECT 1');
PREPARE phlix_guard FROM @phlix_guard_sql;
EXECUTE phlix_guard;
DEALLOCATE PREPARE phlix_guard;
