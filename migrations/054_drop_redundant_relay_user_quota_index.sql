-- migration: 054_drop_redundant_relay_user_quota_index
--
-- SH-054 (review hub__dba-code #4). `relay_user_quotas` carries
-- KEY ix_relay_user_quotas_user (user_id) alongside PRIMARY KEY
-- (user_id, period_start). 035's inline comment claims the secondary key is
-- "not redundant" because "a query with only user_id = ? cannot range-scan
-- the PK efficiently". That is the leftmost-prefix myth inverted: MySQL can
-- use ANY left prefix of a composite index, so `user_id = ?` is exactly the
-- PK's leading-column range — a range scan over the PK's user_id span (which
-- is also the table's clustered ordering, so the rollup reads cost nothing
-- extra). The secondary key holds no information the PK does not already
-- sort by.
--
-- WHAT IT COSTS TO KEEP: every statement on the hot path — the per-flow
-- accounting upsert in RelaySessionManager (INSERT .. ON DUPLICATE KEY UPDATE
-- on the PK) — also maintains a second, full copy of the 36-byte user_id key
-- plus its PK pointer: extra page writes, extra change-buffer pressure, extra
-- gap locks on the secondary during concurrent upserts for the same user. It
-- buys zero read performance in return.
--
-- WHY A NEW FILE RATHER THAN FIXING 035: migrations already applied elsewhere
-- are immutable under the checksum re-apply law (041) — editing 035 would
-- diverge every healthy DB's recorded checksum. 035's comment stays as
-- authored history; this file is the correction, and its guard makes the
-- world safe in both directions: index present (any name — shape probe per
-- S161) → DROP INDEX under its real name; already gone (fresh installs land
-- here after the first pass, and every checksum re-apply after that) →
-- `SELECT 0` no-op.
--
-- The probe is NON_UNIQUE = 1 on purpose: the PK's shape concatenation is
-- never the bare string 'user_id' anyway (it covers two columns), but the
-- filter makes it structurally impossible for this file to aim a DROP at the
-- primary key. InnoDB DROP INDEX is INPLACE/metadata-only on MySQL 8, so the
-- statement does not rebuild the table under relay load.

SET @phlix_rqu_redundant = (
    SELECT MIN(INDEX_NAME)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'relay_user_quotas'
    AND NON_UNIQUE = 1
    GROUP BY INDEX_NAME
    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'user_id'
    LIMIT 1
);

SET @phlix_rqu_sql = IF(
    @phlix_rqu_redundant IS NULL,
    'SELECT 0',
    CONCAT('ALTER TABLE relay_user_quotas DROP INDEX `', @phlix_rqu_redundant, '`')
);

PREPARE stmt FROM @phlix_rqu_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
