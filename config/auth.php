<?php

declare(strict_types=1);

use Phlix\Hub\Common\Env\EnvConfig;

// Config files load EnvConfig by path, not through the composer autoloader:
// estate doctrine keeps a config file loadable without class autoload
// (tests/Unit/Config/DatabaseConfigTest proves it with `env -i` children).
// require_once dedupes against the PSR-4 loader on the long-running path.
require_once __DIR__ . '/../src/Common/Env/EnvConfig.php';

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
// HUB-2.1 (bug #5, fail-open): this read was `getenv(...) ?: 'true'` — and
// `?:` treats the STRING "0" as falsy, so an explicit HUB_SIGNUPS_ENABLED=0
// evaluated to 'true' and left self-service registration OPEN, exactly the
// opposite of the documented deny-list in the header above. EnvConfig::text
// lets "0" survive to the deny-list; trim/lower-case semantics are unchanged.
$signupsEnabled = !in_array(
    strtolower(trim(EnvConfig::text('HUB_SIGNUPS_ENABLED', 'true'))),
    ['0', 'false', 'no', 'off'],
    true,
);

return [
    // nullableText: unset/empty → null (the provider's dev-fallback gate
    // reads the absence); an explicit HUB_JWT_SECRET=0 now arrives verbatim
    // and is refused by resolveSecret()'s ≥32-byte gate — the same refusal
    // path null takes, but the config no longer pretends it is unset.
    'secret'      => EnvConfig::nullableText('HUB_JWT_SECRET'),
    'issuer'      => 'phlix-hub',
    'audience'    => 'hub',
    // integer(): the `?:` arms here swallowed explicit 0 TTLs into the
    // defaults (HUB-2.1); 0 now forwards — a dial the operator set.
    'access_ttl'  => EnvConfig::integer('HUB_JWT_ACCESS_TTL', 3600),
    'refresh_ttl' => EnvConfig::integer('HUB_JWT_REFRESH_TTL', 604800),
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
