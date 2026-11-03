<?php

declare(strict_types=1);

// Operator seam for the file-logging surface (review hub Major 40).
// The whole logging config used to be welded: primary channel hardcoded at
// 'debug', every path anchored to <install>/.logs, zero env reads. A busy
// hub at debug then grows one very large file per day — Monolog's
// RotatingFileHandler caps by DAY COUNT (max_files below), never by bytes —
// and 30 days of that can dominate the disk holding the install root.
$envStr = static fn (string $k, string $d): string => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;

// Default keeps <install>/.logs: the shipped systemd unit lists exactly
// that path in ReadWritePaths (scripts/install.sh), so changing the default
// would break managed installs. HUB_LOG_DIR relocates all five channels.
$logDir = rtrim($envStr('HUB_LOG_DIR', __DIR__ . '/../.logs'), '/');

// Primary-channel verbosity. Default is 'info' (the old hardcoded 'debug'
// was the hazard this seam exists to close); operators who want everything
// back set HUB_LOG_LEVEL=debug. Invalid names fail loud at boot — the same
// parse-at-the-boundary doctrine as every other config file here.
$logLevel = strtolower(trim($envStr('HUB_LOG_LEVEL', 'info')));
$allowedLevels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
if (!in_array($logLevel, $allowedLevels, true)) {
    throw new RuntimeException(
        "Invalid HUB_LOG_LEVEL '{$logLevel}': expected one of " . implode(', ', $allowedLevels) . '.',
    );
}

return [
    'default' => 'file',
    // NB: there is deliberately NO top-level 'level' / 'audit_enabled' key.
    // StructuredLogger reads the PER-HANDLER 'level' below (see
    // src/Common/Logger/StructuredLogger.php:105) — a top-level key would be
    // read by nothing. Do not re-add one to make a settings key resolve.
    'handlers' => [
        'file' => [
            'type' => 'rotating_file',
            'path' => $logDir . '/app.log',
            'max_files' => 30,
            'level' => $logLevel,
        ],
        'error' => [
            'type' => 'rotating_file',
            'path' => $logDir . '/error.log',
            'max_files' => 30,
            'level' => 'error',
        ],
        // Hub directory operations: server claims, heartbeats, listings.
        'hub' => [
            'type' => 'rotating_file',
            'path' => $logDir . '/hub.log',
            'max_files' => 30,
            'level' => 'info',
        ],
        // Relay/tunnel subsystem.
        'relay' => [
            'type' => 'rotating_file',
            'path' => $logDir . '/relay.log',
            'max_files' => 30,
            'level' => 'info',
        ],
        // Audit/security events: signup, login, logout, permission denied.
        'audit' => [
            'type' => 'rotating_file',
            'path' => $logDir . '/audit.log',
            'max_files' => 30,
            'level' => 'info',
        ],
    ],
];
