<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use Phlix\Hub\Http\Controllers\HubRestartController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Relay\TlsMaterialWatch;
use PHPUnit\Framework\TestCase;

/**
 * HUB-S18: resident workers must see filesystem changes without a restart.
 * Each test primes PHP's per-process stat cache with a positive stat, removes
 * the file, then asserts the site observes the removal.
 */
final class StatCacheFreshnessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-hub-s18-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testTlsProbeSeesRemovalAfterPositiveStat(): void
    {
        $path = $this->dir . '/cert.pem';
        file_put_contents($path, 'x');
        $probe = TlsMaterialWatch::probeDefault();
        self::assertSame(TlsMaterialWatch::OK, $probe($path));
        unlink($path);
        self::assertSame(TlsMaterialWatch::MISSING, $probe($path));
    }

    public function testHeartbeatReadSeesRemovalAfterPositiveStat(): void
    {
        $path = $this->dir . '/hb.json';
        $hb = new MaintenanceHeartbeat($path, 180, true);
        $hb->arm(1234, 1_000);
        self::assertNotSame('no_heartbeat_record', $hb->snapshot(1_001)['reason'] ?? null);
        unlink($path);
        self::assertSame('no_heartbeat_record', $hb->snapshot(1_002)['reason']);
    }

    public function testRestartSeesPidFileRemovalAfterPositiveStat(): void
    {
        $path = $this->dir . '/hub.pid';
        file_put_contents($path, '');
        self::assertTrue(is_file($path));
        unlink($path);
        $controller = new HubRestartController($path, $this->createMock(StructuredLogger::class));
        $request = new Request();
        $request->body = [];
        $response = $controller->restart($request, []);
        self::assertSame(500, $response->statusCode);
        self::assertStringContainsString('pid_file_not_found', (string) $response->body);
    }
}
