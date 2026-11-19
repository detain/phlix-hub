<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Logger;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see LoggerFactory}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Logger
 */
final class LoggerFactoryTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

    private string $tempConfigPath;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Create a temporary logger config for testing
        $this->tempConfigPath = sys_get_temp_dir() . '/phlix_test_logger_' . uniqid() . '.php';
        $configContent = <<<'PHP'
<?php
return [
    'handlers' => [
        'test' => [
            'type' => 'rotating_file',
            'path' => '/dev/null',
            'max_files' => 3,
            'level' => 'debug',
        ],
    ],
];
PHP;
        file_put_contents($this->tempConfigPath, $configContent);
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        if (file_exists($this->tempConfigPath)) {
            unlink($this->tempConfigPath);
        }
        parent::tearDown();
    }

    public function testInitStoresConfigPath(): void
    {
        LoggerFactory::init($this->tempConfigPath);

        // Use reflection to verify the config path was stored
        $reflection = new \ReflectionClass(LoggerFactory::class);
        $property = $reflection->getProperty('configPath');
        $property->setAccessible(true);

        $this->assertSame($this->tempConfigPath, $property->getValue());
    }

    public function testGetCreatesAndReturnsStructuredLogger(): void
    {
        LoggerFactory::init($this->tempConfigPath);

        $logger = LoggerFactory::get(LogChannels::APPLICATION);

        $this->assertInstanceOf(StructuredLogger::class, $logger);
    }

    public function testGetReturnsSameInstanceForSameChannel(): void
    {
        LoggerFactory::init($this->tempConfigPath);

        $logger1 = LoggerFactory::get(LogChannels::APPLICATION);
        $logger2 = LoggerFactory::get(LogChannels::APPLICATION);

        $this->assertSame($logger1, $logger2);
    }

    public function testGetReturnsDifferentInstancesForDifferentChannels(): void
    {
        LoggerFactory::init($this->tempConfigPath);

        $appLogger = LoggerFactory::get(LogChannels::APPLICATION);
        $httpLogger = LoggerFactory::get(LogChannels::HTTP);

        $this->assertNotSame($appLogger, $httpLogger);
    }

    public function testResetClearsAllCachedLoggers(): void
    {
        LoggerFactory::init($this->tempConfigPath);

        $logger = LoggerFactory::get(LogChannels::APPLICATION);
        $this->assertSame($logger, LoggerFactory::get(LogChannels::APPLICATION));

        LoggerFactory::reset();

        // After reset, getting the same channel should return a new instance
        $newLogger = LoggerFactory::get(LogChannels::APPLICATION);
        $this->assertNotSame($logger, $newLogger);
    }

    /**
     * A-17 guard port — fail-soft config resolution.
     *
     * Every arm below was written against the parent factory (raw
     * `include self::$configPath` in `get()`) and was RED there: the empty
     * path raised `ValueError: Path cannot be empty`, a missing file emitted
     * an include `E_WARNING` (fatal under this repo's `failOnWarning="true"`)
     * and then a `TypeError` from passing `false` to `StructuredLogger`'s
     * `array $config`, and a scalar-returning file a `TypeError` likewise.
     * Green here is the guard, mirroring `phlix-server`'s documented
     * `loadConfig()` posture. Gate log: see HUB-LOGGER-A17.md.
     */
    public function testGetWithUnsetConfigPathFallsBackToEmptyConfig(): void
    {
        LoggerFactory::init('');

        $logger = LoggerFactory::get(LogChannels::APPLICATION);

        $this->assertInstanceOf(StructuredLogger::class, $logger);
        $this->assertSame([], $this->loadedLoggerConfig($logger));
    }

    public function testGetWithMissingConfigFileFallsBackToEmptyConfig(): void
    {
        $missing = sys_get_temp_dir() . '/phlix_absent_logger_' . uniqid() . '.php';
        $this->assertFileDoesNotExist($missing);

        LoggerFactory::init($missing);

        $logger = LoggerFactory::get(LogChannels::APPLICATION);

        $this->assertInstanceOf(StructuredLogger::class, $logger);
        $this->assertSame([], $this->loadedLoggerConfig($logger));
    }

    public function testGetWithNonArrayConfigFileFallsBackToEmptyConfig(): void
    {
        $scalarConfig = sys_get_temp_dir() . '/phlix_scalar_logger_' . uniqid() . '.php';
        $this->assertIsInt(file_put_contents($scalarConfig, "<?php return 'not-an-array';"));

        LoggerFactory::init($scalarConfig);

        try {
            $logger = LoggerFactory::get(LogChannels::APPLICATION);
        } finally {
            unlink($scalarConfig);
        }

        $this->assertInstanceOf(StructuredLogger::class, $logger);
        $this->assertSame([], $this->loadedLoggerConfig($logger));
    }

    /**
     * `reset()` must drop the memoised path, not only the memo — the
     * server-side factory documents that a test which deleted its temp
     * logger.php would otherwise leave a stale pointer warning for the next
     * suite that calls `get()` after a bare reset (random execution order).
     */
    public function testResetClearsStaleConfigPath(): void
    {
        LoggerFactory::init($this->tempConfigPath);
        $configured = LoggerFactory::get(LogChannels::APPLICATION);
        $this->assertNotSame([], $this->loadedLoggerConfig($configured));

        LoggerFactory::reset();

        $property = (new \ReflectionClass(LoggerFactory::class))->getProperty('configPath');
        $property->setAccessible(true);
        $this->assertSame('', $property->getValue());

        // The dangling-pointer scenario: the temp config is gone (as every
        // fixture's tearDown leaves it) and the next get() must produce a
        // fallback logger, not an include warning + TypeError pair.
        $this->assertFileExists($this->tempConfigPath);
        unlink($this->tempConfigPath);

        $fallback = LoggerFactory::get(LogChannels::APPLICATION);

        $this->assertInstanceOf(StructuredLogger::class, $fallback);
        $this->assertSame([], $this->loadedLoggerConfig($fallback));
    }

    /**
     * Byte-identity pin for the success path: the guard must not alter what
     * a valid config produces. The array the factory hands to
     * `StructuredLogger` is exactly what a raw `include` of the same file
     * returns — same keys, same order, same values (serialize is
     * order- and type-sensitive, so this is byte equality, not structure
     * equality) — and the memoisation shape is unchanged.
     */
    public function testValidConfigIsPassedThroughByteIdentically(): void
    {
        LoggerFactory::init($this->tempConfigPath);

        $logger = LoggerFactory::get(LogChannels::APPLICATION);
        $loaded = $this->loadedLoggerConfig($logger);

        /** @psalm-suppress UnresolvableInclude test fixture path, set above */
        $raw = include $this->tempConfigPath;

        $this->assertIsArray($raw);
        $this->assertSame(serialize($raw), serialize($loaded));

        $this->assertSame($logger, LoggerFactory::get(LogChannels::APPLICATION));
    }

    /**
     * Read the config array a {@see StructuredLogger} resolved with.
     * `StructuredLogger::$config` is private; reflection matches the way
     * {@see LoggerFactoryIsolation} already reads the factory's internals.
     *
     * @return array<string, mixed>
     */
    private function loadedLoggerConfig(StructuredLogger $logger): array
    {
        $property = new \ReflectionProperty(StructuredLogger::class, 'config');
        $property->setAccessible(true);

        /** @var array<string, mixed> $config */
        $config = $property->getValue($logger);

        return $config;
    }

    public function testAllLogChannelConstantsAreValidStrings(): void
    {
        $this->assertSame('application', LogChannels::APPLICATION);
        $this->assertSame('http', LogChannels::HTTP);
        $this->assertSame('websocket', LogChannels::WEBSOCKET);
        $this->assertSame('database', LogChannels::DATABASE);
        $this->assertSame('auth', LogChannels::AUTH);
        $this->assertSame('hub', LogChannels::HUB);
        $this->assertSame('relay', LogChannels::RELAY);
        $this->assertSame('audit', LogChannels::AUDIT);
    }
}
