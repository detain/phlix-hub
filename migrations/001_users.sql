-- migration: 001_users
-- Creates the `users` table — the authoritative directory of hub accounts.
-- Used by signup/login and as the owner referent for servers, shared
-- libraries, and webhooks. Argon2ID-hashed passwords; email + username
-- are both unique.

CREATE TABLE IF NOT EXISTS users (
    id              CHAR(36) NOT NULL,
    username        VARCHAR(64) NOT NULL,
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(128) DEFAULT NULL,
    is_admin        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_username (username),
    UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
