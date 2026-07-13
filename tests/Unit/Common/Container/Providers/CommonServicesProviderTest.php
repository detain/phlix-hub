<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use Phlix\Hub\Common\RateLimit\DbRateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Verifies {@see CommonServicesProvider} registers one limiter per surface
 * (never `set()`) with the correct per-worker `(max, window)`, that config
 * overrides take effect, that surfaces get DISTINCT instances, and — after
 * HB-4.6 Option B — that the `login` surface resolves to the shared, DB-backed
 * {@see DbRateLimiter} while the other five stay in-memory {@see RateLimiter}s,
 * and that the legacy {@see RateLimiterInterface} binding still resolves (to the
 * login profile) for {@see \Phlix\Hub\Auth\AuthManager}.
 */
#[CoversClass(CommonServicesProvider::class)]
#[CoversClass(RateLimitProfiles::class)]
final class CommonServicesProviderTest extends TestCase
{
    /**
     * The five worker-local surfaces resolve to an in-memory RateLimiter with
     * their documented per-worker (max, window) default. (login is DB-backed —
     * see {@see testLoginProfileResolvesToSharedDbRateLimiter}.)
     */
    public function testInMemorySurfacesResolveWithExpectedThresholds(): void
    {
        $container = $this->buildContainer([]);

        $expected = [
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
     * HB-4.6 Option B: the login profile is the shared, DB-backed limiter
     * (unifies the 5 / 900s bucket across HUB_WORKERS), NOT the in-memory one.
     */
    public function testLoginProfileResolvesToSharedDbRateLimiter(): void
    {
        $container = $this->buildContainer([]);

        $login = $container->get(RateLimitProfiles::LOGIN);
        self::assertInstanceOf(DbRateLimiter::class, $login);
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
     * A config/server.php override of a surface's {max, window} takes effect,
     * and untouched surfaces (in-memory AND the DB-backed login) keep defaults.
     */
    public function testConfigOverridePerSurfaceIsApplied(): void
    {
        $container = $this->buildContainer([
            'rate_limit' => [
                'proxy' => ['max' => 7, 'window' => 42],
            ],
        ]);

        $proxy = $container->get(RateLimitProfiles::PROXY);
        self::assertInstanceOf(RateLimiter::class, $proxy);
        $this->assertMaxAndWindow($proxy, 7, 42, RateLimitProfiles::PROXY);

        // Untouched in-memory surface keeps its default.
        $heartbeat = $container->get(RateLimitProfiles::HEARTBEAT);
        self::assertInstanceOf(RateLimiter::class, $heartbeat);
        $this->assertMaxAndWindow($heartbeat, 30, 60, RateLimitProfiles::HEARTBEAT);

        // Untouched DB-backed login keeps its default.
        $login = $container->get(RateLimitProfiles::LOGIN);
        self::assertInstanceOf(DbRateLimiter::class, $login);
        $this->assertMaxAndWindow($login, 5, 900, RateLimitProfiles::LOGIN);
    }

    /**
     * A config override of the login {max, window} flows into the DbRateLimiter.
     */
    public function testLoginConfigOverrideIsApplied(): void
    {
        $container = $this->buildContainer([
            'rate_limit' => [
                'login' => ['max' => 9, 'window' => 120],
            ],
        ]);

        $login = $container->get(RateLimitProfiles::LOGIN);
        self::assertInstanceOf(DbRateLimiter::class, $login);
        $this->assertMaxAndWindow($login, 9, 120, RateLimitProfiles::LOGIN);
    }

    /**
     * Legacy binding: the {@see RateLimiterInterface} still resolves — to the
     * login profile (now the shared {@see DbRateLimiter}) — so AuthManager keeps
     * booting. The concrete {@see RateLimiter} class alias is intentionally
     * dropped (aliasing it to a DbRateLimiter would be a type lie); requesting
     * the concrete class autowires a fresh, unrelated in-memory instance.
     */
    public function testLegacyInterfaceResolvesToLoginProfile(): void
    {
        $container = $this->buildContainer([]);

        $viaInterface = $container->get(RateLimiterInterface::class);
        $login = $container->get(RateLimitProfiles::LOGIN);

        self::assertInstanceOf(DbRateLimiter::class, $viaInterface);
        self::assertSame($login, $viaInterface, 'The interface must alias the login profile.');

        // The concrete class is NOT aliased to login anymore.
        $viaConcrete = $container->get(RateLimiter::class);
        self::assertInstanceOf(RateLimiter::class, $viaConcrete);
        self::assertNotSame($login, $viaConcrete, 'Concrete RateLimiter is no longer the login profile.');
    }

    /**
     * Assert a container-built limiter has the given max and window.
     *
     * `max` is read exactly from `peek()->limit`; `window` is inferred from the
     * `resetAt` of a fresh `hit()` against a captured timestamp (range-checked
     * to absorb a second-boundary roll with the default time() clock). Works for
     * both the in-memory {@see RateLimiter} and the DB-backed {@see DbRateLimiter}
     * (the mock Connection returns no row, so a fresh hit reports resetAt =
     * now + window).
     */
    private function assertMaxAndWindow(
        RateLimiterInterface $limiter,
        int $max,
        int $window,
        string $label,
    ): void {
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

        // The login profile is a DbRateLimiter that autowires a Connection; the
        // mock returns no rows so peek() is empty and a fresh hit() reports
        // resetAt = now + window (enough to assert the thresholds).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $builder->addDefinitions([Connection::class => $db]);

        return $builder->build();
    }
}
