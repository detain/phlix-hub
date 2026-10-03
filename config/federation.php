<?php

declare(strict_types=1);

/**
 * Federation subsystem configuration.
 *
 * Optional env:
 *   HUB_FEDERATION_ENABLED — "false"/"0"/"no"/"off" closes the federation
 *                            subsystem at boot (default true: hub federation
 *                            was always-on before this setting existed).
 *
 * Load-bearing twin: `federation.enabled` in
 * {@see \Phlix\Hub\Hub\HubSettingsRepository::ALLOWED_KEYS} resolves this
 * file's `enabled` via getDefault(); HubSettingsAllowListTest rule 4 fails CI
 * if the key is renamed or removed. LIVE consumers: FederationController
 * mutators, FederationFrameHandler inbound dispatch, and
 * FederationPeerManager's outbound dial — all default to the boot flag here
 * when no override row / no pool.
 *
 * Note: turning federation off stops NEW dials, DATA frames and mutations.
 * The :8805 federation listener itself stays bound (its socket is created in
 * the pre-fork master; unbinding needs a process restart) and hub-to-hub TCP
 * connections are left open but silent — see the key's helpText.
 *
 * @package Phlix\Hub
 */

// NB: must NOT be written `filter_var(getenv(...) ?: 'true', ...)` — '0' is
// falsy in PHP, so `?: 'true'` would resurrect the default and make
// HUB_FEDERATION_ENABLED=0 impossible to express (same trap the metrics
// envBool in config/server.php documents). Explicit unset check instead.
$federationRaw = getenv('HUB_FEDERATION_ENABLED');
$federationEnabled = $federationRaw === false || $federationRaw === ''
    ? true
    : !in_array(strtolower(trim($federationRaw)), ['0', 'false', 'no', 'off'], true);

return [
    'enabled' => $federationEnabled,
];
