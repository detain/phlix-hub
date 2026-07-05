<?php

declare(strict_types=1);

$mysql = [
    'host'     => getenv('HUB_DB_HOST') ?: '127.0.0.1',
    'port'     => (int) (getenv('HUB_DB_PORT') ?: 3306),
    'user'     => getenv('HUB_DB_USER') ?: 'phlix_hub',
    'password' => getenv('HUB_DB_PASSWORD') ?: 'phlix_hub',
    'database' => getenv('HUB_DB_NAME') ?: 'phlix_hub',
];

return [
    'mysql' => $mysql,

    // Dedicated connection for the metrics flush timer (same credentials as
    // 'mysql'). The flush fires from a Workerman Timer, which runs with NO
    // coroutine context, so its query() takes PhlixMySQLConnection's direct
    // passthrough and BYPASSES the per-connection coroutine mutex. On the shared
    // 'mysql' connection it can then barge into a request coroutine's in-flight
    // (yielded) query and trip "SQLSTATE[HY000] 2014 unbuffered queries active".
    // Its own connection isolates the flush so it never collides with request
    // traffic. See MetricsFlushService::CONNECTION.
    'metrics' => $mysql,
];
