<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\Providers\MetricsServicesProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for {@see MetricsServicesProvider} config helpers.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 *
 * @covers \Phlix\Hub\Common\Container\Providers\MetricsServicesProvider
 */
final class MetricsServicesProviderTest extends TestCase
{
    private MetricsServicesProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new MetricsServicesProvider();
    }

    /**
     * Test cfgBool with boolean true.
     */
    public function testCfgBoolReturnsTrueForBooleanTrue(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => true], 'test', false);
        $this->assertTrue($result);
    }

    /**
     * Test cfgBool with boolean false.
     */
    public function testCfgBoolReturnsFalseForBooleanFalse(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => false], 'test', true);
        $this->assertFalse($result);
    }

    /**
     * Test cfgBool with integer 1.
     */
    public function testCfgBoolReturnsTrueForInteger1(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => 1], 'test', false);
        $this->assertTrue($result);
    }

    /**
     * Test cfgBool with integer 0.
     */
    public function testCfgBoolReturnsFalseForInteger0(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => 0], 'test', true);
        $this->assertFalse($result);
    }

    /**
     * Test cfgBool with string '1'.
     */
    public function testCfgBoolReturnsTrueForString1(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => '1'], 'test', false);
        $this->assertTrue($result);
    }

    /**
     * Test cfgBool with string 'true'.
     */
    public function testCfgBoolReturnsTrueForStringTrue(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => 'true'], 'test', false);
        $this->assertTrue($result);
    }

    /**
     * Test cfgBool with string 'yes'.
     */
    public function testCfgBoolReturnsTrueForStringYes(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => 'yes'], 'test', false);
        $this->assertTrue($result);
    }

    /**
     * Test cfgBool with string 'on'.
     */
    public function testCfgBoolReturnsTrueForStringOn(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => 'on'], 'test', false);
        $this->assertTrue($result);
    }

    /**
     * Test cfgBool with missing key returns default.
     */
    public function testCfgBoolReturnsDefaultWhenKeyMissing(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, [], 'test', true);
        $this->assertTrue($result);

        $result = $method->invoke($this->provider, [], 'test', false);
        $this->assertFalse($result);
    }

    /**
     * Test cfgBool with string 'false' returns false (not in truthy list).
     */
    public function testCfgBoolReturnsFalseForStringFalse(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgBool');
        $method->setAccessible(true);

        // 'false' is not in ['1', 'true', 'yes', 'on'], so in_array returns false
        $result = $method->invoke($this->provider, ['test' => 'false'], 'test', true);
        $this->assertFalse($result);
    }

    /**
     * Test cfgInt with integer value.
     */
    public function testCfgIntReturnsIntegerForIntValue(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgInt');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => 42], 'test', 10);
        $this->assertSame(42, $result);
    }

    /**
     * Test cfgInt with numeric string.
     */
    public function testCfgIntReturnsIntegerForNumericString(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgInt');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => '123'], 'test', 10);
        $this->assertSame(123, $result);
    }

    /**
     * Test cfgInt with missing key returns default.
     */
    public function testCfgIntReturnsDefaultWhenKeyMissing(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgInt');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, [], 'test', 99);
        $this->assertSame(99, $result);
    }

    /**
     * Test cfgInt with non-numeric string returns default.
     */
    public function testCfgIntReturnsDefaultForNonNumericString(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgInt');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => 'abc'], 'test', 10);
        $this->assertSame(10, $result);
    }

    /**
     * Test cfgIntList with valid integer array.
     */
    public function testCfgIntListReturnsFilteredIntegers(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgIntList');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => [10, 50, 100]], 'test', [1, 2, 3]);
        $this->assertSame([10, 50, 100], $result);
    }

    /**
     * Test cfgIntList with mixed types filters correctly.
     */
    public function testCfgIntListFiltersMixedTypes(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgIntList');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, ['test' => [10, '50', 3.14, '100', null, false]], 'test', [1, 2, 3]);
        $this->assertSame([10, 50, 3, 100], $result);
    }

    /**
     * Test cfgIntList with empty array returns default.
     */
    public function testCfgIntListReturnsDefaultForEmptyArray(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgIntList');
        $method->setAccessible(true);

        $default = [1, 2, 3];
        $result = $method->invoke($this->provider, ['test' => []], 'test', $default);
        $this->assertSame($default, $result);
    }

    /**
     * Test cfgIntList with non-array returns default.
     */
    public function testCfgIntListReturnsDefaultForNonArray(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'cfgIntList');
        $method->setAccessible(true);

        $default = [1, 2, 3];
        $result = $method->invoke($this->provider, ['test' => 'not-an-array'], 'test', $default);
        $this->assertSame($default, $result);
    }

    /**
     * Test resolveConfig uses appConfig metrics when provided.
     */
    public function testResolveConfigPrefersAppConfigMetrics(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'resolveConfig');
        $method->setAccessible(true);

        $appConfig = [
            'metrics' => [
                'enabled' => true,
                'bucket_seconds' => 20,
            ],
        ];

        $result = $method->invoke($this->provider, $appConfig);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertTrue($result['enabled']);
    }

    /**
     * Test resolveConfig falls back to empty array when no config.
     */
    public function testResolveConfigReturnsEmptyArrayWhenNoConfig(): void
    {
        $method = new ReflectionMethod(MetricsServicesProvider::class, 'resolveConfig');
        $method->setAccessible(true);

        $result = $method->invoke($this->provider, []);
        $this->assertIsArray($result);
    }
}
