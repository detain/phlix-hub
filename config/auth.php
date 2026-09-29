<?php

declare(strict_types=1);

/**
 * Hub auth configuration. Override via env vars in production.
 *
 * Required env:
 *   HUB_JWT_SECRET — ≥32-byte secret. The provider falls back to a
 *                    process-local random secret in dev when missing,
 *                    but production deployments MUST set this.
 *
 * Optional env:
 *   HUB_JWT_ACCESS_TTL   — access token TTL in seconds (default 3600).
 *   HUB_JWT_REFRESH_TTL  — refresh token TTL in seconds (default 604800).
 *   HUB_SIGNUPS_ENABLED  — "false"/"0"/"no"/"off" closes self-service
 *                          registration (auth audit fix 3); default true.
 *                          When false, POST /api/v1/auth/{register,signup}
 *                          answers 403 auth.signups_disabled BEFORE any
 *                          hashing work.
 *
 * @package Phlix\Hub
 */

/*
 * ⚠️  KEY NAMES ARE LOAD-BEARING.
 *
 * `access_ttl` / `refresh_ttl` are read verbatim by
 * {@see \Phlix\Hub\Common\Container\Providers\AuthServicesProvider::register()}
 * (src/Common/Container/Providers/AuthServicesProvider.php). That reader
 * silently falls back to its own hardcoded literals when the key is missing,
 * so renaming a key here does NOT fail loudly — it disables the matching
 * `HUB_JWT_*_TTL` env var in production. (That is exactly what happened when
 * these were briefly renamed to `access_token_ttl` / `refresh_token_ttl`.)
 *
 * The hub-settings allow-list key is the DOTTED CONFIG PATH
 * (`auth.access_ttl` / `auth.refresh_ttl` / `auth.signups_disabled`) — see
 * {@see \Phlix\Hub\Hub\HubSettingsRepository::ALLOWED_KEYS}. If a settings key
 * ever looks "orphaned", fix the ALLOW-LIST, never this file.
 */
$signupsEnabled = !in_array(
    strtolower(trim((string) (getenv('HUB_SIGNUPS_ENABLED') ?: 'true'))),
    ['0', 'false', 'no', 'off'],
    true,
);

return [
    'secret'      => getenv('HUB_JWT_SECRET') ?: null,
    'issuer'      => 'phlix-hub',
    'audience'    => 'hub',
    'access_ttl'  => (int) (getenv('HUB_JWT_ACCESS_TTL') ?: 3600),
    'refresh_ttl' => (int) (getenv('HUB_JWT_REFRESH_TTL') ?: 604800),
    // Load-bearing for the same reason as the TTL keys above:
    // AuthServicesProvider reads this key verbatim and defaults to TRUE when
    // absent — a rename here silently disables HUB_SIGNUPS_ENABLED.
    'signups_enabled' => $signupsEnabled,
    // DOTTED-TWIN of `auth.signups_disabled` in
    // {@see \Phlix\Hub\Hub\HubSettingsRepository::ALLOWED_KEYS}: getDefault()
    // resolves this key, and HubSettingsAllowListTest rule 4 goes RED (loudly,
    // in CI) if the key is ever removed or renamed. Live consumer: the signup
    // gate consults the settings row through the resolver installed by
    // AuthServicesProvider and falls back to the env-derived value carried
    // here, so `signups_disabled` is always exactly `!signups_enabled` at
    // config level — the DB override row is the only thing that can diverge.
    'signups_disabled' => !$signupsEnabled,
];
