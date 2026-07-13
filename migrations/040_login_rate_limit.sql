-- migration: 040_login_rate_limit
-- HB-4.6 (Option B foundation): shared, DB-backed store for the LOGIN rate
-- limiter, replacing the per-worker in-memory RateLimiter for that one surface.
--
-- Why: the login limiter (5 / 900s) is enforced in AuthManager on the HTTP
-- workers, so with HUB_WORKERS > 1 each worker keeps its OWN in-memory bucket
-- and the effective brute-force budget is roughly max x HUB_WORKERS (e.g. about
-- 20 / 900 with 4 workers) instead of the intended 5 / 900. A single row per
-- bucket key in this table lets every worker share one counter.
--
-- Shape mirrors phlix-server's login_rate_limit (migration 074) but is keyed by
-- the hub RateLimiterInterface's opaque bucket key rather than a bare IP column,
-- so the forthcoming DbRateLimiter can back the generic peek/hit/reset contract:
--   - rate_key:   opaque limiter bucket key, e.g. auth:login:<ip> (natural PK)
--   - attempts:   failed attempts recorded in the current window
--   - reset_at:   Unix timestamp at which the window expires and the counter resets
--   - created_at: when the bucket row was first inserted
--
-- Idempotency comes from the MigrationRunner tracking table (each file applied
-- exactly once); CREATE TABLE IF NOT EXISTS keeps re-runs safe. InnoDB + utf8mb4
-- to match the rest of the schema. NOTE: this migration only creates the table.
-- The DbRateLimiter implementation and the RateLimitProfiles::LOGIN rebinding are
-- separate follow-up sub-steps; nothing reads this table yet.

CREATE TABLE IF NOT EXISTS login_rate_limit (
    rate_key    VARCHAR(191) NOT NULL COMMENT 'Opaque RateLimiterInterface bucket key, e.g. auth:login:<ip>',
    attempts    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failed attempts in the current window',
    reset_at    INT UNSIGNED NOT NULL COMMENT 'Unix timestamp when the window expires and the counter resets',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the bucket row was first inserted',
    PRIMARY KEY (rate_key),
    INDEX idx_login_rate_limit_reset_at (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
