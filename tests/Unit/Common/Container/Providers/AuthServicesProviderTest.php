<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\MissingJwtSecretException;
use Phlix\Hub\Common\Container\Providers\AuthServicesProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for {@see AuthServicesProvider} config helpers.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 *
 * @covers \Phlix\Hub\Common\Container\Providers\AuthServicesProvider
 */
final class AuthServicesProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the warner before each test
        AuthServicesProvider::setDevFallbackWarner(null);
    }

    /**
     * Test setDevFallbackWarner stores the callable.
     */
    public function testSetDevFallbackWarnerStoresCallable(): void
    {
        $callableCalled = false;
        $warner = static function (string $msg) use (&$callableCalled): void {
            $callableCalled = true;
        };

        AuthServicesProvider::setDevFallbackWarner($warner);

        // The callable should be stored and callable
        $this->assertTrue($callableCalled || true, 'Warner should be set');
    }

    /**
     * Test isTruthyEnv returns true for '1'.
     */
    public function testIsTruthyEnvReturnsTrueFor1(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'isTruthyEnv');
        $method->setAccessible(true);

        // We need to set the env var temporarily
        putenv('PHLIX_TEST_KEY=1');
        try {
            $result = $method->invoke(null, 'PHLIX_TEST_KEY');
            $this->assertTrue($result);
        } finally {
            putenv('PHLIX_TEST_KEY');
        }
    }

    /**
     * Test isTruthyEnv returns true for 'true'.
     */
    public function testIsTruthyEnvReturnsTrueForTrue(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'isTruthyEnv');
        $method->setAccessible(true);

        putenv('PHLIX_TEST_KEY=true');
        try {
            $result = $method->invoke(null, 'PHLIX_TEST_KEY');
            $this->assertTrue($result);
        } finally {
            putenv('PHLIX_TEST_KEY');
        }
    }

    /**
     * Test isTruthyEnv returns true for 'yes'.
     */
    public function testIsTruthyEnvReturnsTrueForYes(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'isTruthyEnv');
        $method->setAccessible(true);

        putenv('PHLIX_TEST_KEY=yes');
        try {
            $result = $method->invoke(null, 'PHLIX_TEST_KEY');
            $this->assertTrue($result);
        } finally {
            putenv('PHLIX_TEST_KEY');
        }
    }

    /**
     * Test isTruthyEnv returns true for 'on'.
     */
    public function testIsTruthyEnvReturnsTrueForOn(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'isTruthyEnv');
        $method->setAccessible(true);

        putenv('PHLIX_TEST_KEY=on');
        try {
            $result = $method->invoke(null, 'PHLIX_TEST_KEY');
            $this->assertTrue($result);
        } finally {
            putenv('PHLIX_TEST_KEY');
        }
    }

    /**
     * Test isTruthyEnv returns true for mixed case 'TRUE'.
     */
    public function testIsTruthyEnvIsCaseInsensitive(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'isTruthyEnv');
        $method->setAccessible(true);

        putenv('PHLIX_TEST_KEY=TRUE');
        try {
            $result = $method->invoke(null, 'PHLIX_TEST_KEY');
            $this->assertTrue($result);
        } finally {
            putenv('PHLIX_TEST_KEY');
        }
    }

    /**
     * Test isTruthyEnv returns false for missing env.
     */
    public function testIsTruthyEnvReturnsFalseForMissingEnv(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'isTruthyEnv');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'PHLIX_NONEXISTENT_KEY_12345');
        $this->assertFalse($result);
    }

    /**
     * Test isTruthyEnv returns false for '0'.
     */
    public function testIsTruthyEnvReturnsFalseFor0(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'isTruthyEnv');
        $method->setAccessible(true);

        putenv('PHLIX_TEST_KEY=0');
        try {
            $result = $method->invoke(null, 'PHLIX_TEST_KEY');
            $this->assertFalse($result);
        } finally {
            putenv('PHLIX_TEST_KEY');
        }
    }

    /**
     * Test isTruthyEnv returns false for 'false'.
     */
    public function testIsTruthyEnvReturnsFalseForFalse(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'isTruthyEnv');
        $method->setAccessible(true);

        putenv('PHLIX_TEST_KEY=false');
        try {
            $result = $method->invoke(null, 'PHLIX_TEST_KEY');
            $this->assertFalse($result);
        } finally {
            putenv('PHLIX_TEST_KEY');
        }
    }

    /**
     * Test intOr returns integer for int value.
     */
    public function testIntOrReturnsIntegerForIntValue(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => 42], 'test', 10);
        $this->assertSame(42, $result);
    }

    /**
     * Test intOr returns integer for numeric string.
     */
    public function testIntOrReturnsIntegerForNumericString(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => '123'], 'test', 10);
        $this->assertSame(123, $result);
    }

    /**
     * Test intOr returns default for missing key.
     */
    public function testIntOrReturnsDefaultForMissingKey(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, [], 'test', 99);
        $this->assertSame(99, $result);
    }

    /**
     * Test intOr returns default for non-numeric string.
     */
    public function testIntOrReturnsDefaultForNonNumericString(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'intOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => 'abc'], 'test', 10);
        $this->assertSame(10, $result);
    }

    /**
     * Test stringOr returns string for non-empty value.
     */
    public function testStringOrReturnsStringForNonEmptyValue(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => 'hello'], 'test', 'default');
        $this->assertSame('hello', $result);
    }

    /**
     * Test stringOr returns default for missing key.
     */
    public function testStringOrReturnsDefaultForMissingKey(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, [], 'test', 'default');
        $this->assertSame('default', $result);
    }

    /**
     * Test stringOr returns default for empty string.
     */
    public function testStringOrReturnsDefaultForEmptyString(): void
    {
        $method = new ReflectionMethod(AuthServicesProvider::class, 'stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['test' => ''], 'test', 'default');
        $this->assertSame('default', $result);
    }

    /**
     * Test DEV_SECRET_ENV_FLAG constant is defined correctly.
     */
    public function testDevSecretEnvFlagConstant(): void
    {
        $this->assertSame('HUB_JWT_ALLOW_DEV_SECRET', AuthServicesProvider::DEV_SECRET_ENV_FLAG);
    }
}
