<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container;

use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Health\HealthController;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * S312 — the container really hands {@see HealthController} a
 * {@see MaintenanceHeartbeat}, and it is the one the config names.
 *
 * ## Why the CONTAINER is the subject and not the controller
 *
 * `HealthController::__construct()` takes `?MaintenanceHeartbeat $maintenance = null`
 * — optional, because several boot paths and tests construct it bare. PHP-DI's
 * `autowire()` SKIPS optional constructor parameters, so had the controller been
 * left unregistered (it was, until S312) the parameter would resolve to `null`,
 * `/health` would fall back to its pre-S312 payload, and a crash-looping
 * maintenance worker would once again report `{"status":"ok"}`. That is not a
 * hypothetical failure mode in this repository: it is exactly how S269's
 * `AuditLogger` lost its repository, and how S286's OAuth pruners lost theirs.
 *
 * ⚠ No test that constructs `HealthController` itself can see this. The argument
 * list lives in `HttpServicesProvider` and nowhere else.
 *
 * The heartbeat's own values are asserted too, because a resolved-but-misconfigured
 * heartbeat (pointing at a path the maintenance fork never writes) would be a
 * green wiring test in front of a dead signal.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container
 */
final class MaintenanceHealthWiringTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-hub-s312-wire-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides Extra config entries.
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        // AuthServicesProvider resolves the HS256 secret EAGERLY at register()
        // time and throws MissingJwtSecretException without one, so a container
        // cannot be built at all until this exists. Nothing here reads it.
        $authPath = $this->dir . '/auth.php';
        if (!is_file($authPath)) {
            file_put_contents(
                $authPath,
                "<?php return ['secret' => 's312-container-wiring-test-secret-0123456789'];",
            );
        }

        return array_merge([
            'auth_config_path' => $authPath,
            'maintenance_heartbeat_file' => $this->dir . '/maintenance-heartbeat.json',
            'maintenance_stale_seconds' => 123,
        ], $overrides);
    }

    public function testContainerResolvesTheHeartbeatFromConfig(): void
    {
        $container = ContainerFactory::create($this->config());

        $heartbeat = $container->get(MaintenanceHeartbeat::class);

        self::assertInstanceOf(MaintenanceHeartbeat::class, $heartbeat);
        self::assertSame($this->dir . '/maintenance-heartbeat.json', $heartbeat->path());
        self::assertSame(123, $heartbeat->staleAfterSeconds());
        self::assertTrue($heartbeat->enabled());
    }

    public function testContainerResolvedHealthControllerHoldsTheContainersHeartbeat(): void
    {
        $container = ContainerFactory::create($this->config());

        $controller = $container->get(HealthController::class);
        self::assertInstanceOf(HealthController::class, $controller);

        $property = new ReflectionProperty(HealthController::class, 'maintenance');
        $held = $property->getValue($controller);

        self::assertInstanceOf(
            MaintenanceHeartbeat::class,
            $held,
            'HealthController resolved with a NULL heartbeat: /health would report ok for a dead '
            . 'maintenance worker. Check the ->parameter() line in HttpServicesProvider.',
        );
        self::assertSame($container->get(MaintenanceHeartbeat::class), $held);
    }

    /**
     * End to end through the container: a record that says the worker has not
     * completed a sweep must make the resolved controller answer 503.
     *
     * This is the assertion the whole step turns on, taken through the real
     * wiring rather than a hand-built controller.
     */
    public function testTheWiredControllerAnswers503ForACrashLoopingWorker(): void
    {
        $container = ContainerFactory::create($this->config());
        $heartbeat = $container->get(MaintenanceHeartbeat::class);
        self::assertInstanceOf(MaintenanceHeartbeat::class, $heartbeat);

        // Armed 400s ago, five re-forks, no sweep ever completed — the measured
        // master behaviour under `docker run --network none`.
        $now = time() - 400;
        $pid = 100;
        $heartbeat->arm($pid, $now);
        for ($i = 0; $i < 5; $i++) {
            $now += 60;
            $pid += 93;
            $heartbeat->arm($pid, $now);
        }

        $controller = $container->get(HealthController::class);
        self::assertInstanceOf(HealthController::class, $controller);
        $payload = $controller();

        self::assertSame('unhealthy', $payload['status']);
        self::assertSame(503, HealthController::statusCodeFor($payload));
        self::assertSame(6, $payload['maintenance']['incarnations']);
    }

    /**
     * The control for the test above: with a sweep freshly recorded through the
     * SAME container, the SAME controller answers 200. Without this, the 503
     * assertion would also pass against a controller hard-wired to fail.
     */
    public function testTheWiredControllerAnswers200ForALiveWorker(): void
    {
        $container = ContainerFactory::create($this->config());
        $heartbeat = $container->get(MaintenanceHeartbeat::class);
        self::assertInstanceOf(MaintenanceHeartbeat::class, $heartbeat);
        $heartbeat->arm(4242);
        $heartbeat->recordSweep('idle_reaper_db', null);

        $controller = $container->get(HealthController::class);
        self::assertInstanceOf(HealthController::class, $controller);
        $payload = $controller();

        self::assertSame('ok', $payload['status']);
        self::assertSame(200, HealthController::statusCodeFor($payload));
    }

    /**
     * `config/process.php` is the single source of truth for whether the worker
     * exists at all, and an absent heartbeat means something different when it
     * does not. The path is injected by `start.php` as `process_config_path`.
     */
    public function testProcessConfigDisablesTheMaintenanceVerdict(): void
    {
        $processPath = $this->dir . '/process.php';
        file_put_contents(
            $processPath,
            "<?php return ['maintenance' => ['enabled' => false, 'count' => 1, 'poll_seconds' => 60]];",
        );

        $container = ContainerFactory::create($this->config(['process_config_path' => $processPath]));
        $heartbeat = $container->get(MaintenanceHeartbeat::class);

        self::assertInstanceOf(MaintenanceHeartbeat::class, $heartbeat);
        self::assertFalse($heartbeat->enabled());
        self::assertSame(MaintenanceHeartbeat::STATUS_DISABLED, $heartbeat->snapshot()['status']);
    }

    /**
     * The control for the test above, and the reason the default is `true`: the
     * SHIPPED `config/process.php` enables the worker, so an absent heartbeat
     * record there is a real DOWN, not a silent `disabled` that could never
     * fail.
     */
    public function testShippedProcessConfigLeavesTheMaintenanceVerdictLive(): void
    {
        $shipped = dirname(__DIR__, 4) . '/config/process.php';
        self::assertFileExists($shipped);

        $container = ContainerFactory::create($this->config(['process_config_path' => $shipped]));
        $heartbeat = $container->get(MaintenanceHeartbeat::class);

        self::assertInstanceOf(MaintenanceHeartbeat::class, $heartbeat);
        self::assertTrue($heartbeat->enabled());
        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $heartbeat->snapshot()['status']);
    }

    /**
     * `start.php` must actually inject the path, or every deployment silently
     * falls back to the "enabled" default and `disabled` becomes unreachable.
     */
    public function testStartPhpInjectsTheProcessConfigPath(): void
    {
        $startPhp = (string) file_get_contents(dirname(__DIR__, 4) . '/start.php');

        self::assertStringContainsString("\$serverConfig['process_config_path']", $startPhp);
    }
}
