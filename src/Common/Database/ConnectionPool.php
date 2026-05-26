<?php

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

            self::$connections[$name] = new Connection(
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
}
