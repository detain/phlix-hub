-- migration: 009_library_shares
-- Creates the `library_shares` table for per-library sharing between users.
-- The owner_user_id shares their library_id (on server_id) with collaborator_user_id.
-- Permission level controls read-only vs readwrite access.
-- Unique constraint prevents duplicate shares per (owner, collaborator, library) tuple.

CREATE TABLE IF NOT EXISTS library_shares (
    id                   CHAR(36) NOT NULL,
    owner_user_id        CHAR(36) NOT NULL,
    collaborator_user_id   CHAR(36) NOT NULL,
    server_id             CHAR(36) NOT NULL,
    library_id            VARCHAR(255) NOT NULL,
    library_name          VARCHAR(128) NOT NULL,
    permission_level     ENUM('read', 'readwrite') NOT NULL DEFAULT 'read',
    granted_by           CHAR(36) NOT NULL,
    created_at           INT UNSIGNED NOT NULL,
    expires_at          INT UNSIGNED NULL,
    revoked_at           INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_library_share (owner_user_id, collaborator_user_id, library_id),
    INDEX idx_owner (owner_user_id),
    INDEX idx_collaborator (collaborator_user_id),
    CONSTRAINT fk_ls_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ls_collaborator FOREIGN KEY (collaborator_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ls_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
