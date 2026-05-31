-- migration: 029_audit_logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id            CHAR(36) NOT NULL COMMENT 'UUID identifier',
    event         VARCHAR(64) NOT NULL COMMENT 'Event type: login|logout|auth_failure|permission_denied|signup|admin_action|hub_connect|hub_disconnect|library_share_cross_hub|admin_delegation',
    user_id       CHAR(36) NULL COMMENT 'Affected user (null for system events)',
    session_id    VARCHAR(255) NULL COMMENT 'Session ID if applicable',
    device_id     VARCHAR(255) NULL COMMENT 'Device/client identifier',
    resource      VARCHAR(255) NULL COMMENT 'Resource id or path targeted',
    action        VARCHAR(128) NULL COMMENT 'Action performed (e.g. request.approve)',
    success       TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=failure, 1=success',
    reason        VARCHAR(255) NULL COMMENT 'Short machine-friendly reason tag',
    ip_address    VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 client address',
    user_agent    VARCHAR(512) NULL COMMENT 'Client User-Agent string',
    context_json  TEXT NULL COMMENT 'Additional structured context as JSON',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_event (event),
    INDEX idx_user_id (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;