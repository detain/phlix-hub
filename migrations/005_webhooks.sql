-- migration: 005_webhooks
-- Creates the `webhooks` table — user-defined HTTP callbacks the hub
-- delivers when a subscribed event alias fires. `event_aliases_json` is
-- a JSON array of `phlex.*` aliases (see `Phlex\Shared\Plugin\EventNameMap`).
-- `secret` (HMAC) and `template_json` (handlebars body) are optional and
-- land formally in step L.1.

CREATE TABLE IF NOT EXISTS webhooks (
    id                   CHAR(36) NOT NULL,
    user_id              CHAR(36) NOT NULL,
    name                 VARCHAR(128) NOT NULL,
    target_url           VARCHAR(512) NOT NULL,
    secret               VARCHAR(255) DEFAULT NULL,
    event_aliases_json   TEXT NOT NULL,
    template_json        TEXT DEFAULT NULL,
    enabled              TINYINT(1) NOT NULL DEFAULT 1,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_delivery_at     DATETIME DEFAULT NULL,
    last_delivery_status VARCHAR(16) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_webhooks_user (user_id),
    CONSTRAINT fk_webhooks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
