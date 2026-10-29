<?php

/**
 * Phlix hub test: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * M-HUB-14 (review hub__exploiter M3) — the two federation limiter surfaces
 * must actually materialise from configuration, on the real registration
 * path, not just in the defaults table.
 *
 * The profiles test pins `defaults()`; the worker/leaf suites pin behaviour
 * against injected instances. The seam NONE of those sees is
 * {@see CommonServicesProvider}'s `defaults()`-loop wiring: a profile whose
 * config surface key disagrees with its spec key, or a surface silently
 * dropped from `config/server.php`, would leave production running the spec
 * default (harmless) OR an absent bucket shape (the M-HUB-12-adjacent failure
 * class). This suite therefore (a) builds the real container and reads back
 * the constructed RateLimiter's private knobs, with a config override flowing
 * end-to-end (the teeth-on-knobs proof), and (b) pins the committed
 * `config/server.php` surfaces literally.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class FederationRateLimitWiringTest extends TestCase
{
    /**
     * Without any config override, the registered limiters carry the profile
     * defaults (10/60 and 120/60) — the numbers the whole r1 design was
     * sized on.
     */
    public function testRegisteredFederationLimitersCarryProfileDefaults(): void
    {
        $container = $this->buildWith([]);

        $connect = $this->limiterFor($container, RateLimitProfiles::FEDERATION_CONNECT);
        self::assertSame(10, self::knob($connect, 'maxAttempts'));
        self::assertSame(60, self::knob($connect, 'windowSeconds'));

        $frame = $this->limiterFor($container, RateLimitProfiles::FEDERATION_FRAME);
        self::assertSame(120, self::knob($frame, 'maxAttempts'));
        self::assertSame(60, self::knob($frame, 'windowSeconds'));
    }

    /**
     * Teeth-on-knobs: a `rate_limit` override section in the boot config
     * must reach the CONSTRUCTED limiters through the provider's
     * defaults()-loop merge — proving the federation surfaces participate in
     * the same override law as every sibling (ops can retune the :8805
     * budget without a code change; a surface the loop forgot would keep the
     * baked default and redden this pin).
     */
    public function testConfigOverridesFlowThroughToConstructedLimiters(): void
    {
        $container = $this->buildWith([
            'rate_limit' => [
                'federation_connect' => ['max' => 2, 'window' => 7],
                'federation_frame'   => ['max' => 41, 'window' => 13],
            ],
        ]);

        $connect = $this->limiterFor($container, RateLimitProfiles::FEDERATION_CONNECT);
        self::assertSame(2, self::knob($connect, 'maxAttempts'));
        self::assertSame(7, self::knob($connect, 'windowSeconds'));

        $frame = $this->limiterFor($container, RateLimitProfiles::FEDERATION_FRAME);
        self::assertSame(41, self::knob($frame, 'maxAttempts'));
        self::assertSame(13, self::knob($frame, 'windowSeconds'));
    }

    /**
     * The shared-instance law the leaf install depends on: each container id
     * resolves to ONE per-worker singleton — FederationWorker's frameLimiter
     * (master-side hits) and the instance it installs into the peer manager
     * (leaf-side hits) are the SAME counters, which is what makes
     * "one budget per remote peer, both roles" true rather than decorative.
     * Surfaces must also stay distinct from each other (window law).
     */
    public function testFederationProfilesAreSingletonsAndDistinct(): void
    {
        $container = $this->buildWith([]);

        self::assertSame(
            $container->get(RateLimitProfiles::FEDERATION_FRAME),
            $container->get(RateLimitProfiles::FEDERATION_FRAME),
            'PHP-DI singleton law: the leaf install must receive the SAME object',
        );

        self::assertNotSame(
            $container->get(RateLimitProfiles::FEDERATION_CONNECT),
            $container->get(RateLimitProfiles::FEDERATION_FRAME),
            'no two surfaces share a counter (HB-4.6a)',
        );
    }

    /**
     * The committed config/server.php carries both federation surfaces at
     * the r1-prepped values — the file the operator edits, read directly.
     */
    public function testCommittedConfigCarriesBothFederationSurfaces(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 5) . '/config/server.php';

        $section = $config['rate_limit'] ?? null;
        self::assertIsArray($section);

        $expected = [
            'federation_connect' => [10, 60],
            'federation_frame'   => [120, 60],
        ];
        foreach ($expected as $key => [$max, $window]) {
            $surface = $section[$key] ?? null;
            self::assertIsArray($surface, "config/server.php lost the rate_limit.$key surface");
            self::assertSame($max, $surface['max'], "rate_limit.$key.max drifted");
            self::assertSame($window, $surface['window'], "rate_limit.$key.window drifted");
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $appConfig */
    private function buildWith(array $appConfig): \Psr\Container\ContainerInterface
    {
        $builder = new ContainerBuilder();
        (new CommonServicesProvider())->register($builder, $appConfig);

        return $builder->build();
    }

    /** Narrow the container lookup at the boundary (phpstan does not trust static assertInstanceOf). */
    private function limiterFor(\Psr\Container\ContainerInterface $container, string $id): RateLimiter
    {
        $limiter = $container->get($id);
        self::assertInstanceOf(RateLimiter::class, $limiter);

        return $limiter;
    }

    private static function knob(RateLimiter $limiter, string $name): int
    {
        /** @var int $value */
        $value = (new ReflectionProperty(RateLimiter::class, $name))->getValue($limiter);

        return $value;
    }
}
