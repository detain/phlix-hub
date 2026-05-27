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
 * `public/index.php` is kept as a thin shim that requires this file —
 * existing systemd units that point at `public/index.php start` keep
 * working — but `start.php` is the canonical entry going forward.
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
// 0. Coroutine runtime — set Swoole as the eventLoop driver and enable
//    coroutine hooks in the master process before any Worker is instantiated.
//    Mirrors phlix-server/start.php lines ~48-58 (step 0.2a). Degrades
//    gracefully with an E_USER_WARNING when ext-swoole is absent so the
//    install scripts + test suite can boot on dev hosts that haven't
//    built ext-swoole yet (CI loading lives in step 0.3).
// -----------------------------------------------------------------------------

if (extension_loaded('swoole')) {
    // NOTE: the canonical Workerman 5 static property is
    // `Worker::$eventLoopClass`, not `Worker::$eventLoop` (which is an
    // *instance* property used to override the eventLoop on a single
    // Worker). Setting the static here, before any Worker is created,
    // makes Swoole the default eventLoop driver for ALL workers in
    // this process.
    Worker::$eventLoopClass = \Workerman\Events\Swoole::class;
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
} else {
    trigger_error(
        'Swoole extension not detected — coroutine runtime will not be active. Install ext-swoole to enable.',
        E_USER_WARNING
    );
}

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
// 3. Build the PSR-11 container from server.php + injected paths
// -----------------------------------------------------------------------------

/** @var array<string, mixed> $serverConfig */
$serverConfig = include $configDir . '/server.php';
$serverConfig['db_config_path']     = $dbConfigPath;
$serverConfig['logger_config_path'] = $loggerConfigPath;
$serverConfig['auth_config_path']   = $authConfigPath;
// Document root for the static-file fast path inside Application::boot().
$serverConfig['public_root']        = __DIR__ . '/public';

$container = ContainerFactory::create($serverConfig);

// Register the container for runtime timer wiring in HubServicesProvider::boot()
HubServicesProvider::setContainer($container);

// -----------------------------------------------------------------------------
// 4. Boot all workers (HTTP + server-relay + client-relay) and runAll()
// -----------------------------------------------------------------------------

$app = new Application($container, $serverConfig);
$app->boot();
