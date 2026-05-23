<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\WebPortal\PageRenderer;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Controllers\MeController;
use Phlix\Hub\Http\Controllers\PageController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerManageController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Registers the HTTP layer (controllers, middleware, PageRenderer) with
 * the container.
 *
 * The two templates / cache / compile directories default to
 * `<project>/public/templates` and `<project>/var/smarty/{compile,cache}`.
 * Override via the `templates.{dir,compile,cache}` keys on the appConfig.
 *
 * @package Phlix\Hub\Common\Container\Providers
 * @since 0.2.0
 */
final class HttpServicesProvider implements ServiceProviderInterface
{
    /**
     * @inheritDoc
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $templatesDir = self::stringOr($appConfig, 'templates.dir', dirname(__DIR__, 4) . '/public/templates');
        $compileDir = self::stringOr($appConfig, 'templates.compile', dirname(__DIR__, 4) . '/var/smarty/compile');
        $cacheDir = self::stringOr($appConfig, 'templates.cache', dirname(__DIR__, 4) . '/var/smarty/cache');
        $publicDomain = self::stringOr($appConfig, 'public_domain', 'phlix.media');

        $builder->addDefinitions([
            PageRenderer::class => factory(static function () use (
                $templatesDir,
                $compileDir,
                $cacheDir,
            ): PageRenderer {
                return new PageRenderer($templatesDir, $compileDir, $cacheDir);
            }),

            AuthController::class => factory(static function (
                AuthManager $auth,
                PageRenderer $renderer,
            ): AuthController {
                return new AuthController($auth, $renderer);
            }),

            PageController::class => factory(static function (
                PageRenderer $renderer,
                AuthManager $auth,
                ServerInfoHandler $serverInfo,
            ): PageController {
                return new PageController($renderer, $auth, $serverInfo);
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
