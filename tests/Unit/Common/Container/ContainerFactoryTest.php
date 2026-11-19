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
     * HUB-ENVBOOL (answer CHANGE, red-first against the old arm): the former
     * `shouldCompile()` did NOT trim, so `PHLIX_HUB_CONTAINER_COMPILE=" 1 "`
     * answered false while every trimming copy of the word list answered true
     * — the E-04 drift shape. Under the estate bool grammar (EnvConfig::bool)
     * the trim is load-bearing and the padded word enables the compiled cache.
     * The knob is the PHP-DI compiled-container cache (perf-only), so the
     * change is accept-direction-safe: a padded ON now means ON everywhere.
     */
    public function testShouldCompileAcceptsAPaddedTrueWord(): void
    {
        $reflection = new \ReflectionClass(ContainerFactory::class);
        $method = $reflection->getMethod('shouldCompile');
        $method->setAccessible(true);

        $originalValue = getenv('PHLIX_HUB_CONTAINER_COMPILE');

        try {
            putenv('PHLIX_HUB_CONTAINER_COMPILE= 1 ');
            self::assertTrue($method->invoke(null), 'padded " 1 " is the word 1 after the shared trim');
        } finally {
            if ($originalValue === false) {
                putenv('PHLIX_HUB_CONTAINER_COMPILE');
            } else {
                putenv('PHLIX_HUB_CONTAINER_COMPILE=' . $originalValue);
            }
        }
    }

    /**
     * `shouldCompile()` must carry the server's hardened cast law through the
     * SHARED reader — the estate bool grammar owns the trim/case-fold/cast
     * (its own EnvConfigTest suite pins the grammar spell-for-spell), so the
     * site-level obligation collapses to: the body delegates to the SSOT verb
     * on this exact knob name. HUB-3.2 (A-09) originally pinned the inline
     * `strtolower((string)$value)` token; HUB-ENVBOOL supersedes it with the
     * delegation token — the same comment-stripped-source idiom (prose can
     * lie, tokens can't), and the drift it guarded against (disagreement on
     * trim between copies) is now structurally impossible on this knob.
     */
    public function testShouldCompileDelegatesToTheEnvConfigSsot(): void
    {
        $srcFile = __DIR__ . '/../../../../src/Common/Container/ContainerFactory.php';
        $code = php_strip_whitespace($srcFile);
        self::assertNotFalse($code, "php_strip_whitespace({$srcFile}) failed — empty/missing file?");
        $code = (string) preg_replace('/\s+/', ' ', $code);

        self::assertStringContainsString(
            "EnvConfig::bool('PHLIX_HUB_CONTAINER_COMPILE')",
            $code,
            'the compile gate must read its knob through the estate EnvConfig bool reader',
        );
    }
}
