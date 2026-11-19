<?php

declare(strict_types=1);

use Phlix\Hub\Common\Env\EnvConfig;

// Config files load EnvConfig by path, not through the composer autoloader:
// the env -i child legs of tests/Unit/Config/DatabaseConfigTest are exactly
// the venue this doctrine protects. require_once dedupes vs the PSR-4 loader.
require_once __DIR__ . '/../src/Common/Env/EnvConfig.php';

// DB credential admission policy (review hub Major 37).
//
// The old shape was `getenv(...) ?: 'phlix_hub'` for user AND password — a
// credential pair published in this repo, applied SILENTLY whenever the env
// vars were missing. On a shared host that is not a dev convenience but a
// wrong-database hazard: the hub happily authenticates against whatever
// `phlix_hub`/`phlix_hub` MySQL account exists and reads/writes it.
//
// The policy now mirrors the estate's own JWT-secret doctrine
// (AuthServicesProvider::resolveSecret()): the literal fallbacks are admitted
// ONLY in dev/local (`APP_ENV`/`HUB_ENV`), under an explicit opt-in env flag,
// or inside the PHPUnit harness (which manages its own DB venue). Everywhere
// else, an unset `HUB_DB_PASSWORD` is a boot failure, not a silent default.
// Managed installs are unaffected: scripts/install.sh generates a random
// password and writes it into /etc/phlix-hub.env.
$dbPasswordEnv = getenv('HUB_DB_PASSWORD');
$dbUserEnv = getenv('HUB_DB_USER');

$devFallbackAllowed = static function (): bool {
    foreach (['APP_ENV', 'HUB_ENV'] as $key) {
        $value = getenv($key);
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'dev' || $normalized === 'development' || $normalized === 'local') {
                return true;
            }
        }
    }
    $flag = getenv('PHLIX_HUB_ALLOW_DEFAULT_DB_PASSWORD');
    if (is_string($flag) && in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    // The test harness builds its own DB venue and never relies on these
    // literals being production-safe (same early-return discipline as
    // AuthServicesProvider::isTestEnvironment()).
    return defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__');
};

if ($dbPasswordEnv === false && !$devFallbackAllowed()) {
    throw new RuntimeException(
        'No database password configured: set the HUB_DB_PASSWORD environment '
        . 'variable. The published "phlix_hub" literal fallback is only admitted '
        . 'in development (APP_ENV/HUB_ENV dev|local), with '
        . 'PHLIX_HUB_ALLOW_DEFAULT_DB_PASSWORD=1, or under PHPUnit — refusing to '
        . 'boot rather than silently authenticating with a publicly-known pair.',
    );
}

$mysql = [
    // HUB-2.1: these arms used `getenv(...) ?: <default>`, which swallows
    // the STRING "0" into the default — HUB_DB_HOST=0 silently meant
    // 127.0.0.1. EnvConfig reads now honor an explicit zero. The user and
    // password lines below keep their `!== false` explicit-check shape on
    // purpose: they are presence tests, and routing HUB_DB_PASSWORD through
    // text() would turn an explicit empty password into the published
    // 'phlix_hub' literal — strictly worse than today.
    'host'     => EnvConfig::text('HUB_DB_HOST', '127.0.0.1'),
    'port'     => EnvConfig::integer('HUB_DB_PORT', 3306),
    'user'     => $dbUserEnv !== false ? $dbUserEnv : 'phlix_hub',
    'password' => $dbPasswordEnv !== false ? $dbPasswordEnv : 'phlix_hub',
    'database' => EnvConfig::text('HUB_DB_NAME', 'phlix_hub'),

    // Coroutine connection POOL (default ON). Each coroutine leases its own
    // connection, so no two coroutines ever multiplex queries onto ONE mysqli (workerman/mysql)
    // socket. Without it, the single shared socket desyncs under the Swoole
    // runtime hook — every fetch returns the PREVIOUS query's result set
    // ("lag-by-one"), which is what intermittently 500'd/401'd the authed API
    // (`/api/v1/me`, `me/servers`, `auth/me`) under concurrency even though the
    // per-connection query mutex was intact. Set DB_POOL_ENABLED=0 to fall back
    // to the single mutex-serialised socket ({@see PhlixMySQLConnection}).
    // `pool_size` is per-connection-name PER WORKER — keep
    // (worker count × distinct connection names × pool_size) under MySQL
    // `max_connections`. Tune via DB_POOL_SIZE.
    // HUB-2.1 posture notes: DB_POOL_ENABLED set-but-EMPTY now means "not
    // configured" (honors the ON default) instead of feeding '' into
    // filter_var (which read as DISABLE); DB_POOL_SIZE=0 now FORWARDS the
    // explicit 0 instead of the `?:` era's silent 8 — and the floor that
    // makes an explicit 0 safe is the pool's OWN ceiling clamp:
    // PooledMySQLConnection::__construct takes max(1, $maxSize) ("Pool
    // ceiling (clamped to ≥ 1)"), so 0 arms a one-connection pool, never a
    // zero-worker one. Same behavior as the server's pool block, the
    // estate's canonical home of this law.
    'pool_enabled' => filter_var(
        EnvConfig::text('DB_POOL_ENABLED', '1'),
        FILTER_VALIDATE_BOOLEAN,
    ),
    'pool_size' => EnvConfig::integer('DB_POOL_SIZE', 8),
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
    // claim 500s. A separate mysqli (workerman/mysql) socket for the transactional handlers means a
    // reaper physically cannot interfere with an open transaction.
    'txn' => $mysql,
];
