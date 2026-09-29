-- migration: 010_invite_links
-- Creates the `invite_links` table for single-use invite link sharing.
-- An invite link is an opaque 64-hex bearer token that grants library access
-- to recipients (never a session-grade JWT — corrected post-779fc7f, which
-- moved invites from signed-JWT URLs to these opaque tokens).
-- The token_hash stores SHA-256 of the raw opaque token for enumeration-safe lookups.

CREATE TABLE IF NOT EXISTS invite_links (
    id               CHAR(36) NOT NULL,
    owner_user_id   CHAR(36) NOT NULL,
    server_id       CHAR(36) NOT NULL,
    library_id       VARCHAR(255) NULL,
    permission      ENUM('read', 'readwrite') NOT NULL DEFAULT 'read',
    token_hash      VARCHAR(255) NOT NULL,
    max_uses        INT UNSIGNED NOT NULL DEFAULT 1,
    use_count       INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at      INT UNSIGNED NULL,
    created_at      INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id)       REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;