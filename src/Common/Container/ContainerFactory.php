<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Container;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\AuthServicesProvider;
use Phlix\Hub\Common\Container\Providers\CoreServicesProvider;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Container\Providers\HttpServicesProvider;
use Psr\Container\ContainerInterface;

/**
 * Builds the application's PSR-11 container.
 *
 * `ContainerFactory::create($config)` composes the application's
 * service providers against a fresh PHP-DI {@see ContainerBuilder}
 * with autowiring and PHP 8 attribute parsing enabled, then returns
 * the compiled container.
 *
 * @package Phlix\Hub\Common\Container
 */
final class ContainerFactory
{
    /**
     * Default location of the compiled-container cache, relative to the
     * project root. Override by passing `compile_dir` in $appConfig.
     */
    public const DEFAULT_COMPILE_DIR = 'var/cache/container';

    /**
     * Private constructor — the factory is purely static.
     */
    private function __construct()
    {
    }

    /**
     * Build and return a PSR-11 container for the running application.
     *
     * @param array<string, mixed>                       $appConfig Application configuration
     *                                                              (typically the array returned by
     *                                                              `config/server.php` plus
     *                                                              `db_config_path` / `logger_config_path`).
     * @param array<int, ServiceProviderInterface>|null $providers Override the default provider stack.
     *                                                              Mostly useful for tests.
     *
     * @return ContainerInterface Fully built PSR-11 container.
     *
     * @throws \Exception When PHP-DI fails to compile the container.
     *
     */
    public static function create(array $appConfig = [], ?array $providers = null): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(true);

        if (self::shouldCompile()) {
            /** @var mixed $compileDirRaw */
            $compileDirRaw = $appConfig['compile_dir'] ?? self::DEFAULT_COMPILE_DIR;
            $compileDir = is_string($compileDirRaw) ? $compileDirRaw : self::DEFAULT_COMPILE_DIR;
            if ($compileDir !== '' && !is_dir($compileDir)) {
                @mkdir($compileDir, 0775, true);
            }
            if ($compileDir !== '' && is_dir($compileDir) && is_writable($compileDir)) {
                $builder->enableCompilation($compileDir);
            }
        }

        foreach ($providers ?? self::defaultProviders() as $provider) {
            $provider->register($builder, $appConfig);
        }

        return $builder->build();
    }

    /**
     * Canonical list of providers wired into a stock hub container.
     *
     * @return array<int, ServiceProviderInterface>
     *
     */
    public static function defaultProviders(): array
    {
        return [
            new CoreServicesProvider(),
            new AuthServicesProvider(),
            new HttpServicesProvider(),
            new HubServicesProvider(),
        ];
    }

    /**
     * Whether to enable PHP-DI's compiled-container cache.
     *
     * @return bool True when `PHLIX_HUB_CONTAINER_COMPILE` is truthy.
     *
     */
    private static function shouldCompile(): bool
    {
        $value = getenv('PHLIX_HUB_CONTAINER_COMPILE');
        if ($value === false) {
            return false;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
