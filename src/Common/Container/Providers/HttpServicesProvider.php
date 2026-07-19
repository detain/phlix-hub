<?php

/**
 * Phlix hub component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Controllers\MeController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerManageController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Registers the HTTP layer (JSON controllers + auth/admin middleware) with
 * the container. The legacy Smarty SSR stack (renderer, page controller,
 * CSRF middleware, and the template directories) has been retired — the Vue
 * SPA is now the only UI.
 *
 * @package Phlix\Hub\Common\Container\Providers
 */
final class HttpServicesProvider implements ServiceProviderInterface
{
    /**
     * @inheritDoc
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $publicDomain = self::stringOr($appConfig, 'public_domain', 'phlix.media');

        $builder->addDefinitions([
            AuthController::class => factory(static function (
                AuthManager $auth,
            ): AuthController {
                return new AuthController($auth);
            }),

            MeController::class => factory(static function (
                AuthManager $auth,
                ServerInfoHandler $serverInfo,
            ): MeController {
                return new MeController($auth, $serverInfo);
            }),

            ServerListController::class => factory(static function (
                ServerInfoHandler $serverInfo,
            ): ServerListController {
                return new ServerListController($serverInfo);
            }),

            ServerManageController::class => factory(static function (
                ServerInfoHandler $serverInfo,
                Connection $db,
            ) use ($publicDomain): ServerManageController {
                return new ServerManageController($serverInfo, $db, $publicDomain);
            }),

            AuthMiddleware::class => factory(static function (
                JwtHandler $jwt,
                UserRepository $users,
            ): AuthMiddleware {
                return new AuthMiddleware($jwt, $users);
            }),

            AdminMiddleware::class => factory(static function (
                UserRepository $users,
                AuditLogger $audit,
            ): AdminMiddleware {
                return new AdminMiddleware($users, $audit);
            }),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringOr(array $config, string $key, string $default): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $config[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
