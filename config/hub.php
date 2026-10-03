<?php

declare(strict_types=1);

/**
 * Hub operational posture configuration.
 *
 * Optional env:
 *   HUB_MAINTENANCE_MODE — "true"/"1"/"yes"/"on" puts the hub into
 *                          maintenance mode at boot (default false).
 *
 * Load-bearing twin: `hub.maintenance_mode` in
 * {@see \Phlix\Hub\Hub\HubSettingsRepository::ALLOWED_KEYS} resolves this
 * file's `maintenance_mode` via getDefault(); HubSettingsAllowListTest rule 4
 * fails CI if the key is renamed or removed. LIVE consumer:
 * {@see \Phlix\Hub\Hub\MaintenanceGate} (constructed in the HTTP workers with
 * the boot flag here as its fail-open fallback).
 *
 * @package Phlix\Hub
 */

// Explicit unset check (not `getenv(...) ?: 'false'`): with a false default
// the `?:` form happens to work for every value, but the codebase doctrine
// (config/server.php metrics NB) mandates the readable shape.
$maintenanceRaw = getenv('HUB_MAINTENANCE_MODE');
$maintenanceMode = $maintenanceRaw !== false
    && !in_array(strtolower(trim($maintenanceRaw)), ['', '0', 'false', 'no', 'off'], true);

return [
    'maintenance_mode' => $maintenanceMode,
];
