<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for {@see CommonServicesProvider} config helpers.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class CommonServicesProviderTest extends TestCase
{
    /**
     * Test intOr returns integer for int value.
     */
    public function testIntOrReturnsIntegerForIntValue(): void
    {
        $method = new ReflectionMethod(CommonServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => 42], 'test', 10);
        $this->assertSame(42, $result);
    }

    /**
     * Test intOr returns integer for numeric string.
     */
    public function testIntOrReturnsIntegerForNumericString(): void
    {
        $method = new ReflectionMethod(CommonServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => '123'], 'test', 10);
        $this->assertSame(123, $result);
    }

    /**
     * Test intOr returns default for missing key.
     */
    public function testIntOrReturnsDefaultForMissingKey(): void
    {
        $method = new ReflectionMethod(CommonServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, [], 'test', 99);
        $this->assertSame(99, $result);
    }

    /**
     * Test intOr returns default for non-numeric string.
     */
    public function testIntOrReturnsDefaultForNonNumericString(): void
    {
        $method = new ReflectionMethod(CommonServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => 'abc'], 'test', 10);
        $this->assertSame(10, $result);
    }

    /**
     * Test intOr returns default for null.
     */
    public function testIntOrReturnsDefaultForNull(): void
    {
        $method = new ReflectionMethod(CommonServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => null], 'test', 10);
        $this->assertSame(10, $result);
    }

    /**
     * Test intOr returns default for array.
     */
    public function testIntOrReturnsDefaultForArray(): void
    {
        $method = new ReflectionMethod(CommonServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => [1, 2, 3]], 'test', 10);
        $this->assertSame(10, $result);
    }
}
