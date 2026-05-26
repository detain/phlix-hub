<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Container;

use DI\ContainerBuilder;

/**
 * Contract for service providers that register bindings on the PHP-DI
 * ContainerBuilder.
 *
 * Service providers group related bindings so that
 * {@see ContainerFactory} can compose them without knowing the details
 * of each subsystem. Implementations should be stateless: a single
 * register() call receives the builder, and the provider mutates it by
 * adding definitions.
 *
 * @package Phlix\Hub\Common\Container
 */
interface ServiceProviderInterface
{
    /**
     * Register service definitions on the given container builder.
     *
     * @param ContainerBuilder<\DI\Container> $builder   The builder being assembled by {@see ContainerFactory}.
     * @param array<string, mixed>            $appConfig Application configuration as merged by the factory
     *                                                   (`config/server.php` plus `db_config_path` and
     *                                                   `logger_config_path` injected by the bootstrap).
     *
     * @return void
     *
     */
    public function register(ContainerBuilder $builder, array $appConfig): void;
}
