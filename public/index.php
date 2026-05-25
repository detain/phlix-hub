<?php

declare(strict_types=1);

/**
 * Phlix Hub HTTP entry point.
 *
 * Loads composer autoload, primes the static logger + DB pools, builds
 * the PSR-11 container, then hands off to {@see \Phlix\Hub\Application::boot()}
 * which starts a Workerman HTTP worker.
 *
 * Run with `php public/index.php start` during development.
 *
 * @package Phlix\Hub
 * @since 0.1.0
 */

use Phlix\Hub\Application;
use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Common\Logger\LoggerFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$configDir = __DIR__ . '/../config';
$dbConfigPath = $configDir . '/database.php';
$loggerConfigPath = $configDir . '/logger.php';
$authConfigPath = $configDir . '/auth.php';

LoggerFactory::init($loggerConfigPath);
// We deliberately do NOT call ConnectionPool::init() at bootstrap so the
// /health endpoint stays reachable even when MySQL is unreachable. The
// pool is initialised lazily by the container the first time a service
// asks for a Connection.
ConnectionPool::class; // ensure autoload pulls the class.

/** @var array<string, mixed> $serverConfig */
$serverConfig = include $configDir . '/server.php';
$serverConfig['db_config_path'] = $dbConfigPath;
$serverConfig['logger_config_path'] = $loggerConfigPath;
$serverConfig['auth_config_path'] = $authConfigPath;

$container = ContainerFactory::create($serverConfig);

// Register container for runtime timer wiring in HubServicesProvider::boot()
HubServicesProvider::setContainer($container);

$app = new Application($container, $serverConfig);
$app->boot();
