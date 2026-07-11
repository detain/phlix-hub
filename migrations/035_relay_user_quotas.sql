-- migration: 035_relay_user_quotas
-- HB-3.4: Per-user relay bandwidth quotas.
-- Tracks usage per calendar month; quotas are optional (0 = unlimited).

CREATE TABLE IF NOT EXISTS relay_user_quotas (
    user_id          CHAR(36) NOT NULL,
    period_start     DATE NOT NULL,
    bytes_in         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    quota_bytes_in   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    quota_bytes_out  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key for looking up current period quota. ix_relay_user_quotas_user is
-- not redundant: the PK is (user_id, period_start); a query with only
-- user_id = ? cannot use the PK efficiently (it would need to scan all
-- periods), so the secondary index on user_id alone is useful.
KEY ix_relay_user_quotas_user (user_id);
