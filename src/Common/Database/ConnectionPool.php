<?php

/**
 * Phlix hub component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Database;

use Workerman\MySQL\Connection;

/**
 * Static MySQL connection pool wrapper around `workerman/mysql`.
 *
 * Initialise once via {@see ConnectionPool::init()} with the absolute
 * path to `config/database.php`; thereafter resolve a named connection
 * via {@see ConnectionPool::getConnection()}. The pool memoises each
 * named connection inside the worker process.
 *
 * @package Phlix\Hub\Common\Database
 */
class ConnectionPool
{
    /** @var array<string, Connection> */
    private static array $connections = [];

    private static string $configPath = '';

    private static ?ConnectionPool $instance = null;

    /**
     * Initialise the pool with the path to the DB config file.
     *
     * @param string $configPath Absolute path to `config/database.php`.
     */
    public static function init(string $configPath): void
    {
        self::$configPath = $configPath;
        self::$instance = new self();
    }

    /**
     * Get the singleton (or null if {@see init()} has not been called).
     *
     * @return self|null
     */
    public static function getInstance(): ?ConnectionPool
    {
        return self::$instance;
    }

    /**
     * Resolve a named MySQL connection, instantiating it on first access.
     *
     * @param string $name Connection key in `config/database.php`.
     *
     * @return Connection Live MySQL connection.
     */
    public static function getConnection(string $name = 'mysql'): Connection
    {
        if (!isset(self::$connections[$name])) {
            /**
             * @psalm-suppress UnresolvableInclude
             * @var array<string, array<string, scalar>> $config
             */
            $config = include self::$configPath;
            /** @var array<string, scalar> $connConfig */
            $connConfig = $config[$name];

            $poolEnabled = (bool) ($connConfig['pool_enabled'] ?? false);
            $poolSize = is_numeric($connConfig['pool_size'] ?? null)
                ? (int) $connConfig['pool_size']
                : 8;

            if ($poolEnabled) {
                // Coroutine connection POOL: each coroutine leases its OWN
                // connection for the duration it holds it, so no two coroutines
                // ever multiplex queries onto ONE PDO socket. This eliminates the
                // MySQL-protocol response DESYNC that the shared single-socket
                // {@see PhlixMySQLConnection} suffers under the Swoole runtime
                // hook: there, even though the per-connection mutex serialises
                // query() calls, interleaving many coroutines' queries onto one
                // socket makes each fetch return the PREVIOUS query's result set
                // ("lag-by-one") — e.g. `GET /api/v1/me`'s servers query returns
                // the users-row it just fetched, tripping
                // `ServerInfoHandler: row missing or null user_id` (500) and the
                // sibling `auth.user_not_found` (401) / `user.not_found` (404).
                // Mirrors phlix-server's PooledMySQLConnection. Env-gated (default
                // ON) via `pool_enabled`; set DB_POOL_ENABLED=0 to fall back to
                // the single mutex-serialised socket.
                self::$connections[$name] = new PooledMySQLConnection(
                    (string) $connConfig['host'],
                    (int) $connConfig['port'],
                    (string) $connConfig['user'],
                    (string) $connConfig['password'],
                    (string) $connConfig['database'],
                    $poolSize > 0 ? $poolSize : 8,
                );
            } else {
                // Fallback: the single PhlixMySQLConnection subclass — re-keys
                // positional bind arrays (workerman/mysql v1.0.9 bindMore() bug
                // on PHP 8.x) and serialises cross-coroutine access via its
                // per-connection mutex. Type-compatible with the parent.
                self::$connections[$name] = new PhlixMySQLConnection(
                    (string) $connConfig['host'],
                    (int) $connConfig['port'],
                    (string) $connConfig['user'],
                    (string) $connConfig['password'],
                    (string) $connConfig['database'],
                );
            }
        }
        return self::$connections[$name];
    }

    /**
     * Close every memoised connection and clear the pool.
     */
    public static function closeAll(): void
    {
        foreach (self::$connections as $connection) {
            $connection->closeConnection();
        }
        self::$connections = [];
    }

    /**
     * Chains a DB-connection cleanup onto the worker's onWorkerStop hook.
     *
     * Under the Swoole event loop, a coroutine-hooked PDO socket that is still
     * open when the process reaches RSHUTDOWN is torn down outside any
     * coroutine context and fatals the worker ("Couldn't execute method
     * Error::__toString in Unknown on line 0" on every SIGTERM/SIGINT stop).
     * onWorkerStop still runs inside a coroutine, so closing every connection
     * there lets the process exit cleanly. Any onWorkerStop already assigned
     * at call time is preserved and invoked first.
     *
     * @param \Workerman\Worker $worker Worker declared before runAll().
     */
    public static function armWorkerStopCleanup(\Workerman\Worker $worker): void
    {
        $previous = $worker->onWorkerStop;
        $worker->onWorkerStop = static function (\Workerman\Worker $w) use ($previous): void {
            if (is_callable($previous)) {
                $previous($w);
            }
            // Close client connections BEFORE draining the pool: their onClose
            // handlers may write to the DB (e.g. relay-session teardown) and
            // would otherwise re-create a hooked PDO connection after closeAll,
            // which then fatals at RSHUTDOWN. Worker::stop() only closes
            // connections AFTER onWorkerStop, so do it here first.
            /** @psalm-suppress InternalProperty */
            foreach ($w->connections as $connection) {
                $connection->close();
            }
            self::closeAll();
        };
    }
}
