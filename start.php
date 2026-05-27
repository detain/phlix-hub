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
