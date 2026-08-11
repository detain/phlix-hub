<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Health;

use Phlix\Hub\Health\HealthController;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use Phlix\Hub\Version;
use Phlix\Shared\Version as SharedVersion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see HealthController}.
 *
 * The S312 block below is the part that matters: before S312 this controller
 * answered `{"status":"ok"}` while the maintenance worker — a DIFFERENT FORK —
 * was being killed and re-forked every 60 seconds, and both `docker inspect`
 * and `RestartCount` agreed the container was fine.
 *
 * The coverage metadata this file used to carry has been removed (S311): in
 * this repository it DISCARDS every other file's coverage from this test's
 * contribution, and the payload now spans two classes. Naming the tag in prose
 * here would be the same defect in a quieter form — PHPUnit parses it out of
 * the sentence as an invalid entry and throws the attribution away anyway.
 *
 * @package Phlix\Hub\Tests\Health
 */
final class HealthControllerTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-hub-s312-hc-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        $this->path = $this->dir . '/maintenance-heartbeat.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testInvokeReturnsOkStatus(): void
    {
        $payload = (new HealthController())();

        self::assertSame('ok', $payload['status']);
    }

    public function testInvokeIdentifiesAsPhlixHub(): void
    {
        $payload = (new HealthController())();

        self::assertSame('phlix-hub', $payload['service']);
    }

    public function testInvokeIncludesPackageVersion(): void
    {
        $payload = (new HealthController())();

        self::assertSame(Version::VERSION, $payload['version']);
    }

    public function testInvokeIncludesSharedVersion(): void
    {
        $payload = (new HealthController())();

        self::assertSame(SharedVersion::VERSION, $payload['phlixShared']);
    }

    public function testInvokeIncludesRecentTimestamp(): void
    {
        $before = time();
        $payload = (new HealthController())();
        $after = time();

        self::assertGreaterThanOrEqual($before, $payload['timestamp']);
        self::assertLessThanOrEqual($after, $payload['timestamp']);
    }

    // -----------------------------------------------------------------------
    // S312 — the probe reports the maintenance worker, and answers 503 for it
    // -----------------------------------------------------------------------

    public function testWithNoHeartbeatWiredThePayloadStillAnswers200(): void
    {
        $payload = (new HealthController())();

        self::assertSame('ok', $payload['status']);
        self::assertSame(MaintenanceHeartbeat::STATUS_DISABLED, $payload['maintenance']['status']);
        self::assertSame('no_heartbeat_configured', $payload['maintenance']['reason']);
        self::assertSame(200, HealthController::statusCodeFor($payload));
    }

    public function testALiveMaintenanceWorkerKeepsTheProbeGreen(): void
    {
        $heartbeat = new MaintenanceHeartbeat($this->path, 180, true);
        $heartbeat->arm(4242);
        $heartbeat->recordSweep('idle_reaper_db', null);

        $payload = (new HealthController($heartbeat))();

        self::assertSame('ok', $payload['status']);
        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $payload['maintenance']['status']);
        self::assertSame(4242, $payload['maintenance']['pid']);
        self::assertSame(1, $payload['maintenance']['incarnations']);
        self::assertSame(200, HealthController::statusCodeFor($payload));
    }

    /**
     * The failing arm, and the assertion the whole step turns on: with the
     * maintenance worker not completing sweeps, `/health` must stop saying `ok`
     * and must answer 503, because `curl -fsS` — the image's HEALTHCHECK — only
     * fails on the status code.
     */
    public function testACrashLoopingMaintenanceWorkerMakesTheProbeFail(): void
    {
        $heartbeat = new MaintenanceHeartbeat($this->path, 180, true);

        // The measured master behaviour: arm, never sweep, re-fork every 60s.
        $now = time() - 400;
        $pid = 100;
        $heartbeat->arm($pid, $now);
        for ($i = 0; $i < 5; $i++) {
            $now += 60;
            $pid += 93;
            $heartbeat->arm($pid, $now);
        }

        $payload = (new HealthController($heartbeat))();

        self::assertSame('unhealthy', $payload['status']);
        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $payload['maintenance']['status']);
        self::assertSame('no_sweep_completed', $payload['maintenance']['reason']);
        self::assertSame(6, $payload['maintenance']['incarnations']);
        self::assertSame(503, HealthController::statusCodeFor($payload));
    }

    public function testAMissingHeartbeatRecordMakesTheProbeFail(): void
    {
        $heartbeat = new MaintenanceHeartbeat($this->path, 180, true);

        $payload = (new HealthController($heartbeat))();

        self::assertSame('unhealthy', $payload['status']);
        self::assertSame('no_heartbeat_record', $payload['maintenance']['reason']);
        self::assertSame(503, HealthController::statusCodeFor($payload));
    }

    /**
     * The control that keeps the assertion above from being a blunt "any
     * problem is a 503": a worker whose sweeps RUN but fail is degraded, is
     * reported as such in the body, and stays 200.
     */
    public function testADegradedButAliveWorkerStaysGreen(): void
    {
        $heartbeat = new MaintenanceHeartbeat($this->path, 180, true);
        $heartbeat->arm(4242);
        $heartbeat->recordSweep('server_reaper', new RuntimeException('Connection refused'));

        $payload = (new HealthController($heartbeat))();

        self::assertSame('ok', $payload['status']);
        self::assertSame(MaintenanceHeartbeat::STATUS_DEGRADED, $payload['maintenance']['status']);
        self::assertIsString($payload['maintenance']['last_error']);
        self::assertStringContainsString('Connection refused', $payload['maintenance']['last_error']);
        self::assertSame(200, HealthController::statusCodeFor($payload));
    }

    public function testADisabledMaintenanceWorkerStaysGreen(): void
    {
        $heartbeat = new MaintenanceHeartbeat($this->path, 180, false);

        $payload = (new HealthController($heartbeat))();

        self::assertSame('ok', $payload['status']);
        self::assertSame(MaintenanceHeartbeat::STATUS_DISABLED, $payload['maintenance']['status']);
        self::assertSame(200, HealthController::statusCodeFor($payload));
    }
}
