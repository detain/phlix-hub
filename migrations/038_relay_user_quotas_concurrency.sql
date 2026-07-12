-- migration: 038_relay_user_quotas_concurrency
-- HB-3.4: operator-configurable per-user concurrent-stream cap.
-- Added to the existing per-user relay quota rollup. 0 = unlimited (default),
-- so every existing row keeps its prior "no cap" behaviour after the ALTER.
--
-- Plain ADD COLUMN (no `IF NOT EXISTS` — that is MariaDB-only and the MySQL 8
-- deploy target rejects it with 1064; the MigrationRunner tracking table is what
-- makes this file apply exactly once).

ALTER TABLE relay_user_quotas
    ADD COLUMN max_concurrent_streams INT UNSIGNED NOT NULL DEFAULT 0;
