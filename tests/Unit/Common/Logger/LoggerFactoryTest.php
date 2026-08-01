<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Logger;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see LoggerFactory}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Logger
 *
 * @covers \Phlix\Hub\Common\Logger\LoggerFactory
 */
final class LoggerFactoryTest extends TestCase
{
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
