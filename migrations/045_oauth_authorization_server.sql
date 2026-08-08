-- migration: 045_oauth_authorization_server
-- S92 — the hub's shared OAuth 2.0 Authorization Server.
--
-- Four tables, deliberately separate rather than one polymorphic "grants" table,
-- because each has a different lifetime and a different single-use rule:
--
--   oauth_clients              long-lived registry, updated by an operator
--   oauth_consent_requests     ~10 minutes, single-use, consumed by the consent POST
--   oauth_authorization_codes  ~60 seconds, single-use, consumed by the token endpoint
--   oauth_tokens               1 hour / 30 days, revocable, rotated on refresh
--
-- Built once and shared: nothing here is Alexa-specific. The Alexa skill is one
-- row in oauth_clients; MCP's future spec-correct mode is another, and its scope
-- strings (mcp:*) are already grantable — see Phlix\Hub\OAuth\OAuthScopes.
--
-- SECRETS AT REST — every credential in these tables is stored as a SHA-256 hex
-- digest and never as plaintext, following client_relay_tokens (032) and
-- mcp_tokens (044). That covers the consent ticket, the authorization code, both
-- token kinds, and the client secret. A disclosure of this schema's contents
-- yields nothing that can be presented to any endpoint.
--
-- SINGLE USE is enforced by a conditional UPDATE against the `consumed_at` /
-- `revoked_at` column (see AuthorizationCodeService::consume()), never by a
-- read-then-write pair in PHP, which is a check-then-act race. The UNIQUE index
-- on each *_hash column is what makes that UPDATE address exactly one row.

CREATE TABLE IF NOT EXISTS oauth_clients (
    id                 CHAR(36) NOT NULL COMMENT 'UUID identifier',
    client_id          VARCHAR(191) NOT NULL COMMENT 'Public client_id presented on the wire',
    name               VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Human label shown on the consent screen',
    redirect_uris      TEXT NOT NULL COMMENT 'Newline-delimited exact redirect URIs; matched whole, never by prefix',
    allowed_scopes     VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Space-delimited scope ceiling; empty = client refused',
    is_confidential    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when the client authenticates with a secret',
    client_secret_hash CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 hex of the client secret (never the secret)',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the client was registered',
    disabled_at        TIMESTAMP NULL DEFAULT NULL COMMENT 'When the client was disabled (NULL = enabled)',
    PRIMARY KEY (id),
    UNIQUE INDEX uq_oauth_clients_client_id (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_consent_requests (
    id             CHAR(36) NOT NULL COMMENT 'UUID identifier',
    ticket_hash    CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the single-use consent ticket',
    user_id        CHAR(36) NOT NULL COMMENT 'Hub user the consent screen was rendered for',
    client_id      VARCHAR(191) NOT NULL COMMENT 'Requesting client_id',
    redirect_uri   TEXT NOT NULL COMMENT 'The exact registered redirect URI that was matched',
    scopes         VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Space-delimited scopes shown to the user',
    state          VARCHAR(512) NULL DEFAULT NULL COMMENT 'Client opaque state, echoed back verbatim',
    code_challenge VARCHAR(128) NOT NULL COMMENT 'S256 code_challenge to bind the resulting code to',
    expires_at     TIMESTAMP NOT NULL COMMENT 'Hard expiry of the rendered consent screen',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the screen was rendered',
    consumed_at    TIMESTAMP NULL DEFAULT NULL COMMENT 'When the decision was submitted (NULL = pending)',
    PRIMARY KEY (id),
    UNIQUE INDEX uq_oauth_consent_requests_ticket (ticket_hash),
    INDEX idx_oauth_consent_requests_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
    id             CHAR(36) NOT NULL COMMENT 'UUID identifier; also the token lineage handle',
    code_hash      CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the single-use authorization code',
    client_id      VARCHAR(191) NOT NULL COMMENT 'Client the code was issued to; re-checked at redemption',
    user_id        CHAR(36) NOT NULL COMMENT 'Hub user who consented',
    redirect_uri   TEXT NOT NULL COMMENT 'Exact redirect URI the code was issued against; re-checked at redemption',
    scopes         VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Consented scopes; what the token gets, verbatim',
    code_challenge VARCHAR(128) NOT NULL COMMENT 'S256 challenge the redeemer must prove a verifier for',
    expires_at     TIMESTAMP NOT NULL COMMENT 'Hard expiry (~60s); enforced inside the claiming UPDATE',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the code was minted',
    consumed_at    TIMESTAMP NULL DEFAULT NULL COMMENT 'When it was redeemed (NULL = unused); replay detector',
    PRIMARY KEY (id),
    UNIQUE INDEX uq_oauth_authorization_codes_code (code_hash),
    INDEX idx_oauth_authorization_codes_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_tokens (
    id           CHAR(36) NOT NULL COMMENT 'UUID identifier',
    token_hash   CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the plaintext token (never the token itself)',
    kind         VARCHAR(16) NOT NULL COMMENT 'access | refresh — filtered on, so the kinds cannot substitute',
    client_id    VARCHAR(191) NOT NULL COMMENT 'Client the token was issued to',
    user_id      CHAR(36) NOT NULL COMMENT 'Hub user the token acts as',
    scopes       VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Space-delimited granted scopes',
    code_id      CHAR(36) NULL DEFAULT NULL COMMENT 'Authorization code lineage; revoke-the-family handle',
    expires_at   TIMESTAMP NOT NULL COMMENT 'Hard expiry',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the token was issued',
    last_used_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last successful validation (NULL = never used)',
    revoked_at   TIMESTAMP NULL DEFAULT NULL COMMENT 'When revoked or rotated (NULL = active)',
    PRIMARY KEY (id),
    UNIQUE INDEX uq_oauth_tokens_hash (token_hash),
    INDEX idx_oauth_tokens_code (code_id),
    INDEX idx_oauth_tokens_user_client (user_id, client_id),
    INDEX idx_oauth_tokens_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
