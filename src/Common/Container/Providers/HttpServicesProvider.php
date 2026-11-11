<?php

/**
 * Phlix hub component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\TokenSessionRepository;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Hub\CookiePolicy;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Health\HealthController;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Controllers\MeController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerManageController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Workerman\MySQL\Connection;

use function DI\factory;
use function DI\get;

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
            // S312 — EXPLICIT, and the explicitness is the point.
            //
            // HealthController used to be pure autowiring (no registration at
            // all). It now takes a MaintenanceHeartbeat, and PHP-DI's
            // `autowire()` SKIPS optional constructor parameters: left
            // unregistered, the parameter would resolve to `null`, the probe
            // would fall back to its pre-S312 payload, and /health would report
            // `ok` for a crash-looping maintenance worker — the exact defect
            // S312 exists to remove, silently reinstated by an omission that
            // reads like working code.
            HealthController::class => factory(static function (
                MaintenanceHeartbeat $maintenance,
            ): HealthController {
                return new HealthController($maintenance);
            })->parameter('maintenance', get(MaintenanceHeartbeat::class)),

            // CP-011: the ONE auth-cookie policy instance. Singleton so the
            // classify / attach / clear seams inside AuthController can never
            // disagree, and so any future consumer (the CP-012 Origin guard
            // rides the same scheme/class primitives) shares the same live
            // settings reads. Resolvers default to the canonical
            // HubSettingsResolvers idiom inside the class.
            CookiePolicy::class => factory(static function (): CookiePolicy {
                return new CookiePolicy();
            }),

            AuthController::class => factory(static function (
                AuthManager $auth,
                CookiePolicy $cookiePolicy,
            ): AuthController {
                return new AuthController($auth, $cookiePolicy);
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

            // CP-029 (D-1a hub twin): the middleware gains the session-family
            // store so an access token carrying a `sid` claim can be checked
            // against LIVE family state on every request. PHP-DI resolves the
            // typed closure parameter from the AuthServicesProvider binding —
            // the SAME container-cached instance AuthManager received, which
            // tests/Integration/Auth/AuthTokenSessionWiringGuardTest.php pins
            // by object identity (one store, one SSOT).
            AuthMiddleware::class => factory(static function (
                JwtHandler $jwt,
                UserRepository $users,
                TokenSessionRepository $tokenSessions,
            ): AuthMiddleware {
                return new AuthMiddleware($jwt, $users, $tokenSessions);
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
