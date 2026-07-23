-- migration: 043_relay_user_settings
-- S42 Fixer (review finding, MEDIUM): the operator-configured per-user relay
-- THROTTLE (updates.md #50) was stored on relay_user_quotas — the per-CALENDAR-
-- MONTH usage-rollup row keyed (user_id, period_start) — and read for the CURRENT
-- month only. On the 1st of each month no row exists yet for the new period, so
-- the throttle read fell back to the 3 Mbps default and silently reverted every
-- admin-configured value (Unlimited / high caps) until an admin re-applied it.
-- S42 enforces the throttle on the live relay path, so that monthly revert now
-- throttles real users to 3 Mbps every month.
--
-- A per-user admin SETTING must be durable, not usage-period-scoped. Move the
-- throttle to a dedicated per-user settings table keyed by user_id ALONE. The
-- monthly BYTE-CAP quota (bytes_in/out, quota_bytes_in/out) and the concurrent-
-- stream cap stay period-scoped on relay_user_quotas and are untouched here; the
-- relay_user_quotas.throttle_bps column (migration 042) is left in place but is
-- no longer read for throttle after this migration.
--
-- Plain DDL only (no column/index `IF [NOT] EXISTS` — that is MariaDB-only and
-- the MySQL 8 deploy target rejects it with a 1064). Idempotency comes from the
-- MigrationRunner tracking table, which applies each file exactly once.

CREATE TABLE IF NOT EXISTS relay_user_settings (
    user_id      CHAR(36) NOT NULL,
    throttle_bps BIGINT UNSIGNED NOT NULL DEFAULT 3000000,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data migration: preserve each user's existing admin-set throttle so nobody's
-- current setting is lost. Copy the MOST-RECENT NON-DEFAULT throttle_bps from the
-- period-scoped rollup, skipping the 3 Mbps auto-created default rows so a fresh-
-- period default row cannot mask an earlier admin-set value. A stored 0 means
-- Unlimited: it is non-default and IS carried across. No-op on a fresh database
-- where relay_user_quotas is empty. Re-run-safe via ON DUPLICATE KEY UPDATE.
INSERT INTO relay_user_settings (user_id, throttle_bps)
SELECT q.user_id, q.throttle_bps
  FROM relay_user_quotas AS q
  INNER JOIN (
        SELECT user_id, MAX(period_start) AS max_period
          FROM relay_user_quotas
         WHERE throttle_bps <> 3000000
      GROUP BY user_id
  ) AS latest
    ON q.user_id = latest.user_id
   AND q.period_start = latest.max_period
ON DUPLICATE KEY UPDATE throttle_bps = VALUES(throttle_bps);
