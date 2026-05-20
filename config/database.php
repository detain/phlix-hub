<?php

declare(strict_types=1);

return [
    'mysql' => [
        'host'     => getenv('HUB_DB_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('HUB_DB_PORT') ?: 3306),
        'user'     => getenv('HUB_DB_USER') ?: 'phlix_hub',
        'password' => getenv('HUB_DB_PASSWORD') ?: 'phlix_hub',
        'database' => getenv('HUB_DB_NAME') ?: 'phlix_hub',
    ],
];
