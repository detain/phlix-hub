-- migration: 055_auth_token_sessions
-- CP-029 / D-1a (COOKIE-DESIGN.md §5, owner ruling 2026-11-10 "do it"):
-- session-family revocation store for the hub's JWT auth path — the twin of
-- the server's migration 127 (413765b6). Table shape is byte-ident; only
-- this prose is hub-specific.
--
-- The hub's shipped account-wide bound is the carrier layer: a logout cuts
-- the presented refresh lineage by `jti` (auth_revoked_refresh_tokens,
-- migration 047) and user deletion sweeps everything through the users FK.
-- What the hub never had — and what these tables add underneath both token
-- paths — is per-device sign-out and refresh-token REUSE detection:
--
--   auth_token_sessions  — one row per LOGIN ceremony (a "family"). Access and
--                          refresh JWTs minted from now on carry its id as the
--                          `sid` claim. Revoking one row ends exactly one
--                          device's chain and nothing else.
--   auth_refresh_grants  — one row per MINTED refresh token (its `jti` is the
--                          primary key). Rotation marks the presented token
--                          consumed; a replay of a consumed jti OUTSIDE the
--                          grace window is the reuse signal and revokes the
--                          family. A replay INSIDE the window (<= 10s, the
--                          server CP-005 design constant mirrored at query
--                          time) is treated as the benign multi-tab race the
--                          grace rule exists for: it rotates on, it does NOT
--                          revoke.
--
-- Legacy tokens (no `sid` claim) are structurally outside these tables: the
-- read paths (AuthMiddleware, AlexaAccountLink, refresh) consult this store
-- only when the claim is present, so pre-055 sessions keep honoring exactly
-- the watermark-free carrier path they honor today.
--
-- Idempotent by construction (CREATE TABLE IF NOT EXISTS): replaying the file
-- against a database that already carries both tables is a no-op.
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MPL-2.0

CREATE TABLE IF NOT EXISTS auth_token_sessions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    revoke_reason VARCHAR(64) NULL,
    device_type VARCHAR(50) NULL,
    device_name VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_ats_user (user_id),
    INDEX idx_ats_user_status (user_id, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_refresh_grants (
    jti CHAR(32) NOT NULL PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    consumed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arg_session (session_id),
    INDEX idx_arg_consumed (consumed_at),
    FOREIGN KEY (session_id) REFERENCES auth_token_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
