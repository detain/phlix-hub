<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use Phlix\Hub\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for {@see Application}'s private route-template collapsing, which
 * keeps the per-route metrics table bounded by folding high-cardinality path
 * segments (numeric ids, UUIDs, long mixed tokens) to `{id}`.
 */
final class ApplicationRouteTemplateTest extends TestCase
{
    private function routeTemplate(string $path): string
    {
        $method = new ReflectionMethod(Application::class, 'routeTemplate');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke(null, $path);

        return $result;
    }

    public function testRootAndEmptyPathsMapToSlash(): void
    {
        $this->assertSame('/', $this->routeTemplate('/'));
        $this->assertSame('/', $this->routeTemplate(''));
    }

    public function testStableWordSegmentsArePreserved(): void
    {
        $this->assertSame('/api/v1/health', $this->routeTemplate('/api/v1/health'));
        $this->assertSame('/me/libraries', $this->routeTemplate('/me/libraries'));
    }

    public function testNumericSegmentIsCollapsed(): void
    {
        $this->assertSame('/api/v1/servers/{id}', $this->routeTemplate('/api/v1/servers/42'));
    }

    public function testUuidSegmentIsCollapsed(): void
    {
        $this->assertSame(
            '/api/v1/servers/{id}/detail',
            $this->routeTemplate('/api/v1/servers/550e8400-e29b-41d4-a716-446655440000/detail')
        );
    }

    public function testLongMixedTokenIsCollapsed(): void
    {
        // 8+ chars mixing letters and digits (e.g. a slug/hash) is treated as an id.
        $this->assertSame('/download/{id}', $this->routeTemplate('/download/abc12345xyz'));
    }

    public function testShortMixedTokensArePreserved(): void
    {
        // Under 8 chars — a stable path word even though it mixes letters + digits.
        $this->assertSame('/tv/s01e02', $this->routeTemplate('/tv/s01e02'));
        $this->assertSame('/video/1080p', $this->routeTemplate('/video/1080p'));
    }
}
