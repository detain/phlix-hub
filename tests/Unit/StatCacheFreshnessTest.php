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
 * Each test primes PHP's per-process stat cache with a positive stat, then
 * changes the file EXTERNALLY (a child `rm`/`chmod`/`mv`, as an operator or
 * another process would), and asserts the site observes the change.
 *
 * It must be external: PHP's own unlink()/chmod()/rename() clear the stat
 * cache for the path, so mutating through them makes a test pass even when the
 * site under test never calls clearstatcache() (the first version of this file
 * did exactly that). These tests go red if the site's clearstatcache() is
 * removed.
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

    /** Run a command in a CHILD process so PHP's stat cache is not invalidated. */
    private function external(string $command): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec() is disabled; cannot mutate the file outside this process.');
        }
        $out = [];
        $code = 0;
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, 'external command failed: ' . implode("\n", $out));
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
        self::assertTrue(is_file($path)); // primes the stat cache with a positive entry
        $this->external('rm -f ' . escapeshellarg($path));
        self::assertSame(TlsMaterialWatch::MISSING, $probe($path));
    }

    public function testTlsProbeSeesExternalCreationAfterNegativeProbe(): void
    {
        $path = $this->dir . '/late.pem';
        $probe = TlsMaterialWatch::probeDefault();
        self::assertSame(TlsMaterialWatch::MISSING, $probe($path));
        $this->external('printf x > ' . escapeshellarg($path));
        self::assertSame(TlsMaterialWatch::OK, $probe($path));
    }

    public function testTlsProbeSeesExternalChmodAfterPositiveStat(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads mode-000 files; the chmod probe needs an unprivileged user.');
        }
        $path = $this->dir . '/mode.pem';
        file_put_contents($path, 'x');
        $probe = TlsMaterialWatch::probeDefault();
        self::assertSame(TlsMaterialWatch::OK, $probe($path));
        self::assertTrue(is_readable($path)); // primes the cache with the 0600 mode
        $this->external('chmod 000 ' . escapeshellarg($path));
        self::assertSame(TlsMaterialWatch::UNREADABLE, $probe($path));
    }

    public function testHeartbeatReadSeesRemovalAfterPositiveStat(): void
    {
        $path = $this->dir . '/hb.json';
        $hb = new MaintenanceHeartbeat($path, 180, true);
        $hb->arm(1234, 1_000);
        // NB: a stale stat answer is masked here (the read that follows fails
        // on the missing file anyway), so this pins the observable verdict, not
        // the cache; the cache-sensitive sites are the TLS probe and the PID
        // file above/below.
        self::assertNotSame('no_heartbeat_record', $hb->snapshot(1_001)['reason'] ?? null);
        self::assertTrue(is_file($path)); // primes the stat cache with a positive entry
        $this->external('rm -f ' . escapeshellarg($path));
        self::assertSame('no_heartbeat_record', $hb->snapshot(1_002)['reason']);
    }

    public function testRestartSeesPidFileRemovalAfterPositiveStat(): void
    {
        $path = $this->dir . '/hub.pid';
        file_put_contents($path, '');
        $controller = new HubRestartController($path, $this->createMock(StructuredLogger::class));
        $request = new Request();
        $request->body = [];
        // PHP keeps ONE stat entry (the last path stat'd): prime it last, right
        // before the external removal, so a stale cache would answer is_file()
        // true and the failure would degrade to pid_file_read_failed.
        self::assertTrue(is_file($path));
        $this->external('rm -f ' . escapeshellarg($path));
        $response = $controller->restart($request, []);
        self::assertSame(500, $response->statusCode);
        self::assertStringContainsString('pid_file_not_found', (string) $response->body);
    }
}
