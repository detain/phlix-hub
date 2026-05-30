-- migration: 027_hub_settings
-- Creates the `hub_settings` table for hub-wide typed key/value settings.
-- Mirrors server migration 026_server_settings.sql.

CREATE TABLE IF NOT EXISTS hub_settings (
    id               CHAR(36) NOT NULL,
    setting_key      VARCHAR(191) NOT NULL,
    setting_value    TEXT NOT NULL,
    value_type       ENUM('string', 'int', 'bool', 'float', 'json') NOT NULL DEFAULT 'string',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hub_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
