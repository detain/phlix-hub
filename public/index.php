<?php

declare(strict_types=1);

/**
 * Phlex Hub HTTP entry point.
 *
 * Loads composer autoload, primes the static logger + DB pools, builds
 * the PSR-11 container, then hands off to {@see \Phlex\Hub\Application::boot()}
 * which starts a Workerman HTTP worker.
 *
 * Run with `php public/index.php start` during development.
 *
 * @package Phlex\Hub
 * @since 0.1.0
 */

use Phlex\Hub\Application;
use Phlex\Hub\Common\Container\ContainerFactory;
use Phlex\Hub\Common\Database\ConnectionPool;
use Phlex\Hub\Common\Logger\LoggerFactory;

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
$app = new Application($container, $serverConfig);
$app->boot();
