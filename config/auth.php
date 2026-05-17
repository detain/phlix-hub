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
 *
 * @package Phlex\Hub
 * @since 0.2.0
 */

return [
    'secret'      => getenv('HUB_JWT_SECRET') ?: null,
    'issuer'      => 'phlex-hub',
    'audience'    => 'hub',
    'access_ttl'  => (int) (getenv('HUB_JWT_ACCESS_TTL') ?: 3600),
    'refresh_ttl' => (int) (getenv('HUB_JWT_REFRESH_TTL') ?: 604800),
];
