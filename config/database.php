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

    // Dedicated connection for the server-facing handlers that run explicit
    // multi-statement TRANSACTIONS (heartbeat, claim; same credentials as
    // 'mysql'). Those handlers run in request coroutines (cid>=0, mutex-guarded),
    // but the boot()-armed maintenance reapers (ServerReaper, IdleReaper, …) fire
    // from Workerman's pcntl timer scheduler with NO coroutine context (cid<0),
    // so their query() BYPASSES the per-connection mutex. On the shared 'mysql'
    // socket a reaper query lands mid-transaction and trips "SQLSTATE[HY000] 2014
    // unbuffered queries active", corrupting the transaction so the next
    // beginTrans() throws "There is already an active transaction" → heartbeat/
    // claim 500s. A separate PDO socket for the transactional handlers means a
    // reaper physically cannot interfere with an open transaction.
    'txn' => $mysql,
];
