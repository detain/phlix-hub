<?php

declare(strict_types=1);

return [
    'default' => 'file',
    'handlers' => [
        'file' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/app.log',
            'max_files' => 30,
            'level' => 'debug',
        ],
        'error' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/error.log',
            'max_files' => 30,
            'level' => 'error',
        ],
        // Hub directory operations: server claims, heartbeats, listings.
        // Populated in B.6+.
        'hub' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/hub.log',
            'max_files' => 30,
            'level' => 'info',
        ],
        // Relay/tunnel subsystem. Populated in Phase C+.
        'relay' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/relay.log',
            'max_files' => 30,
            'level' => 'info',
        ],
        // Audit/security events: signup, login, logout, permission denied.
        // Wired in B.7 alongside the AuthManager.
        'audit' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/audit.log',
            'max_files' => 30,
            'level' => 'info',
        ],
    ],
];
