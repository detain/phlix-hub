<?php

declare(strict_types=1);

namespace Phlex\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Hub\Common\Container\ServiceProviderInterface;
use Phlex\Hub\Common\Database\ConnectionPool;
use Phlex\Hub\Common\Logger\LogChannels;
use Phlex\Hub\Common\Logger\LoggerFactory;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Registers the foundational bindings used across `phlex-hub`.
 *
 * - {@see Connection} resolves to the singleton MySQL connection vended
 *   by {@see ConnectionPool::getConnection()}.
 * - {@see LoggerFactory} is bootstrapped once with the configured logger
 *   config path.
 * - One named binding per {@see LogChannels} constant is registered
 *   ("logger.auth", "logger.http", etc.) so consumers can reference a
 *   channel via `DI\get('logger.auth')` rather than pulling the factory.
 *
 * @package Phlex\Hub\Common\Container\Providers
 * @since 0.1.0
 */
final class CoreServicesProvider implements ServiceProviderInterface
{
    /**
     * Register database, logger factory and per-channel logger bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder   Builder being assembled.
     * @param array<string, mixed>            $appConfig Must contain `db_config_path` and
     *                                                   `logger_config_path` keys (the bootstrap
     *                                                   in `public/index.php` injects these).
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        /** @var mixed $dbConfigPathRaw */
        $dbConfigPathRaw = $appConfig['db_config_path'] ?? null;
        /** @var mixed $loggerConfigPathRaw */
        $loggerConfigPathRaw = $appConfig['logger_config_path'] ?? null;

        $dbConfigPath = is_string($dbConfigPathRaw) ? $dbConfigPathRaw : null;
        $loggerConfigPath = is_string($loggerConfigPathRaw) ? $loggerConfigPathRaw : null;

        $definitions = [
            'app.config' => $appConfig,
            'app.db_config_path' => $dbConfigPath,
            'app.logger_config_path' => $loggerConfigPath,

            Connection::class => factory(static function () use ($dbConfigPath): Connection {
                if ($dbConfigPath !== null && $dbConfigPath !== '' && ConnectionPool::getInstance() === null) {
                    ConnectionPool::init($dbConfigPath);
                }
                return ConnectionPool::getConnection('mysql');
            }),

            LoggerFactory::class => factory(static function () use ($loggerConfigPath): LoggerFactory {
                if ($loggerConfigPath !== null && $loggerConfigPath !== '') {
                    LoggerFactory::init($loggerConfigPath);
                }
                return new LoggerFactory();
            }),
        ];

        foreach (self::channels() as $alias => $channel) {
            $definitions[$alias] = factory(
                static function () use ($loggerConfigPath, $channel): StructuredLogger {
                    if ($loggerConfigPath !== null && $loggerConfigPath !== '') {
                        LoggerFactory::init($loggerConfigPath);
                    }
                    return LoggerFactory::get($channel);
                }
            );
        }

        // Default StructuredLogger autowiring target -> application channel.
        $definitions[StructuredLogger::class] = factory(
            static function () use ($loggerConfigPath): StructuredLogger {
                if ($loggerConfigPath !== null && $loggerConfigPath !== '') {
                    LoggerFactory::init($loggerConfigPath);
                }
                return LoggerFactory::get(LogChannels::APPLICATION);
            }
        );

        $builder->addDefinitions($definitions);
    }

    /**
     * Map of container alias -> log channel name. Exposed for tests.
     *
     * @return array<string, string>
     *
     * @since 0.1.0
     */
    public static function channels(): array
    {
        return [
            'logger.application' => LogChannels::APPLICATION,
            'logger.http' => LogChannels::HTTP,
            'logger.websocket' => LogChannels::WEBSOCKET,
            'logger.database' => LogChannels::DATABASE,
            'logger.auth' => LogChannels::AUTH,
            'logger.hub' => LogChannels::HUB,
            'logger.relay' => LogChannels::RELAY,
            'logger.audit' => LogChannels::AUDIT,
        ];
    }
}
