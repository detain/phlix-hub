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
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * M-HUB-12 — the container can never ship the pairing limiter UNWIRED again.
 *
 * This is the PHP-DI optional-parameter trap (S62 law, same shape as
 * HubServicesProviderTest's IdleReaper gate): {@see ServerClaimController}'s
 * `$claimsLimiter` and `$audit` have `null` defaults, and PHP-DI SKIPS
 * defaulted constructor parameters. If the provider's `->parameter()` lines
 * were deleted, the controller would still construct, every hand-wired unit
 * test would still pass, and production would silently serve the claim
 * endpoints WITHOUT the budget guard — reintroducing the exact
 * hub__exploiter M1 finding this commit cures. Only a check at the DEFINITION
 * sees it, so this suite reads the real provider's definitions without ever
 * building a container (no DB, no worker boot).
 *
 * It goes one notch further than the IdleReaper precedent and pins the
 * TARGET of each reference: `claimsLimiter` must point at the SERVER_CLAIMS
 * container id specifically (a login-grade or shared-jwks limiter wired in by
 * a careless merge would pass a names-only check and silently mis-shape the
 * budget).
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class ServerClaimRateLimitWiringTest extends TestCase
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
        $params = self::factoryParametersFor(ServerClaimController::class);
        self::assertArrayHasKey(
            'handler',
            $params,
            'Could not read the ServerClaimController factory definition from HubServicesProvider — '
            . 'either the binding moved or PHP-DI internals changed. Fix the lookup, do not delete this suite.',
        );
    }

    public function testEveryNullableControllerDependencyIsExplicitlyWiredToTheRightEntry(): void
    {
        $params = self::factoryParametersFor(ServerClaimController::class);

        $nullableServiceParameters = [];
        $constructor = (new \ReflectionClass(ServerClaimController::class))->getConstructor();
        self::assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !$type->allowsNull()) {
                continue;
            }
            $nullableServiceParameters[] = $parameter->getName();
        }

        self::assertSame(
            ['claimsLimiter', 'audit'],
            $nullableServiceParameters,
            'ServerClaimController was expected to carry exactly the two optional M12 collaborators; '
            . 'a third nullable service dependency needs its own wiring pin here.',
        );

        foreach ($nullableServiceParameters as $name) {
            self::assertArrayHasKey(
                $name,
                $params,
                sprintf(
                    'ServerClaimController::$%s is an optional dependency the container never supplies — '
                    . 'PHP-DI skips defaulted parameters, so production would resolve it to null and the '
                    . 'claim surfaces would answer with NO budget guard (review hub__exploiter M1). Add '
                    . "`->parameter('%s', get(...))` to the ServerClaimController definition.",
                    $name,
                    $name,
                ),
            );
        }

        $limiterRef = $params['claimsLimiter'];
        self::assertInstanceOf(
            Reference::class,
            $limiterRef,
            'claimsLimiter must be a get() reference to the shared limiter instance, not a per-entry object definition',
        );
        self::assertSame(
            RateLimitProfiles::SERVER_CLAIMS,
            $limiterRef->getTargetEntryName(),
            'the claim surfaces must draw from the dedicated SERVER_CLAIMS bucket family — wiring the '
            . 'login-grade (5/900, would break 2 s pairing polls) or a shared anonymous limiter here silently '
            . 'mis-shapes the budget this commit exists to set',
        );

        $auditRef = $params['audit'];
        self::assertInstanceOf(Reference::class, $auditRef);
        self::assertSame(
            AuditLogger::class,
            $auditRef->getTargetEntryName(),
            'the first-trip audit transition writes through the same AuditLogger the handler uses',
        );

        // The handler reference stays exactly one hop away (regression anchor
        // for the definition rewrite, not a behavior claim).
        $handlerRef = $params['handler'];
        self::assertInstanceOf(Reference::class, $handlerRef);
        self::assertSame(ClaimRequestHandler::class, $handlerRef->getTargetEntryName());
    }

    public function testTheServerClaimsSurfaceIsRegisteredThroughTheProfileCatalogue(): void
    {
        // The limiter instance the reference above resolves to comes from
        // CommonServicesProvider's generic loop over RateLimitProfiles::
        // defaults(); pin the catalogue entry this wiring depends on.
        $defaults = RateLimitProfiles::defaults();
        self::assertArrayHasKey(RateLimitProfiles::SERVER_CLAIMS, $defaults);
        self::assertSame('server_claims', $defaults[RateLimitProfiles::SERVER_CLAIMS]['key']);
    }

    public function testConfigServerCarriesTheMatchingOverrideSurface(): void
    {
        // config/server.php resolves the whole rate_limit section through an
        // env closure; the override key must exist so an operator can retune
        // the pairing budget without a code change (absence would NOT fail at
        // runtime — defaults() would silently win — which is exactly the kind
        // of drift a definition-side pin catches).
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 5) . '/config/server.php';
        $rateLimit = $config['rate_limit'] ?? null;
        self::assertIsArray($rateLimit);
        $surface = $rateLimit['server_claims'] ?? null;
        self::assertIsArray($surface, 'config/server.php lost the rate_limit.server_claims surface');
        self::assertSame(60, $surface['max'] ?? null);
        self::assertSame(60, $surface['window'] ?? null);
    }
}
