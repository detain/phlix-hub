<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * W5 unit coverage for the sparse-blob merge helpers behind the live
 * `server.rate_limit` setting: {@see CommonServicesProvider::overrideIntOr()}
 * and {@see CommonServicesProvider::effectiveSurface()} (both exercised via
 * reflection — they are private statics by design, wired inside the limiter
 * factories at container build).
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class RateLimitSurfaceMergeTest extends TestCase
{
    /**
     * @param array{key: string, max: int, window: int} $spec Profile row from RateLimitProfiles::defaults().
     * @param array<string, mixed> $override
     * @param array<string, mixed> $boot
     *
     * @return array{max: int, window: int}
     */
    private function effectiveSurface(array $spec, array $boot, array $override): array
    {
        $method = new ReflectionMethod(CommonServicesProvider::class, 'effectiveSurface');
        $method->setAccessible(true);

        /** @var array{max: int, window: int} $result */
        $result = $method->invoke(null, $spec, $boot, $override);

        return $result;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function overrideIntOr(array $override, string $key, int $default): int
    {
        $method = new ReflectionMethod(CommonServicesProvider::class, 'overrideIntOr');
        $method->setAccessible(true);

        /** @var int $result */
        $result = $method->invoke(null, $override, $key, $default);

        return $result;
    }

    /**
     * @return array{key: string, max: int, window: int}
     */
    private function spec(): array
    {
        return ['key' => 'login', 'max' => 5, 'window' => 900];
    }

    // ------------------------------------------------------ overrideIntOr

    public function testOverrideCountsOnlyPositiveNumerics(): void
    {
        self::assertSame(9, $this->overrideIntOr(['login' => 9], 'login', 1));
        self::assertSame(9, $this->overrideIntOr(['login' => '9'], 'login', 1), 'numeric string accepted');
    }

    public function testZeroAndNegativesAreIgnoredNotMerged(): void
    {
        // 0/negative in the DB blob is a corrupt row, not an operator
        // "disable" — it must not loosen a limiter to zero.
        self::assertSame(1, $this->overrideIntOr(['login' => 0], 'login', 1));
        self::assertSame(1, $this->overrideIntOr(['login' => -5], 'login', 1));
        self::assertSame(1, $this->overrideIntOr(['login' => 'soon'], 'login', 1));
        self::assertSame(1, $this->overrideIntOr([], 'login', 1));
    }

    // --------------------------------------------------- effectiveSurface

    public function testEmptyOverridePreservesBootThenSpec(): void
    {
        // Defaults-preservation: no blob -> boot section governs.
        $boot = ['login' => ['max' => 20, 'window' => 120]];
        self::assertSame(['max' => 20, 'window' => 120], $this->effectiveSurface($this->spec(), $boot, []));

        // Missing boot surface -> spec defaults.
        self::assertSame(['max' => 5, 'window' => 900], $this->effectiveSurface($this->spec(), [], []));
    }

    public function testOverrideWinsPerFieldIndependently(): void
    {
        $boot = ['login' => ['max' => 20, 'window' => 120]];

        // Sparse blob: only window overridden; max falls through to boot.
        self::assertSame(
            ['max' => 20, 'window' => 60],
            $this->effectiveSurface($this->spec(), $boot, ['login' => ['window' => 60]]),
        );

        // Only max overridden; window stays boot.
        self::assertSame(
            ['max' => 3, 'window' => 120],
            $this->effectiveSurface($this->spec(), $boot, ['login' => ['max' => 3]]),
        );
    }

    public function testNonArraySurfaceShapeDegradesSafely(): void
    {
        $boot = ['login' => 42];
        self::assertSame(
            ['max' => 5, 'window' => 900],
            $this->effectiveSurface($this->spec(), $boot, ['login' => 'nonsense']),
            'a non-array blob surface is ignored entirely',
        );
    }

    public function testUnrelatedSurfacesDoNotLeak(): void
    {
        $boot = ['proxy' => ['max' => 999, 'window' => 1]];
        self::assertSame(
            ['max' => 5, 'window' => 900],
            $this->effectiveSurface($this->spec(), $boot, []),
            'login spec unaffected by proxy boot values',
        );
    }
}
