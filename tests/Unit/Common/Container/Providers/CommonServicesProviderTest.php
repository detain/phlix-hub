<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies {@see CommonServicesProvider} wires the rate limiter via the
 * container (never `set()`), binds the interface to the concrete worker-
 * local implementation, and resolves both type-hints to the SAME singleton
 * per worker.
 */
#[CoversClass(CommonServicesProvider::class)]
final class CommonServicesProviderTest extends TestCase
{
    public function testInterfaceResolvesToWorkerLocalRateLimiter(): void
    {
        $container = $this->buildContainer([]);

        $limiter = $container->get(RateLimiterInterface::class);
        self::assertInstanceOf(RateLimiter::class, $limiter);
    }

    public function testInterfaceAndConcreteShareTheSameSingleton(): void
    {
        $container = $this->buildContainer([]);

        $viaInterface = $container->get(RateLimiterInterface::class);
        $viaConcrete = $container->get(RateLimiter::class);

        self::assertSame($viaConcrete, $viaInterface);
    }

    public function testConfigOverridesAreApplied(): void
    {
        $container = $this->buildContainer([
            'rate_limit' => [
                'window_seconds' => 30,
                'max_attempts' => 2,
                'cap' => 4,
            ],
        ]);

        /** @var RateLimiter $limiter */
        $limiter = $container->get(RateLimiter::class);

        // max_attempts=2 → the second hit trips the limit.
        $limiter->hit('k');
        self::assertTrue($limiter->hit('k')->limited);
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function buildContainer(array $appConfig): \DI\Container
    {
        $builder = new ContainerBuilder();
        (new CommonServicesProvider())->register($builder, $appConfig);

        return $builder->build();
    }
}
