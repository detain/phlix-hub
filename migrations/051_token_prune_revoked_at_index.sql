-- migration: 051_token_prune_revoked_at_index
--
-- Review hub Minor 42 (dba2 cluster). The two token pruners
-- (ClientRelayTokenService::pruneExpiredTokens(), McpTokenService::pruneExpiredTokens())
-- sweep
--
--     expires_at < NOW() - INTERVAL n DAY  OR  revoked_at IS NOT NULL
--
-- on every reaper tick. 032/044 indexed `expires_at` but left `revoked_at`
-- bare, so the revoked arm forced a full scan of BOTH tables per tick — and
-- every row the arm eventually deletes (revocations accumulate; the arm is the
-- one that prunes the bulk of long-lived rows) made the scan, not an index
-- range, do the work. The pruners are also being reworked into the two-phase
-- keyed sweep (the ServerReaper::sweepHeartbeats idiom: non-locking SELECT of
-- the PK batch, then DELETE ... WHERE id IN (...)); that pattern only stays
-- cheap if each arm is its own indexed range.
--
--     INDEX idx_client_relay_tokens_revoked_at (revoked_at)
--     INDEX idx_mcp_tokens_revoked_at          (revoked_at)
--
-- WHY A NULLABLE-TIMESTAMP INDEX WORKS FOR `IS NOT NULL`: InnoDB stores NULLs
-- in the B-tree, and `revoked_at IS NOT NULL` optimizes to the range
-- (MAX_NOT_NULL, +∞) — an index scan over exactly the revoked rows, no
-- back-to-table filter for the arm's own predicate. Active rows (NULL) sit in
-- a contiguous NULL region the range skips.
--
-- THE GUARD (048/050 idiom, per table): present-in-any-name → `SELECT 0`
-- no-op (covers every checksum re-apply and every healthy boot); missing →
-- ADD INDEX. Plain (non-unique) shape match on the single column.

-- ── client_relay_tokens ────────────────────────────────────────────────────
SET @phlix_crt_revoked_index = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'client_relay_tokens'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'revoked_at'
    ) phlix_crt_index_shapes
);

SET @phlix_crt_sql = IF(
    @phlix_crt_revoked_index > 0,
    'SELECT 0',
    'ALTER TABLE client_relay_tokens ADD INDEX idx_client_relay_tokens_revoked_at (revoked_at)'
);

PREPARE stmt FROM @phlix_crt_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── mcp_tokens ─────────────────────────────────────────────────────────────
SET @phlix_mcp_revoked_index = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'mcp_tokens'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'revoked_at'
    ) phlix_mcp_index_shapes
);

SET @phlix_mcp_sql = IF(
    @phlix_mcp_revoked_index > 0,
    'SELECT 0',
    'ALTER TABLE mcp_tokens ADD INDEX idx_mcp_tokens_revoked_at (revoked_at)'
);

PREPARE stmt FROM @phlix_mcp_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
