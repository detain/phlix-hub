<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/** HUB-S20 — the port gate must see listeners on both loopback families. */
final class WaitForFreePortsTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/wait-for-free-ports.sh';

    public function testScriptProbesBothLoopbackFamilies(): void
    {
        $src = (string) file_get_contents(self::SCRIPT);
        self::assertStringContainsString('127.0.0.1', $src);
        self::assertStringContainsString('::1', $src);
    }

    public function testFreePortExitsImmediately(): void
    {
        $t = microtime(true);
        exec('bash ' . escapeshellarg(self::SCRIPT) . ' -t 5 59871 2>&1', $o, $rc);
        self::assertSame(0, $rc);
        self::assertLessThan(3.0, microtime(true) - $t);
    }

    public function testIpv6ListenerIsDetectedAsBusy(): void
    {
        $srv = @stream_socket_server('tcp://[::1]:0', $errno, $err);
        if ($srv === false) {
            self::markTestSkipped('IPv6 loopback unavailable');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($srv, false), ':'), 1);
        $t = microtime(true);
        exec('bash ' . escapeshellarg(self::SCRIPT) . ' -t 2 ' . $port . ' 2>&1', $o, $rc);
        fclose($srv);
        self::assertSame(0, $rc);
        self::assertStringContainsString('still in use', implode("\n", $o));
        self::assertGreaterThanOrEqual(1.5, microtime(true) - $t);
    }
}
