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

    /**
     * `shouldCompile()` must carry the server's hardened `(string)` cast into
     * `strtolower()` — the canonical shared-lineage shape
     * (`strtolower((string)$value)`).
     *
     * HUB-3.2 (A-09, runtime-deprecation family): the env read is guarded for
     * the unset arm (`getenv() === false`), but the argument type passed to
     * `strtolower()` was left implicit — the exact form the server hardened
     * against implicit-conversion deprecations. Pinned on comment-stripped
     * tokens (prose can lie, tokens can't — the repo guard idiom), so a doc
     * mentioning the cast cannot fake this test green.
     */
    public function testShouldCompileCastsItsEnvValueBeforeLowercasing(): void
    {
        $srcFile = __DIR__ . '/../../../../src/Common/Container/ContainerFactory.php';
        $code = php_strip_whitespace($srcFile);
        self::assertNotFalse($code, "php_strip_whitespace({$srcFile}) failed — empty/missing file?");
        $code = (string) preg_replace('/\s+/', ' ', $code);

        self::assertStringContainsString(
            'strtolower((string)$value)',
            $code,
            'the server parity cast must be present at the call site',
        );
    }
}
