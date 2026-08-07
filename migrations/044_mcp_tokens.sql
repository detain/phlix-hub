-- migration: 044_mcp_tokens
-- S62 — Personal Access Tokens (PATs) for the hub's MCP endpoint (`POST /mcp`).
--
-- Shape is deliberately the `client_relay_tokens` table (migration 032) with two
-- differences that the MCP surface needs and the relay surface does not:
--
--   * `server_id` is GONE. An MCP PAT is scoped to a USER, not to one server —
--     `list_servers` has to enumerate every server that user owns. Per-server
--     authorisation is NOT weakened by that: every tool call re-derives the
--     token's `user_id` and runs the request through ServerProxyController,
--     whose existing 404 `server.not_found` / 403 `server.not_owned` ownership
--     gate is the thing that actually decides which servers are reachable.
--   * `scopes` is NEW: a space-delimited list of Phlix\Hub\Mcp\McpScopes
--     constants. A tool declares the scope it requires and McpToolRegistry
--     refuses to invoke it when the presenting token does not hold it, so a
--     narrow token stays narrow even as tools are added.
--
-- As with 032, ONLY the SHA-256 hash of the plaintext is stored; the plaintext
-- is returned to the caller exactly once at mint time and is unrecoverable
-- afterwards, so a database disclosure never yields a usable credential.
--
-- `expires_at` is NOT NULL by design (no perpetual tokens). The default TTL is
-- long (McpTokenService::DEFAULT_TTL_SECONDS, 90 days) because an MCP client is
-- a long-lived desktop/agent process, not a browser session — but it is still
-- finite, and `revoked_at` makes revocation immediate and independent of expiry.
--
-- Plain DDL only (no column/index `IF [NOT] EXISTS` — MariaDB-only syntax the
-- MySQL 8 deploy target rejects with a 1064). Idempotency comes from the
-- MigrationRunner tracking table, which applies each file exactly once.

CREATE TABLE IF NOT EXISTS mcp_tokens (
    id           CHAR(36) NOT NULL COMMENT 'UUID identifier',
    token_hash   CHAR(64) NOT NULL COMMENT 'SHA-256 hex hash of the plaintext PAT (never the token itself)',
    user_id      CHAR(36) NOT NULL COMMENT 'Hub user the token authenticates as',
    name         VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Operator-supplied label, shown in the token list',
    scopes       VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Space-delimited McpScopes constants granted to this token',
    expires_at   TIMESTAMP NOT NULL COMMENT 'Hard expiry; the token is invalid once this passes',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the token was minted',
    last_used_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last successful validation (NULL = never used)',
    revoked_at   TIMESTAMP NULL DEFAULT NULL COMMENT 'When the token was revoked (NULL = active)',
    PRIMARY KEY (id),
    UNIQUE INDEX uq_mcp_tokens_hash (token_hash),
    INDEX idx_mcp_tokens_user (user_id),
    INDEX idx_mcp_tokens_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
