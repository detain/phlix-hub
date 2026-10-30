<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use DI\Definition\FactoryDefinition;
use DI\Definition\Reference;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Http\Controllers\RequestController;
use Phlix\Hub\Requests\RequestManager;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Minors campaign M-HUB-23 — the container can never ship the request-create
 * budget UNWIRED again.
 *
 * Same PHP-DI optional-parameter trap as the CLIENT_RELAY_TOKEN /
 * INVITE_REDEEM / SERVER_CLAIMS precedents (S62 law):
 * {@see RequestController}'s `$createLimiter` carries a `null` default and
 * PHP-DI SKIPS defaulted constructor parameters. Delete the provider's
 * `->parameter('createLimiter', ...)` line and every hand-wired unit test
 * still passes while production silently serves request creation with no
 * per-member budget — the exact unbilled-grind path M-HUB-23 closes. Only a
 * check at the DEFINITION sees it, so this suite reads the real provider's
 * definitions without ever building a container.
 *
 * It also pins the TARGET: `createLimiter` must reference the dedicated
 * CLIENT_REQUEST_CREATE profile binding specifically.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class RequestCreateLimiterWiringTest extends TestCase
{
    /**
     * @return array<string, mixed> Parameter name => raw definition value.
     */
    private static function factoryParametersFor(string $id): array
    {
        $builder = new ContainerBuilder();
        (new HubServicesProvider())->register($builder, []);

        $sources = new ReflectionProperty(ContainerBuilder::class, 'definitionSources');
        /** @var mixed $rawSources */
        $rawSources = $sources->getValue($builder);
        if (!is_array($rawSources)) {
            return [];
        }

        foreach ($rawSources as $source) {
            if (!is_array($source) || !isset($source[$id])) {
                continue;
            }
            /** @var mixed $helper */
            $helper = $source[$id];
            if (!$helper instanceof \DI\Definition\Helper\FactoryDefinitionHelper) {
                continue;
            }
            $definition = $helper->getDefinition($id);
            self::assertInstanceOf(FactoryDefinition::class, $definition);

            return $definition->getParameters();
        }

        return [];
    }

    public function testTheDefinitionLookupWorksBeforeAssertingAnything(): void
    {
        // Non-vacuity: a broken lookup returns [] and every assertion below
        // would measure nothing.
        $params = self::factoryParametersFor(RequestController::class);
        self::assertArrayHasKey(
            'manager',
            $params,
            'Could not read the RequestController factory definition from HubServicesProvider — '
                . 'either the binding moved or PHP-DI internals changed. Fix the lookup, do not delete this suite.',
        );
        self::assertSame(
            RequestManager::class,
            $params['manager'] instanceof Reference ? $params['manager']->getTargetEntryName() : null,
        );
    }

    public function testCreateLimiterIsExplicitlyWiredToTheDedicatedProfile(): void
    {
        $params = self::factoryParametersFor(RequestController::class);

        $nullableServiceParameters = [];
        $constructor = (new \ReflectionClass(RequestController::class))->getConstructor();
        self::assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !$type->allowsNull()) {
                continue;
            }
            $nullableServiceParameters[] = $parameter->getName();
        }

        // manager/notification/users/audit are REQUIRED ctor params;
        // createLimiter is the single optional collaborator — the exact one
        // PHP-DI would skip.
        self::assertSame(
            ['createLimiter'],
            $nullableServiceParameters,
            'RequestController was expected to carry exactly the one optional M-HUB-23 '
                . 'collaborator; a new nullable service dependency needs its own wiring pin here.',
        );

        self::assertArrayHasKey(
            'createLimiter',
            $params,
            'RequestController::$createLimiter is an optional dependency the container never '
                . 'supplies — PHP-DI skips defaulted parameters, so production would resolve it to null '
                . 'and the create surface would answer with NO per-member budget guard. Add '
                . "`->parameter('createLimiter', get(...))` to the RequestController definition.",
        );

        $limiterRef = $params['createLimiter'];
        self::assertInstanceOf(
            Reference::class,
            $limiterRef,
            'createLimiter must be an explicit container Reference, not an inline value.',
        );
        self::assertSame(
            RateLimitProfiles::CLIENT_REQUEST_CREATE,
            $limiterRef->getTargetEntryName(),
            'createLimiter must bind the dedicated CLIENT_REQUEST_CREATE profile — a shared or '
                . 'login-grade limiter would mis-shape the request-create budget.',
        );
    }
}
