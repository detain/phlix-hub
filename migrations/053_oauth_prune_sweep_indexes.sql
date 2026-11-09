-- migration: 053_oauth_prune_sweep_indexes
--
-- SH-053 (review hub__dba-code #3). Four credential pruners that still issued
-- one unbounded wide-predicate DELETE per reaper tick are being reworked into
-- the two-phase keyed sweep (KeySweeper: non-locking SELECT of the PK batch,
-- then DELETE ... WHERE <pk> IN (...)) — the same cure 051 prepared for the
-- relay/MCP siblings. That pattern only stays cheap if each arm is its own
-- indexed range; two of the OAuth arms had no index to range over:
--
--     INDEX idx_oauth_tokens_revoked_at               (revoked_at)
--     INDEX idx_oauth_consent_requests_consumed_at    (consumed_at)
--
-- `oauth_tokens`: the prune's second arm is
-- `revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 1 DAY` — unlike
-- the MCP sibling's bare `IS NOT NULL` arm it carries a timestamp comparison,
-- so a tight range needs the column indexed outright (045 left it bare).
-- `oauth_consent_requests`: the consumed arm is the MCP shape
-- (`consumed_at IS NOT NULL`); 045 indexed `expires_at` only, so every tick
-- full-scanned the table to find tickets the redirect flow had already burned.
--
-- WHY A NULLABLE-TIMESTAMP INDEX WORKS FOR `IS NOT NULL`: recorded in 051 —
-- InnoDB stores NULLs in the B-tree, `IS NOT NULL` optimizes to the range
-- (MAX_NOT_NULL, +∞), active rows sit in a contiguous NULL region the scan
-- skips.
--
-- THE GUARD (048/050 idiom, per table): present-in-any-name → `SELECT 0`
-- no-op (covers every checksum re-apply and every healthy boot); missing →
-- ADD INDEX. Plain (non-unique) shape match on the single column.

-- ── oauth_tokens ───────────────────────────────────────────────────────────
SET @phlix_oauth_revoked_index = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'oauth_tokens'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'revoked_at'
    ) phlix_oauth_index_shapes
);

SET @phlix_oauth_sql = IF(
    @phlix_oauth_revoked_index > 0,
    'SELECT 0',
    'ALTER TABLE oauth_tokens ADD INDEX idx_oauth_tokens_revoked_at (revoked_at)'
);

PREPARE stmt FROM @phlix_oauth_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── oauth_consent_requests ─────────────────────────────────────────────────
SET @phlix_consent_consumed_index = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'oauth_consent_requests'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'consumed_at'
    ) phlix_consent_index_shapes
);

SET @phlix_consent_sql = IF(
    @phlix_consent_consumed_index > 0,
    'SELECT 0',
    'ALTER TABLE oauth_consent_requests ADD INDEX idx_oauth_consent_requests_consumed_at (consumed_at)'
);

PREPARE stmt FROM @phlix_consent_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
