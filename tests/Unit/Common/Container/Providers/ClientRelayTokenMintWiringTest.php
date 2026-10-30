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
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Http\Controllers\ClientRelayTokenController;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Minors campaign M-HUB-22 — the container can never ship the relay-token
 * mint budget UNWIRED again.
 *
 * Same PHP-DI optional-parameter trap as the SERVER_CLAIMS / INVITE_REDEEM
 * precedents (S62 law): {@see ClientRelayTokenController}'s `$mintLimiter`
 * carries a `null` default and PHP-DI SKIPS defaulted constructor
 * parameters. Delete the provider's `->parameter('mintLimiter', ...)` line
 * and every hand-wired unit test still passes while production silently
 * serves mint with no per-member rate bound — the exact unbilled-grind path
 * M-HUB-22 closes. Only a check at the DEFINITION sees it, so this suite
 * reads the real provider's definitions without ever building a container.
 *
 * It also pins the TARGET: `mintLimiter` must reference the dedicated
 * CLIENT_RELAY_TOKEN profile binding specifically — a login-grade or
 * proxy limiter wired in by a careless merge would pass a names-only check
 * and silently mis-shape the budget.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class ClientRelayTokenMintWiringTest extends TestCase
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
        $params = self::factoryParametersFor(ClientRelayTokenController::class);
        self::assertArrayHasKey(
            'tokens',
            $params,
            'Could not read the ClientRelayTokenController factory definition from HubServicesProvider — '
                . 'either the binding moved or PHP-DI internals changed. Fix the lookup, do not delete this suite.',
        );
        self::assertSame(
            ClientRelayTokenService::class,
            $params['tokens'] instanceof Reference ? $params['tokens']->getTargetEntryName() : null,
        );
    }

    public function testMintLimiterIsExplicitlyWiredToTheDedicatedProfile(): void
    {
        $params = self::factoryParametersFor(ClientRelayTokenController::class);

        $nullableServiceParameters = [];
        $constructor = (new \ReflectionClass(ClientRelayTokenController::class))->getConstructor();
        self::assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !$type->allowsNull()) {
                continue;
            }
            $nullableServiceParameters[] = $parameter->getName();
        }

        // tokens/serverInfo/audit are REQUIRED ctor params; mintLimiter is the
        // single optional collaborator — the exact one PHP-DI would skip.
        self::assertSame(
            ['mintLimiter'],
            $nullableServiceParameters,
            'ClientRelayTokenController was expected to carry exactly the one optional M-HUB-22 '
                . 'collaborator; a new nullable service dependency needs its own wiring pin here.',
        );

        self::assertArrayHasKey(
            'mintLimiter',
            $params,
            'ClientRelayTokenController::$mintLimiter is an optional dependency the container never '
                . 'supplies — PHP-DI skips defaulted parameters, so production would resolve it to null '
                . 'and the mint surface would answer with NO per-member budget guard. Add '
                . "`->parameter('mintLimiter', get(...))` to the ClientRelayTokenController definition.",
        );

        $limiterRef = $params['mintLimiter'];
        self::assertInstanceOf(
            Reference::class,
            $limiterRef,
            'mintLimiter must be a get() reference to the shared limiter instance, not a per-entry object definition',
        );
        self::assertSame(
            RateLimitProfiles::CLIENT_RELAY_TOKEN,
            $limiterRef->getTargetEntryName(),
            'mint must draw from the dedicated CLIENT_RELAY_TOKEN bucket — wiring the login-grade or '
                . 'another shared limiter here silently mis-shapes the budget this cure exists to set',
        );
    }
}
