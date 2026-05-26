<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests;

use Phlix\Hub\Version;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for {@see Version}.
 *
 * @package Phlix\Hub\Tests
 *
 * @covers \Phlix\Hub\Version
 */
final class VersionTest extends TestCase
{
    public function testVersionIsExactlyZeroPointOnePointZero(): void
    {
        self::assertSame('0.1.0', Version::VERSION);
    }

    public function testVersionIsValidSemver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(?:-[\w.-]+)?(?:\+[\w.-]+)?$/',
            Version::VERSION,
        );
    }

    public function testVersionMatchesChangelogHeading(): void
    {
        $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
        self::assertNotFalse($changelog, 'CHANGELOG.md must exist');
        self::assertStringContainsString('## [' . Version::VERSION . ']', $changelog);
    }

    public function testConstructorIsPrivate(): void
    {
        $reflection = new ReflectionClass(Version::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor, 'Version must declare a constructor.');
        self::assertTrue(
            $constructor->isPrivate(),
            'Version::__construct must be private to prevent instantiation.',
        );

        // Exercise the constructor through reflection so coverage reflects
        // the intentionally inert body.
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->setAccessible(true);
        $constructor->invoke($instance);
        self::assertInstanceOf(Version::class, $instance);
    }
}
