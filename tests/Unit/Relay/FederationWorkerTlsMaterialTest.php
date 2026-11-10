<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Audit §3.2 (wildcard-rotation cure): the :8805 federation listener used to
 * have NO boot material check at all — a configured-but-missing PEM sailed
 * through construction and died cryptically inside stream_socket_server at
 * runAll(), and an unreadable key meant every NEW handshake closed SILENTLY
 * (no onError). The cure aligns it with the :8802 sibling: fail-fast on
 * MISSING (message names the env knob, the path, and both ways out), plus
 * the armed per-connection watch + loud enable_crypto failure line.
 *
 * Red on the parent: absent material happily returned a Worker.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class FederationWorkerTlsMaterialTest extends TestCase
{
    use WorkermanTimerRuntimeControl;
    use LoggerFactoryIsolation;

    /** @var array<int, string> Scratch paths removed in tearDown(). */
    private array $scratchFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        FederationWorker::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        FederationWorker::reset();
        LoggerFactory::reset();
        foreach ($this->scratchFiles as $path) {
            @unlink($path);
        }
        $this->scratchFiles = [];
    }

    private function scratchPem(string $role): string
    {
        $path = sys_get_temp_dir() . '/phlix-fed-tls-' . $role . '-' . bin2hex(random_bytes(6)) . '.pem';
        if (file_put_contents($path, "-----BEGIN SCRATCH $role-----\n") === false) {
            self::fail("could not write scratch $role pem to $path");
        }
        $this->scratchFiles[] = $path;

        return $path;
    }

    public function testStartWithAbsentCertFailsFastNamingTheEnvKnobAndBothExits(): void
    {
        $worker = new FederationWorker(
            $this->createMock(ContainerInterface::class),
            0,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            true,
            '/tmp/phlix-fed-tls-absent-' . bin2hex(random_bytes(6)) . '.pem',
            $this->scratchPem('key'),
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/HUB_FEDERATION_TLS_CERT/');
        $this->expectExceptionMessageMatches('/does not exist/');
        $this->expectExceptionMessageMatches('/HUB_FEDERATION_TLS=false/');

        $worker->start();
    }

    public function testStartWithAbsentKeyFailsFastNamingTheKeyKnob(): void
    {
        $worker = new FederationWorker(
            $this->createMock(ContainerInterface::class),
            0,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            true,
            $this->scratchPem('cert'),
            '/tmp/phlix-fed-tls-absent-' . bin2hex(random_bytes(6)) . '.pem',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/HUB_FEDERATION_TLS_KEY/');

        $worker->start();
    }

    public function testTlsStartArmsTheRuntimeWatchAndErrorLoudLine(): void
    {
        $worker = new FederationWorker(
            $this->createMock(ContainerInterface::class),
            0,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            true,
            $this->scratchPem('cert'),
            $this->scratchPem('key'),
            false,
        );

        $workerman = $worker->start();

        self::assertSame('ssl', $workerman->transport);
        self::assertNotNull($workerman->onConnect);
        self::assertNotNull($workerman->onError);
    }
}
