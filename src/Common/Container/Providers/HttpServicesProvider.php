<?php

declare(strict_types=1);

namespace Phlex\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Hub\Auth\AuthManager;
use Phlex\Hub\Auth\JwtHandler;
use Phlex\Hub\Auth\UserRepository;
use Phlex\Hub\Common\Container\ServiceProviderInterface;
use Phlex\Hub\Common\Logger\AuditLogger;
use Phlex\Hub\Common\WebPortal\PageRenderer;
use Phlex\Hub\Http\Controllers\AuthController;
use Phlex\Hub\Http\Controllers\MeController;
use Phlex\Hub\Http\Controllers\PageController;
use Phlex\Hub\Http\Middleware\AdminMiddleware;
use Phlex\Hub\Http\Middleware\AuthMiddleware;

use function DI\factory;

/**
 * Registers the HTTP layer (controllers, middleware, PageRenderer) with
 * the container.
 *
 * The two templates / cache / compile directories default to
 * `<project>/public/templates` and `<project>/var/smarty/{compile,cache}`.
 * Override via the `templates.{dir,compile,cache}` keys on the appConfig.
 *
 * @package Phlex\Hub\Common\Container\Providers
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
            ): PageController {
                return new PageController($renderer, $auth);
            }),

            MeController::class => factory(static function (AuthManager $auth): MeController {
                return new MeController($auth);
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
