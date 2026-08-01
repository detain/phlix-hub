<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\Providers\HttpServicesProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for {@see HttpServicesProvider} config helpers.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 *
 * @covers \Phlix\Hub\Common\Container\Providers\HttpServicesProvider
 */
final class HttpServicesProviderTest extends TestCase
{
    /**
     * Test stringOr returns string for non-empty value.
     */
    public function testStringOrReturnsStringForNonEmptyValue(): void
    {
        $method = new ReflectionMethod(HttpServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => 'hello'], 'test', 'default');
        $this->assertSame('hello', $result);
    }

    /**
     * Test stringOr returns default for missing key.
     */
    public function testStringOrReturnsDefaultForMissingKey(): void
    {
        $method = new ReflectionMethod(HttpServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, [], 'test', 'default');
        $this->assertSame('default', $result);
    }

    /**
     * Test stringOr returns default for empty string.
     */
    public function testStringOrReturnsDefaultForEmptyString(): void
    {
        $method = new ReflectionMethod(HttpServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => ''], 'test', 'default');
        $this->assertSame('default', $result);
    }

    /**
     * Test stringOr returns default for non-string value (int).
     */
    public function testStringOrReturnsDefaultForInt(): void
    {
        $method = new ReflectionMethod(HttpServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => 123], 'test', 'default');
        $this->assertSame('default', $result);
    }

    /**
     * Test stringOr returns default for null.
     */
    public function testStringOrReturnsDefaultForNull(): void
    {
        $method = new ReflectionMethod(HttpServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => null], 'test', 'default');
        $this->assertSame('default', $result);
    }

    /**
     * Test stringOr preserves whitespace in valid strings.
     */
    public function testStringOrPreservesWhitespace(): void
    {
        $method = new ReflectionMethod(HttpServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => 'hello world'], 'test', 'default');
        $this->assertSame('hello world', $result);
    }
}
