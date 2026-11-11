-- migration: 056_auth_refresh_grants_expiry
-- D1a cure twin, hub limb 1: per-row expiry for auth_refresh_grants.
--
-- 055 shipped the theft sensor without a lifetime column, so consumed grants
-- accumulated forever: the only removal path was the session FK CASCADE, and
-- a healthy rotating family never triggers it. Every sibling credential store
-- in this schema carries its own expiry and self-prunes against it
-- (auth_revoked_refresh_tokens 047, server_claims, the OAuth/MCP/relay token
-- tables). This migration completes that shape for the grants table.
--
-- expires_at is the OWN expiry of the minted refresh JWT - its baked `exp`
-- claim, handed to recordGrant by the mint path - so the sweep horizon is a
-- per-row fact, never a guess from whatever TTL is configured today. Rows
-- minted before this column existed keep NULL; the store's grandfather arm
-- collects them only after a horizon far beyond any credible refresh TTL, so
-- no live token can ever have its sensor row deleted early.
--
-- Consumed rows are NOT the sweep key: a consumed grant stays load-bearing
-- until the token itself expires (a replayed stolen token must reach the
-- reuse arm, not the unknown-grant arm). Keying the prune on consumed_at
-- would silently degrade the theft detection D-1a exists for.
--
-- Replay-safe per the 036/051 guard idiom: each statement decides its own
-- object - a COLUMNS probe for the column, a STATISTICS shape probe for the
-- index; present objects skip, fresh applies run verbatim. Plain DDL
-- otherwise (AGENTS law: never IF [NOT] EXISTS on columns or indexes).
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MPL-2.0

SET @phlix_guard_grants_expiry = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'auth_refresh_grants'
         AND COLUMN_NAME = 'expires_at') = 0,
    'ALTER TABLE auth_refresh_grants ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL',
    'SELECT 0');
PREPARE phlix_guard FROM @phlix_guard_grants_expiry;
EXECUTE phlix_guard;
DEALLOCATE PREPARE phlix_guard;

SET @phlix_guard_grants_index = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'auth_refresh_grants'
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'expires_at'
    ) phlix_arg_index_shapes
);
SET @phlix_guard_grants_index_sql = IF(
    @phlix_guard_grants_index > 0,
    'SELECT 0',
    'ALTER TABLE auth_refresh_grants ADD INDEX idx_arg_expires_at (expires_at)'
);
PREPARE phlix_guard_index FROM @phlix_guard_grants_index_sql;
EXECUTE phlix_guard_index;
DEALLOCATE PREPARE phlix_guard_index;
