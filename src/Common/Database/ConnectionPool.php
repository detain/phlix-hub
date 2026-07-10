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

            // Use the local PhlixMySQLConnection subclass so positional
            // arrays passed to query() are re-keyed to 1-indexed before
            // PDO::bindParam() — workaround for workerman/mysql v1.0.9's
            // bindMore() bug on PHP 8.x ("Argument #1 must be >= 1" when
            // saving settings). Type-compatible with the parent Connection.
            self::$connections[$name] = new PhlixMySQLConnection(
                (string) $connConfig['host'],
                (int) $connConfig['port'],
                (string) $connConfig['user'],
                (string) $connConfig['password'],
                (string) $connConfig['database'],
            );
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
            foreach ($w->connections as $connection) {
                $connection->close();
            }
            self::closeAll();
        };
    }
}
