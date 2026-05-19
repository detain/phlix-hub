<?php

declare(strict_types=1);

return [
    'host'          => getenv('HUB_HOST') ?: '0.0.0.0',
    'port'          => (int) (getenv('HUB_PORT') ?: 8800),
    'workers'       => (int) (getenv('HUB_WORKERS') ?: 2),
    'workerman_log' => getenv('HUB_WORKERMAN_LOG') ?: __DIR__ . '/../.logs/workerman.log',

    // Sonarr/Radarr endpoints used by the K.3 request UI.
    // See \Phlex\Shared\Arr\ArrClientFactory for the expected shape.
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
