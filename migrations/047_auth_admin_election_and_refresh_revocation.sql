-- migration: 047_auth_admin_election_and_refresh_revocation
-- Auth hardening: two small tables behind the audit fixes.
--
-- 1. `auth_signup_guard` — the first-admin ELECTION MUTEX (fixes the TOCTOU
--    where two concurrent registrations both read `countUsers() === 0` outside
--    the transaction and both self-elect as admin). The election is decided by
--    who wins the single-row INSERT on this table's PRIMARY KEY: exactly one
--    concurrent registrant can ever hold the row, and the duplicate-key check
--    serialises rival inserters until the winner commits. The winner is still
--    only promoted when no OTHER user exists at election time, so an upgraded
--    install (users present, guard row absent) never self-elects a newcomer.
--
-- 2. `auth_revoked_refresh_tokens` — the refresh-token REVOCATION registry the
--    logout endpoint always promised but never had (the openapi logout
--    description claims refresh-token invalidation). A logout that presents a
--    still-verifiable refresh JWT records its `jti` here; `AuthManager::refresh`
--    consults the registry before minting, so a logged-out token lineage
--    actually ends instead of rolling for the remaining 7-day TTL.
--
-- Rows are self-cleaning: a revoked row is dead weight once its token's own
-- `expires_at` has passed, and `RefreshTokenRevocationService::revoke()` prunes
-- stale rows on write (the OAuth/MCP stores get swept by IdleReaper; this
-- table is small enough to sweep itself and must not depend on the reaper
-- firing). The 1-day retention grace mirrors the OAuth stores' prune predicate.
--
-- Plain DDL only (no column/index `IF [NOT] EXISTS` — MariaDB-only syntax the
-- MySQL 8 deploy target rejects with a 1064). Idempotency comes from the
-- MigrationRunner tracking table, which applies each file exactly once.

CREATE TABLE IF NOT EXISTS auth_signup_guard (
    guard_key   CHAR(36) NOT NULL,
    user_id     CHAR(36) NOT NULL,
    elected_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (guard_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_revoked_refresh_tokens (
    jti         VARCHAR(64) NOT NULL,
    user_id     CHAR(36) NOT NULL,
    expires_at  DATETIME NOT NULL,
    revoked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (jti),
    KEY idx_auth_revoked_refresh_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
