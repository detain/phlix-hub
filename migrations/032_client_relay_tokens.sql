-- migration: 032_client_relay_tokens
-- Per-user, server-scoped, revocable client relay tokens (Step S2a).
--
-- A short-lived bearer credential minted for an authenticated hub user and
-- scoped to a single owned server. The client presents it to the client
-- relay worker to mount a tunnel (enforcement lands in S2b). Only the
-- SHA-256 hash of the token is stored — never the plaintext, which is
-- returned to the caller exactly once at mint time.
CREATE TABLE IF NOT EXISTS client_relay_tokens (
    id          CHAR(36) NOT NULL COMMENT 'UUID identifier',
    token_hash  CHAR(64) NOT NULL COMMENT 'SHA-256 hex hash of the plaintext token (never the token itself)',
    user_id     CHAR(36) NOT NULL COMMENT 'Hub user the token authenticates as',
    server_id   CHAR(36) NOT NULL COMMENT 'Server the token is scoped to (must be owned by user_id at mint time)',
    expires_at  TIMESTAMP NOT NULL COMMENT 'Hard expiry; the token is invalid once this passes',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the token was minted',
    revoked_at  TIMESTAMP NULL DEFAULT NULL COMMENT 'When the token was revoked (NULL = active)',
    PRIMARY KEY (id),
    UNIQUE INDEX uq_client_relay_tokens_hash (token_hash),
    INDEX idx_client_relay_tokens_user_server (user_id, server_id),
    INDEX idx_client_relay_tokens_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
