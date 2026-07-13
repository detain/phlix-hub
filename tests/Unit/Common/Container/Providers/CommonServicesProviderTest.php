<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies {@see CommonServicesProvider} registers one {@see RateLimiter} per
 * surface (never `set()`) with the correct per-worker `(max, window)`, that
 * config overrides take effect, that surfaces get DISTINCT instances, and that
 * the legacy {@see RateLimiterInterface} binding still resolves (to the login
 * profile) for back-compat while call sites are migrated (HB-4.6a).
 */
#[CoversClass(CommonServicesProvider::class)]
#[CoversClass(RateLimitProfiles::class)]
final class CommonServicesProviderTest extends TestCase
{
    /**
     * Every named surface resolves to a RateLimiter with its documented
     * per-worker (max, window) default.
     */
    public function testEachSurfaceResolvesWithExpectedThresholds(): void
    {
        $container = $this->buildContainer([]);

        $expected = [
            RateLimitProfiles::LOGIN         => [5, 900],
            RateLimitProfiles::PROXY         => [600, 60],
            RateLimitProfiles::HEARTBEAT     => [30, 60],
            RateLimitProfiles::JWKS          => [120, 60],
            RateLimitProfiles::RELAY_CONNECT => [10, 60],
            RateLimitProfiles::CLIENT_MOUNT  => [30, 60],
        ];

        foreach ($expected as $id => [$max, $window]) {
            $limiter = $container->get($id);
            self::assertInstanceOf(RateLimiter::class, $limiter, $id);
            $this->assertMaxAndWindow($limiter, $max, $window, $id);
        }
    }

    /**
     * The login profile keeps the historical 5 / 900s limiter.
     */
    public function testLoginProfileIsFivePerFifteenMinutes(): void
    {
        $container = $this->buildContainer([]);

        /** @var RateLimiter $login */
        $login = $container->get(RateLimitProfiles::LOGIN);
        $this->assertMaxAndWindow($login, 5, 900, RateLimitProfiles::LOGIN);
    }

    /**
     * No two surfaces share the same instance (a shared window object would
     * cross-contaminate unrelated traffic).
     */
    public function testSurfacesAreDistinctInstances(): void
    {
        $container = $this->buildContainer([]);

        $objectIds = [];
        foreach (array_keys(RateLimitProfiles::defaults()) as $id) {
            $instance = $container->get($id);
            self::assertIsObject($instance, $id);
            $objectIds[] = spl_object_id($instance);
        }

        self::assertSameSize($objectIds, array_unique($objectIds));
    }

    /**
     * A config/server.php override of a surface's {max, window} takes effect.
     */
    public function testConfigOverridePerSurfaceIsApplied(): void
    {
        $container = $this->buildContainer([
            'rate_limit' => [
                'proxy' => ['max' => 7, 'window' => 42],
            ],
        ]);

        /** @var RateLimiter $proxy */
        $proxy = $container->get(RateLimitProfiles::PROXY);
        $this->assertMaxAndWindow($proxy, 7, 42, RateLimitProfiles::PROXY);

        // Untouched surfaces keep their defaults.
        /** @var RateLimiter $login */
        $login = $container->get(RateLimitProfiles::LOGIN);
        $this->assertMaxAndWindow($login, 5, 900, RateLimitProfiles::LOGIN);
    }

    /**
     * Legacy binding: RateLimiterInterface (and the concrete RateLimiter) still
     * resolve — to the login profile — so un-migrated call sites keep booting.
     */
    public function testLegacyInterfaceResolvesToLoginProfile(): void
    {
        $container = $this->buildContainer([]);

        $viaInterface = $container->get(RateLimiterInterface::class);
        $viaConcrete = $container->get(RateLimiter::class);
        $login = $container->get(RateLimitProfiles::LOGIN);

        self::assertInstanceOf(RateLimiter::class, $viaInterface);
        self::assertSame($login, $viaInterface);
        self::assertSame($login, $viaConcrete);
    }

    /**
     * Assert a container-built RateLimiter has the given max and window.
     *
     * `max` is read exactly from `peek()->limit`; `window` is inferred from the
     * `resetAt` of a fresh `hit()` against a captured timestamp (range-checked
     * to absorb a second-boundary roll with the default time() clock).
     */
    private function assertMaxAndWindow(RateLimiter $limiter, int $max, int $window, string $label): void
    {
        self::assertSame($max, $limiter->peek('probe')->limit, $label . ' max');

        $before = time();
        $resetAt = $limiter->hit('window-probe')->resetAt;
        $after = time();

        self::assertGreaterThanOrEqual($before + $window, $resetAt, $label . ' window lower');
        self::assertLessThanOrEqual($after + $window, $resetAt, $label . ' window upper');
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function buildContainer(array $appConfig): Container
    {
        $builder = new ContainerBuilder();
        (new CommonServicesProvider())->register($builder, $appConfig);

        return $builder->build();
    }
}
