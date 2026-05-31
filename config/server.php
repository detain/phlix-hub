<?php

declare(strict_types=1);

$hubPort = (int) (getenv('HUB_PORT') ?: 8800);

// Public-facing base URL of the hub. This is baked into the enrollment
// JWT and the JWKS URL handed to enrolled servers, so it MUST be reachable
// from those servers — not the in-process listen address. Precedence:
//   1. HUB_BASE_URL (explicit override, incl. scheme),
//   2. https://<HUB_PUBLIC_DOMAIN> when a public domain is configured,
//   3. http://localhost:<port> as the single-host / dev fallback.
$hubPublicDomain = getenv('HUB_PUBLIC_DOMAIN') ?: '';
$hubBaseUrl = getenv('HUB_BASE_URL')
    ?: ($hubPublicDomain !== ''
        ? 'https://' . $hubPublicDomain
        : 'http://localhost:' . $hubPort);

return [
    'host'          => getenv('HUB_HOST') ?: '0.0.0.0',
    'port'          => $hubPort,
    'workers'       => (int) (getenv('HUB_WORKERS') ?: 2),
    'workerman_log' => getenv('HUB_WORKERMAN_LOG') ?: __DIR__ . '/../.logs/workerman.log',

    // Public domain used to build relay URLs for subdomain-allocated servers.
    // Each enrolled server gets `<subdomain>.<public_domain>` (see migration
    // 008 and `Phlix\Hub\Hub\DnsAliasManager`).
    'public_domain' => getenv('HUB_PUBLIC_DOMAIN') ?: 'phlix.media',

    // Public base URL (scheme + host) the hub advertises to enrolled servers
    // for heartbeats and JWKS fetches. See $hubBaseUrl derivation above.
    'hub_base_url'  => $hubBaseUrl,

    // Sonarr/Radarr endpoints used by the request UI.
    // See \Phlix\Shared\Arr\ArrClientFactory for the expected shape.
    'arr' => [
        'sonarr' => [
            'url'     => getenv('HUB_SONARR_URL') ?: 'http://localhost:8989',
            'api_key' => getenv('HUB_SONARR_API_KEY') ?: '',
            'enabled' => filter_var(getenv('HUB_SONARR_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
        ],
        'radarr' => [
            'url'     => getenv('HUB_RADARR_URL') ?: 'http://localhost:7878',
            'api_key' => getenv('HUB_RADARR_API_KEY') ?: '',
            'enabled' => filter_var(getenv('HUB_RADARR_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
        ],
    ],
];
