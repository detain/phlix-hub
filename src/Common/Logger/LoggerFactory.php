<?php

/**
 * Phlix hub component: Logger.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Logger;

/**
 * Static-cache factory for {@see StructuredLogger} instances.
 *
 * Initialise once via {@see LoggerFactory::init()} with the absolute
 * path to `config/logger.php`; thereafter callers resolve a logger by
 * channel name with {@see LoggerFactory::get()}.
 *
 * The config file is resolved through {@see LoggerFactory::loadConfig()}
 * — the guard port from `phlix-server` (A-17): an unset path, a path
 * that no longer exists, or a file that does not return an array all
 * yield an empty config instead of an include warning, a `ValueError`,
 * or a `TypeError`. Test fixtures that delete their temp logger.php
 * between cases are the reason: the stale pointer must not leak a PHP
 * warning into an unrelated suite under `failOnWarning="true"`. The
 * matching trade-off is the one the server factory documents — a missing
 * config at boot now produces a zero-handler logger (Monolog drops the
 * record) instead of fataling the worker.
 *
 * @package Phlix\Hub\Common\Logger
 */
class LoggerFactory
{
    /** @var array<string, StructuredLogger> */
    private static array $loggers = [];

    private static string $configPath = '';

    /**
     * Initialise the factory with the path to the logger config file.
     *
     * @param string $configPath Absolute path to `config/logger.php`.
     */
    public static function init(string $configPath): void
    {
        self::$configPath = $configPath;
    }

    /**
     * Get (or lazily create) a logger bound to the given channel.
     *
     * @param string $channel Channel name (see {@see LogChannels}).
     *
     * @return StructuredLogger Memoised logger for the channel.
     */
    public static function get(string $channel): StructuredLogger
    {
        if (!isset(self::$loggers[$channel])) {
            self::$loggers[$channel] = new StructuredLogger($channel, self::loadConfig());
        }
        return self::$loggers[$channel];
    }

    /**
     * Reset the static cache. Intended for tests only.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$loggers = [];
        // Drop the path too — otherwise a test that already deleted its
        // temp logger.php leaves a stale pointer that emits an `include`
        // warning the next time get() is called from another fixture's
        // tear-down.
        self::$configPath = '';
    }

    /**
     * Load the config file referenced by {@see self::init()}.
     *
     * Returns an empty array (silently) when the path is unset or no
     * longer exists — many tests spin up a temp config and tear it down
     * between cases, so swallowing the missing-file warning here is a
     * feature, not a bug. A config file that returns anything other
     * than an array is rejected the same way: the factory hands the
     * constructor an array (keys as PHP wrote them), never a scalar.
     *
     * @return array<array-key, mixed>
     */
    private static function loadConfig(): array
    {
        if (self::$configPath === '' || !is_file(self::$configPath)) {
            return [];
        }

        /**
         * @psalm-suppress UnresolvableInclude self::$configPath is set at runtime
         * @var mixed $config
         */
        $config = include self::$configPath;

        // is_array() is the runtime ENFORCEMENT of the config-file
        // contract (A-17: a scalar-returning config must fall back, not
        // TypeError). With $config honestly typed mixed the check is a
        // real narrowing, not a docblock-narrowed redundancy — the
        // guard and the types agree.
        return is_array($config) ? $config : [];
    }
}
