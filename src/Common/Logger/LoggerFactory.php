<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Logger;

/**
 * Static-cache factory for {@see StructuredLogger} instances.
 *
 * Initialise once via {@see LoggerFactory::init()} with the absolute
 * path to `config/logger.php`; thereafter callers resolve a logger by
 * channel name with {@see LoggerFactory::get()}.
 *
 * @package Phlix\Hub\Common\Logger
 * @since 0.1.0
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
            /**
             * @psalm-suppress UnresolvableInclude
             * @var array<string, mixed> $config
             */
            $config = include self::$configPath;
            self::$loggers[$channel] = new StructuredLogger($channel, $config);
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
    }
}
