#!/usr/bin/env php
<?php

/**
 * Phlix Hub — Workerman bootstrap.
 *
 * This is the long-running daemon entry point. Modelled on webman's
 * `start.php` + `support\App::run()` pattern (and mirrored in
 * phlix-server's own `start.php`):
 *
 *   1. Composer autoload.
 *   2. Bootstrap config (server / database / logger / auth) once per
 *      worker process; the PSR-11 container is built from it and shared
 *      across requests through {@see Application}.
 *   3. {@see Application::boot()} creates the HTTP worker on the
 *      configured port, the relay workers on the tunnel ports, and
 *      calls `Worker::runAll()`.
 *
 * `start.php` is the ONLY entry point: the old `public/index.php` shim was
 * deleted with the S300 serving-model rewrite (no such file ships or exists
 * — see docker/docker-entrypoint.sh for the same correction), so nothing
 * may assume a web-root fallback. The systemd/Docker commands below invoke
 * this file directly.
 *
 * Usage:
 *   php start.php start          # foreground
 *   php start.php start -d       # daemonize
 *   php start.php stop
 *   php start.php restart
 *   php start.php reload
 *   php start.php status
 *
 * @see https://www.workerman.net/doc/workerman/install.html for the CLI commands.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';

use Phlix\Hub\Application;
use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Workerman\Worker;

// -----------------------------------------------------------------------------
// 0. Coroutine runtime — see step 3b below. The Swoole block must be gated on
//    config/server.php's `coroutine.*` keys, so it lives AFTER the config
//    include, still in the master and still before any Worker is instantiated
//    (the first Worker object is created inside Application::boot(), much
//    later in this file).
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// 1. Config paths
// -----------------------------------------------------------------------------

$configDir = __DIR__ . '/config';
$dbConfigPath     = $configDir . '/database.php';
$loggerConfigPath = $configDir . '/logger.php';
$authConfigPath   = $configDir . '/auth.php';

// -----------------------------------------------------------------------------
// 2. Initialise static pools / factories
// -----------------------------------------------------------------------------

LoggerFactory::init($loggerConfigPath);
// We deliberately do NOT call ConnectionPool::init() at bootstrap so the
// /health endpoint stays reachable even when MySQL is unreachable. The
// pool is initialised lazily by the container the first time a service
// asks for a Connection.
ConnectionPool::class; // ensure autoload pulls the class.

// Workerman's default log file is `workerman.log` in the current
// directory. Pin it to the install's .logs/ dir so the location is
// explicit and survives future systemd hardening (ProtectSystem=strict
// + ReadWritePaths) without needing a code change. phlix-server hit
// this exact EROFS path when its hardened unit shipped first.
$workerLogFile = __DIR__ . '/.logs/workerman.log';
@mkdir(dirname($workerLogFile), 0775, true);
Worker::$logFile = $workerLogFile;

// -----------------------------------------------------------------------------
// 3. Load server config, then pin the pid/status files FROM IT
// -----------------------------------------------------------------------------

/** @var array<string, mixed> $serverConfig */
$serverConfig = include $configDir . '/server.php';

// -----------------------------------------------------------------------------
// 3b. Coroutine runtime — set Swoole as the eventLoop driver and enable a
//     CURATED set of coroutine hooks in the master process before any Worker
//     is instantiated. Degrades gracefully with an E_USER_WARNING when
//     ext-swoole is absent so the install scripts + test suite can boot on dev
//     hosts that haven't built ext-swoole yet (CI loading lives in the
//     workflows).
//
//     Hooking *every* native call (SWOOLE_HOOK_ALL) crashed the estate's
//     Workerman/Swoole workers with recurring general-protection faults inside
//     swoole.so (`exit with status 139` = SIGSEGV) on the PHP 8.5 / Swoole
//     6.2.1 / kernel-7 (io_uring) stack. {@see \Phlix\Hub\Runtime\SwooleRuntime}
//     keeps the socket/sleep/stream hooks (so the coroutine MySQL pool +
//     network IO still yield) and drops FILE/PROC/CURL/STDIO, which run as
//     ordinary blocking calls. The master sets the mask so children inherit it;
//     Workerman's Swoole adapter constructor re-installs SWOOLE_HOOK_ALL per
//     worker regardless, which is why EVERY onWorkerStart re-asserts and
//     behaviourally PROVES the curated mask — {@see \Phlix\Hub\Runtime\HookDelivery}
//     (port of phlix-server's S433; R14-01/R16-01).
// -----------------------------------------------------------------------------
if (extension_loaded('swoole')) {
    // NOTE: the canonical Workerman 5 static property is
    // `Worker::$eventLoopClass`, not `Worker::$eventLoop` (which is an
    // *instance* property used to override the eventLoop on a single
    // Worker). Setting the static here, before any Worker is created,
    // makes Swoole the default eventLoop driver for ALL workers in
    // this process.
    Worker::$eventLoopClass = \Workerman\Events\Swoole::class;
    if (\Phlix\Hub\Runtime\SwooleRuntime::coroutineEnabled($serverConfig)) {
        // Enable the CURATED coroutine hook mask in the master so children
        // inherit it. resolveHookFlags() handles Swoole 5/6 constant
        // differences (e.g. SWOOLE_HOOK_SOCKET was removed in Swoole 6).
        \Swoole\Runtime::enableCoroutine(\Phlix\Hub\Runtime\SwooleRuntime::resolveHookFlags($serverConfig));
    }
} else {
    trigger_error(
        'Swoole extension not detected — coroutine runtime will not be active. Install ext-swoole to enable.',
        E_USER_WARNING
    );
}

// Same rationale as the log file for the pid + status files. Workerman writes
// its master pid (unconditionally, in Worker::runAll() → saveMasterPid(), which
// throws if the write fails) and its status/statistics/connections files next to
// the start file — i.e. into the install ROOT. Under the hardened unit that root
// is read-only (only var/, .logs/, config/ are ReadWritePaths), so these live in
// var/ (a ReadWritePath and the service HOME).
//
// ⚠️ SINGLE SOURCE OF TRUTH: the path comes from config/server.php's `pid_file`
// — the SAME value the container hands to HubRestartController. When these two
// were independent literals the admin restart endpoint read a file nobody ever
// wrote and returned 500 `pid_file_not_found` on every deployed hub. Do not
// re-inline a literal here.
$hubPidFile = is_string($serverConfig['pid_file'] ?? null) && $serverConfig['pid_file'] !== ''
    ? (string) $serverConfig['pid_file']
    : __DIR__ . '/var/hub.pid';
$hubStatusFile = is_string($serverConfig['status_file'] ?? null) && $serverConfig['status_file'] !== ''
    ? (string) $serverConfig['status_file']
    : __DIR__ . '/var/hub.status';

@mkdir(dirname($hubPidFile), 0775, true);
@mkdir(dirname($hubStatusFile), 0775, true);
Worker::$pidFile = $hubPidFile;
Worker::$statusFile = $hubStatusFile;

// -----------------------------------------------------------------------------
// 3c. JWT secret pre-flight (R14-08)
// -----------------------------------------------------------------------------
// Fail-fast BEFORE the container builds and before any worker forks: a
// SET-but-bad HUB_JWT_SECRET (documented placeholder, empty, or short outside
// development) must kill the boot once, loudly, on the console. The UNSET
// case deliberately passes here — resolveSecret() owns that verdict
// (production: MissingJwtSecretException at container build; dev/local:
// random fallback with a loud warning), and docker-entrypoint.sh's
// persist-or-generate path always exports a real key, so containers are
// unaffected. Mirrors phlix-server's start.php pre-flight doctrine.
try {
    \Phlix\Hub\Common\Container\Providers\AuthServicesProvider::assertSecretConfigured();
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

// -----------------------------------------------------------------------------
// 4. Build the PSR-11 container from server.php + injected paths
// -----------------------------------------------------------------------------

$serverConfig['db_config_path']     = $dbConfigPath;
$serverConfig['logger_config_path'] = $loggerConfigPath;
$serverConfig['auth_config_path']   = $authConfigPath;
// S312: config/process.php is the single source of truth for whether the
// maintenance worker runs at all. /health has to know, because "no heartbeat
// record" means something completely different when the worker was never
// supposed to exist. Injected as a PATH, like the three above, so the container
// does not have to guess its own location relative to config/.
$serverConfig['process_config_path'] = $configDir . '/process.php';
// Document root for the static-file fast path inside Application::boot().
$serverConfig['public_root']        = __DIR__ . '/public';

$container = ContainerFactory::create($serverConfig);

// Register the container for runtime timer wiring in HubServicesProvider::boot()
HubServicesProvider::setContainer($container);

// -----------------------------------------------------------------------------
// 5. Boot all workers (HTTP + server-relay + client-relay) and runAll()
// -----------------------------------------------------------------------------

$app = new Application($container, $serverConfig);
$app->boot();
