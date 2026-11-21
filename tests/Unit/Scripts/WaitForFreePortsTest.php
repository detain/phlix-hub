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
        // Granularity law: the script's deadline is second-granular (date +%s)
        // and the busy loop polls once per second, so with -t 2 the exit fires
        // at the first tick whose floor reaches D0+2 — elapsed lands in
        // [~1.0, ~2.0] depending on where the bash launch sits inside the
        // wall-clock second (observed 1.014s when it starts just before a
        // boundary). The old 1.5 floor sat INSIDE that legitimate band and
        // flaked; any floor here must sit below t-1, hence 0.9.
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $t);
    }
}
