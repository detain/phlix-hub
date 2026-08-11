<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Container\Providers\AuthServicesProvider;
use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use Phlix\Hub\Common\Container\Providers\CoreServicesProvider;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Container\Providers\HttpServicesProvider;
use Phlix\Hub\Common\Container\Providers\MetricsServicesProvider;
use Phlix\Hub\Common\Container\ServiceProviderInterface;

/**
 * Unit tests for {@see ContainerFactory}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container
 */
final class ContainerFactoryTest extends TestCase
{
    public function testDefaultCompileDirConstant(): void
    {
        self::assertSame('var/cache/container', ContainerFactory::DEFAULT_COMPILE_DIR);
    }

    public function testDefaultProvidersReturnsAllSixProviders(): void
    {
        $providers = ContainerFactory::defaultProviders();

        self::assertCount(6, $providers);

        // Verify each provider is the correct instance type
        self::assertInstanceOf(CoreServicesProvider::class, $providers[0]);
        self::assertInstanceOf(CommonServicesProvider::class, $providers[1]);
        self::assertInstanceOf(AuthServicesProvider::class, $providers[2]);
        self::assertInstanceOf(HttpServicesProvider::class, $providers[3]);
        self::assertInstanceOf(MetricsServicesProvider::class, $providers[4]);
        self::assertInstanceOf(HubServicesProvider::class, $providers[5]);
    }

    public function testDefaultProvidersReturnsArrayOfServiceProviderInterface(): void
    {
        $providers = ContainerFactory::defaultProviders();

        foreach ($providers as $provider) {
            self::assertInstanceOf(ServiceProviderInterface::class, $provider);
        }
    }

    public function testShouldCompileReturnsFalseWhenEnvNotSet(): void
    {
        // Save original value
        $originalValue = getenv('PHLIX_HUB_CONTAINER_COMPILE');

        // Ensure env is not set
        putenv('PHLIX_HUB_CONTAINER_COMPILE');

        try {
            // Use reflection to test private method
            $reflection = new \ReflectionClass(ContainerFactory::class);
            $method = $reflection->getMethod('shouldCompile');
            $method->setAccessible(true);

            self::assertFalse($method->invoke(null));
        } finally {
            // Restore original environment
            if ($originalValue !== false) {
                putenv('PHLIX_HUB_CONTAINER_COMPILE=' . $originalValue);
            }
        }
    }

    public function testShouldCompileReturnsTrueForVariousTruthyValues(): void
    {
        $truthyValues = ['1', 'true', 'yes', 'on', 'TRUE', 'Yes', 'ON'];

        $reflection = new \ReflectionClass(ContainerFactory::class);
        $method = $reflection->getMethod('shouldCompile');
        $method->setAccessible(true);

        // Save original value
        $originalValue = getenv('PHLIX_HUB_CONTAINER_COMPILE');

        try {
            foreach ($truthyValues as $value) {
                putenv('PHLIX_HUB_CONTAINER_COMPILE=' . $value);
                self::assertTrue(
                    $method->invoke(null),
                    "Expected shouldCompile() to return true for value: {$value}"
                );
            }
        } finally {
            // Restore original environment
            if ($originalValue === false) {
                putenv('PHLIX_HUB_CONTAINER_COMPILE');
            } else {
                putenv('PHLIX_HUB_CONTAINER_COMPILE=' . $originalValue);
            }
        }
    }

    public function testShouldCompileReturnsFalseForFalsyValues(): void
    {
        $reflection = new \ReflectionClass(ContainerFactory::class);
        $method = $reflection->getMethod('shouldCompile');
        $method->setAccessible(true);

        // Save original value
        $originalValue = getenv('PHLIX_HUB_CONTAINER_COMPILE');

        try {
            putenv('PHLIX_HUB_CONTAINER_COMPILE=0');
            self::assertFalse($method->invoke(null));

            putenv('PHLIX_HUB_CONTAINER_COMPILE=false');
            self::assertFalse($method->invoke(null));

            putenv('PHLIX_HUB_CONTAINER_COMPILE=no');
            self::assertFalse($method->invoke(null));

            putenv('PHLIX_HUB_CONTAINER_COMPILE=off');
            self::assertFalse($method->invoke(null));
        } finally {
            // Restore original environment
            if ($originalValue === false) {
                putenv('PHLIX_HUB_CONTAINER_COMPILE');
            } else {
                putenv('PHLIX_HUB_CONTAINER_COMPILE=' . $originalValue);
            }
        }
    }

    public function testPrivateConstructor(): void
    {
        $reflection = new \ReflectionClass(ContainerFactory::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
