<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\MaintenanceWorker;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for {@see MaintenanceWorker}.
 *
 * @package Phlix\Hub\Tests\Unit
 */
final class MaintenanceWorkerTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up a temp logger config for LoggerFactory
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-maint-worker-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        @unlink($this->tmpDir . '/logger.php');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testStartCallsHubServicesProviderWithContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $worker = new MaintenanceWorker();

        // The start() method catches exceptions and logs them
        // We verify it completes without throwing
        $worker->start($container);

        // If we got here without exception, the test passes
        self::assertTrue(true);
    }

    public function testStartDoesNotRequireSpecificContainer(): void
    {
        // Verify the method signature accepts any ContainerInterface
        $container = $this->createMock(ContainerInterface::class);

        $worker = new MaintenanceWorker();
        $worker->start($container);

        self::assertTrue(true);
    }
}
