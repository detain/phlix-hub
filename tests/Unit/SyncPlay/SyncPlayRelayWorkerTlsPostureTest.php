<?php

/**
 * Phlix hub component: SyncPlay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\SyncPlay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use Workerman\Worker;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SH-064 (review hub__secnet#1) — bind-time TLS posture + bind-host law for
 * the :8804 SyncPlay relay listener. Sister suite of
 * {@see \Phlix\Hub\Tests\Unit\Relay\ClientRelayWorkerTlsPostureTest}: both
 * client-facing sockets (the web client's watch-party path included) bound
 * hardcoded 0.0.0.0 with no native wss:// path, no mandate knob and no boot
 * announcement, while join frames carry each member's auth token.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class SyncPlayRelayWorkerTlsPostureTest extends TestCase
{
    use WorkermanTimerRuntimeControl;
    use LoggerFactoryIsolation;

    private const PORT = 0;

    private ContainerInterface $container;

    /**
     * Warnings recorded by the seeded relay logger.
     *
     * @var array<int, string>
     */
    private array $recordedWarnings = [];

    /** @var array<int, string> Scratch PEM paths removed in tearDown(). */
    private array $scratchFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->recordedWarnings = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        foreach ($this->scratchFiles as $path) {
            @unlink($path);
        }
        $this->scratchFiles = [];
    }

    private function seedRecordingLogger(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('warning')->willReturnCallback(
            /**
             * @param string               $message
             * @param array<string, mixed> $context
             */
            function (string $message, array $context = []): void {
                $this->recordedWarnings[] = $message;
            },
        );

        $cache = new ReflectionProperty(LoggerFactory::class, 'loggers');
        /** @var array<string, StructuredLogger> $current */
        $current = $cache->getValue(null);
        $current[LogChannels::RELAY] = $logger;
        $cache->setValue(null, $current);
    }

    private function writeScratchPem(string $role): string
    {
        $path = sys_get_temp_dir() . '/phlix-sh064-sp-' . $role . '-' . bin2hex(random_bytes(6)) . '.pem';
        if (file_put_contents($path, "-----BEGIN PHLEX SCRATCH " . strtoupper($role) . "-----\n") === false) {
            self::fail("could not write scratch $role pem to $path");
        }
        $this->scratchFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, array{bool, bool, string}>
     */
    public static function postureProvider(): array
    {
        return [
            'tls on, no mandate -> secure' => [true, false, 'secure'],
            'tls on, mandate -> secure' => [true, true, 'secure'],
            'plaintext, no mandate -> advisory (DEFAULT, warn-only)' => [false, false, 'advisory'],
            'plaintext, mandate -> refused' => [false, true, 'refused'],
        ];
    }

    #[DataProvider('postureProvider')]
    public function testBootPostureTruthTable(bool $useTls, bool $requireTls, string $expected): void
    {
        self::assertSame($expected, SyncPlayRelayWorker::tlsBootPosture($useTls, $requireTls));
    }

    public function testAdvisoryNamesTheRiskAndEveryCureKnob(): void
    {
        $text = SyncPlayRelayWorker::plaintextAdvisory(self::PORT);

        $needles = [
            'PLAINTEXT ws://',
            'SyncPlay',
            'token',
            'HAProxy',
            'HUB_SYNCPLAY_TLS=true',
            'HUB_SYNCPLAY_REQUIRE_TLS',
            'HUB_WS_BIND',
            ':' . self::PORT,
        ];
        foreach ($needles as $needle) {
            self::assertStringContainsString(
                $needle,
                $text,
                "advisory must carry '{$needle}' — operators need the risk AND every way out in one line",
            );
        }
    }

    public function testRefusalNamesTheMandateThePayloadAndTheWayBack(): void
    {
        $text = SyncPlayRelayWorker::plaintextRefusal(self::PORT);

        $needles = [
            'Refusing to bind',
            'syncplay_require_tls',
            'HUB_SYNCPLAY_REQUIRE_TLS=1',
            'SyncPlay',
            'HUB_SYNCPLAY_TLS=true',
            'warn-only',
            ':' . self::PORT,
        ];
        foreach ($needles as $needle) {
            self::assertStringContainsString(
                $needle,
                $text,
                "refusal must be actionable: mandate, risk, and BOTH exits (secure it / drop the mandate)",
            );
        }
    }

    public function testRequireTlsWithoutTlsRefusesToBindBeforeAnySocket(): void
    {
        $worker = new SyncPlayRelayWorker(self::PORT, 1, $this->container, requireTls: true);

        try {
            $worker->start();
            self::fail('require_tls + plaintext must throw, never bind a cleartext listener');
        } catch (RuntimeException $e) {
            self::assertSame(
                SyncPlayRelayWorker::plaintextRefusal(self::PORT),
                $e->getMessage(),
                'the boot failure must carry the actionable refusal text verbatim',
            );
        }
    }

    public function testPlaintextDefaultStillBootsWarnOnly(): void
    {
        $this->seedRecordingLogger();

        // Default-off estate law; a bind/TLS default flip is an owner call.
        $worker = new SyncPlayRelayWorker(self::PORT, 1, $this->container);
        self::assertInstanceOf(Worker::class, $worker->start());
    }

    public function testPlaintextDefaultLogsTheAdvisoryExactlyOnce(): void
    {
        $this->seedRecordingLogger();

        $worker = new SyncPlayRelayWorker(self::PORT, 1, $this->container);
        $worker->start();

        self::assertCount(
            1,
            $this->recordedWarnings,
            'one loud advisory per boot on the plaintext path — silence was the reviewed defect',
        );
        self::assertSame(SyncPlayRelayWorker::plaintextAdvisory(self::PORT), $this->recordedWarnings[0]);
    }

    public function testTlsEnabledPostureLogsNoAdvisorySetsSslTransportAndCarriesHardenedContext(): void
    {
        $this->seedRecordingLogger();
        $cert = $this->writeScratchPem('cert');
        $key = $this->writeScratchPem('key');

        $worker = (new SyncPlayRelayWorker(
            self::PORT,
            1,
            $this->container,
            bindHost: '127.0.0.1',
            useTls: true,
            tlsCert: $cert,
            tlsKey: $key,
        ))->start();

        self::assertSame([], $this->recordedWarnings, 'a wss:// bind must never cry wolf');
        self::assertSame('ssl', $worker->transport);
        $socketContext = new ReflectionProperty(Worker::class, 'socketContext');
        $contextResource = $socketContext->getValue($worker);
        if (!is_resource($contextResource)) {
            self::fail('a TLS bind must carry a real stream context resource');
        }
        $options = stream_context_get_options($contextResource);
        $ssl = isset($options['ssl']) && is_array($options['ssl'])
            ? $options['ssl']
            : [];
        self::assertNotSame([], $ssl, 'TLS bind must carry an ssl context section');
        self::assertSame($cert, $ssl['local_cert']);
        self::assertSame($key, $ssl['local_pk']);
        self::assertSame('secp384r1', $ssl['ecdh_curve']);
        self::assertSame(2, $ssl['security_level']);
    }

    public function testTlsWithMissingMaterialFailsFastLoudBeforeAnyBind(): void
    {
        $this->seedRecordingLogger();

        try {
            (new SyncPlayRelayWorker(
                self::PORT,
                1,
                $this->container,
                useTls: true,
                tlsCert: '/tmp/phlix-sh064-definitely-absent-cert.pem',
                tlsKey: '/tmp/phlix-sh064-definitely-absent-key.pem',
            ))->start();
            self::fail('configured-but-missing PEMs must fail fast, not bind half-configured TLS');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('HUB_SYNCPLAY_TLS_CERT', $e->getMessage());
            self::assertStringContainsString('does not exist', $e->getMessage());
            self::assertStringContainsString('HUB_SYNCPLAY_TLS=false', $e->getMessage());
        }
    }

    public function testBindHostDefaultsToAllInterfacesAndHonorsTheKnob(): void
    {
        $this->seedRecordingLogger();
        $socketName = new ReflectionProperty(Worker::class, 'socketName');

        $default = (new SyncPlayRelayWorker(self::PORT, 1, $this->container))->start();
        self::assertSame('websocket://0.0.0.0:0', $socketName->getValue($default));

        $loopback = (new SyncPlayRelayWorker(self::PORT, 1, $this->container, bindHost: '127.0.0.1'))->start();
        self::assertSame('websocket://127.0.0.1:0', $socketName->getValue($loopback));

        // IPv6 brackets are normalized in (same no-silent-bind-trap law as
        // the :8803 sister suite).
        $v6 = (new SyncPlayRelayWorker(self::PORT, 1, $this->container, bindHost: '::1'))->start();
        self::assertSame('websocket://[::1]:0', $socketName->getValue($v6));
    }

    public function testConfigShipsTheSyncplayKnobsDefaultingOff(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/server.php';

        foreach (['syncplay_tls', 'syncplay_require_tls'] as $key) {
            self::assertArrayHasKey($key, $config, "$key must be config-visible");
            self::assertIsBool($config[$key]);
            self::assertFalse($config[$key], "$key must default false (default-off estate law)");
        }
        foreach (['syncplay_tls_cert', 'syncplay_tls_key'] as $key) {
            self::assertArrayHasKey($key, $config);
            self::assertNull($config[$key]);
        }
    }
}
