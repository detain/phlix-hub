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
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Http\Controllers\InviteLinkController;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Minors campaign M-HUB-21 — the container can never ship the redeem budget
 * UNWIRED again.
 *
 * The PHP-DI optional-parameter trap (S62 law, same shape as
 * ServerClaimRateLimitWiringTest): {@see InviteLinkController}'s
 * `$redeemLimiter` and `$audit` carry `null` defaults and PHP-DI SKIPS
 * defaulted constructor parameters. Delete the provider's `->parameter()`
 * lines and every hand-wired unit test still passes while production silently
 * serves redeem WITHOUT the per-member budget — reintroducing exactly the
 * unbilled-grind path this cure exists to close. Only a check at the
 * DEFINITION sees it, so this suite reads the real provider's definitions
 * without ever building a container (no DB, no worker boot).
 *
 * Like the SERVER_CLAIMS precedent it pins the TARGET of each reference:
 * `redeemLimiter` must point at the INVITE_REDEEM container id specifically —
 * a login-grade or proxy limiter wired in by a careless merge would pass a
 * names-only check and silently mis-shape the budget.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class InviteLinkRedeemWiringTest extends TestCase
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
        $params = self::factoryParametersFor(InviteLinkController::class);
        self::assertArrayHasKey(
            'handler',
            $params,
            'Could not read the InviteLinkController factory definition from HubServicesProvider — '
                . 'either the binding moved or PHP-DI internals changed. Fix the lookup, do not delete this suite.',
        );
    }

    public function testEveryNullableControllerDependencyIsExplicitlyWiredToTheRightEntry(): void
    {
        $params = self::factoryParametersFor(InviteLinkController::class);

        $nullableServiceParameters = [];
        $constructor = (new \ReflectionClass(InviteLinkController::class))->getConstructor();
        self::assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !$type->allowsNull()) {
                continue;
            }
            $nullableServiceParameters[] = $parameter->getName();
        }

        self::assertSame(
            ['redeemLimiter', 'audit'],
            $nullableServiceParameters,
            'InviteLinkController was expected to carry exactly the two optional M-HUB-21 collaborators; '
                . 'a third nullable service dependency needs its own wiring pin here.',
        );

        foreach ($nullableServiceParameters as $name) {
            self::assertArrayHasKey(
                $name,
                $params,
                sprintf(
                    'InviteLinkController::$%s is an optional dependency the container never supplies — '
                        . 'PHP-DI skips defaulted parameters, so production would resolve it to null and the '
                        . 'redeem surface would answer with NO per-member budget guard. Add '
                        . "`->parameter('%s', get(...))` to the InviteLinkController definition.",
                    $name,
                    $name,
                ),
            );
        }

        $limiterRef = $params['redeemLimiter'];
        self::assertInstanceOf(
            Reference::class,
            $limiterRef,
            'redeemLimiter must be a get() reference to the shared limiter instance, not a per-entry object definition',
        );
        self::assertSame(
            RateLimitProfiles::INVITE_REDEEM,
            $limiterRef->getTargetEntryName(),
            'redeem must draw from the dedicated INVITE_REDEEM bucket family — wiring the login-grade '
                . '(5/900, would punish a household redeeming several invites) or a shared anonymous '
                . 'limiter here silently mis-shapes the budget this cure exists to set',
        );

        $auditRef = $params['audit'];
        self::assertInstanceOf(Reference::class, $auditRef);
        self::assertSame(
            AuditLogger::class,
            $auditRef->getTargetEntryName(),
            'the first-trip audit transition writes through the same AuditLogger the rest of the portal uses',
        );

        // The handler reference stays exactly one hop away (regression anchor
        // for the definition rewrite, not a behavior claim).
        $handlerRef = $params['handler'];
        self::assertInstanceOf(Reference::class, $handlerRef);
        self::assertSame(InviteLinkHandler::class, $handlerRef->getTargetEntryName());
    }
}
