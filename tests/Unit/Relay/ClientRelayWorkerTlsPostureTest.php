<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use Workerman\Worker;

/**
 * SH-064 (review hub__secnet#1) — bind-time TLS posture + bind-host law for
 * the :8803 CLIENT relay listener, the two sibling surfaces (:8803, :8804)
 * that M21/M22 never reached: both still hardcoded `0.0.0.0` with no native
 * wss:// path, no mandate knob, and no boot announcement, while their payload
 * is bearer-class (client relay tokens + everything relayed through them).
 *
 * The cure mirrors the shipped :8802 (M21) pattern on the listen end with
 * per-surface wording duty; these tests pin:
 *
 *   1. the pure posture truth table (secure / advisory / refused);
 *   2. the wording duty of both messages (risk + every escape hatch named,
 *      including the new HUB_WS_BIND bind-host cure);
 *   3. `require_tls` + plaintext THROWS before any bind (red on the parent:
 *      the parent happily returned a cleartext Worker);
 *   4. the DEFAULT is not flipped: plaintext + no mandate still boots
 *      (estate default-off law — same posture as :8802's warn-only);
 *   5. the plaintext default logs the advisory EXACTLY ONCE (red on the
 *      parent: silence was the defect);
 *   6. the secure path logs no advisory, rides transport ssl, carries the
 *      hardened context, and fails fast LOUD on missing material;
 *   7. the bind host honors HUB_WS_BIND (default 0.0.0.0 unchanged — the
 *      bind-default flip is an estate owner call, not this lane's);
 *   8. config/server.php ships the knobs defaulting off.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class ClientRelayWorkerTlsPostureTest extends TestCase
{
    // start() builds a real Workerman Worker (process-global latch) and the
    // advisory path touches the LoggerFactory statics; both traits snapshot
    // before setUp() and restore after tearDown().
    use WorkermanTimerRuntimeControl;
    use LoggerFactoryIsolation;

    /** Port used everywhere: 0 never binds (bind happens only in runAll()). */
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

    /**
     * Pre-memoize a warning-recording StructuredLogger into LoggerFactory
     * (same harness law as RelayWorkerTlsPostureTest).
     */
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
        $path = sys_get_temp_dir() . '/phlix-sh064-cr-' . $role . '-' . bin2hex(random_bytes(6)) . '.pem';
        if (file_put_contents($path, "-----BEGIN PHLEX SCRATCH " . strtoupper($role) . "-----\n") === false) {
            self::fail("could not write scratch $role pem to $path");
        }
        $this->scratchFiles[] = $path;

        return $path;
    }

    // ---- 1. posture truth table ---------------------------------------------

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

    /**
     * @dataProvider postureProvider
     */
    public function testBootPostureTruthTable(bool $useTls, bool $requireTls, string $expected): void
    {
        self::assertSame($expected, ClientRelayWorker::tlsBootPosture($useTls, $requireTls));
    }

    // ---- 2. wording duty -----------------------------------------------------

    public function testAdvisoryNamesTheRiskAndEveryCureKnob(): void
    {
        $text = ClientRelayWorker::plaintextAdvisory(self::PORT);

        $needles = [
            'PLAINTEXT ws://',
            'bearer',
            'HAProxy',
            'HUB_CLIENT_RELAY_TLS=true',
            'HUB_CLIENT_RELAY_REQUIRE_TLS',
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
        $text = ClientRelayWorker::plaintextRefusal(self::PORT);

        $needles = [
            'Refusing to bind',
            'client_relay_require_tls',
            'HUB_CLIENT_RELAY_REQUIRE_TLS=1',
            'bearer',
            'HUB_CLIENT_RELAY_TLS=true',
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

    // ---- 3. mandate + plaintext = loud boot failure ---------------------------

    public function testRequireTlsWithoutTlsRefusesToBindBeforeAnySocket(): void
    {
        $worker = new ClientRelayWorker($this->container, self::PORT, requireTls: true);

        try {
            $worker->start();
            self::fail('require_tls + plaintext must throw, never bind a cleartext listener');
        } catch (RuntimeException $e) {
            self::assertSame(
                ClientRelayWorker::plaintextRefusal(self::PORT),
                $e->getMessage(),
                'the boot failure must carry the actionable refusal text verbatim',
            );
        }
    }

    // ---- 4. the default is NOT flipped (estate default-off law) ---------------

    public function testPlaintextDefaultStillBootsWarnOnly(): void
    {
        $this->seedRecordingLogger();

        // A default flip would make this red — that flip is an estate-wide
        // owner call (the managed install fronts :8803 through HAProxy; docs
        // and proxy topology move together or neither does).
        $worker = new ClientRelayWorker($this->container, self::PORT);
        self::assertInstanceOf(Worker::class, $worker->start());
    }

    // ---- 5. the plaintext default announces itself -----------------------------

    public function testPlaintextDefaultLogsTheAdvisoryExactlyOnce(): void
    {
        $this->seedRecordingLogger();

        $worker = new ClientRelayWorker($this->container, self::PORT);
        $worker->start();

        self::assertCount(
            1,
            $this->recordedWarnings,
            'one loud advisory per boot on the plaintext path — silence was the reviewed defect',
        );
        self::assertSame(ClientRelayWorker::plaintextAdvisory(self::PORT), $this->recordedWarnings[0]);
    }

    // ---- 6. secure path ---------------------------------------------------------

    public function testTlsEnabledPostureLogsNoAdvisorySetsSslTransportAndCarriesHardenedContext(): void
    {
        $this->seedRecordingLogger();
        $cert = $this->writeScratchPem('cert');
        $key = $this->writeScratchPem('key');

        $worker = (new ClientRelayWorker(
            $this->container,
            self::PORT,
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
        self::assertTrue($ssl['disable_compression']);
    }

    public function testTlsWithMissingMaterialFailsFastLoudBeforeAnyBind(): void
    {
        $this->seedRecordingLogger();

        try {
            (new ClientRelayWorker(
                $this->container,
                self::PORT,
                useTls: true,
                tlsCert: '/tmp/phlix-sh064-definitely-absent-cert.pem',
                tlsKey: '/tmp/phlix-sh064-definitely-absent-key.pem',
            ))->start();
            self::fail('configured-but-missing PEMs must fail fast, not bind half-configured TLS');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('HUB_CLIENT_RELAY_TLS_CERT', $e->getMessage());
            self::assertStringContainsString('does not exist', $e->getMessage());
            // The way back must be named (bootDecision message law).
            self::assertStringContainsString('HUB_CLIENT_RELAY_TLS=false', $e->getMessage());
        }
    }

    // ---- 7. bind-host law (HUB_WS_BIND governs :8803/:8804, default unchanged) --

    public function testBindHostDefaultsToAllInterfacesAndHonorsTheKnob(): void
    {
        $this->seedRecordingLogger();
        $socketName = new ReflectionProperty(Worker::class, 'socketName');

        $default = (new ClientRelayWorker($this->container, self::PORT))->start();
        self::assertSame('websocket://0.0.0.0:0', $socketName->getValue($default));

        $loopback = (new ClientRelayWorker($this->container, self::PORT, bindHost: '127.0.0.1'))->start();
        self::assertSame('websocket://127.0.0.1:0', $socketName->getValue($loopback));

        // IPv6 bind hosts need brackets or stream_socket_server cannot parse
        // the listen string — the knob must not be a silent-bind trap.
        $v6 = (new ClientRelayWorker($this->container, self::PORT, bindHost: '::1'))->start();
        self::assertSame('websocket://[::1]:0', $socketName->getValue($v6));
    }

    // ---- 8. config law -----------------------------------------------------------

    public function testConfigShipsTheClientRelayKnobsDefaultingOff(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/server.php';

        foreach (['client_relay_tls', 'client_relay_require_tls'] as $key) {
            self::assertArrayHasKey($key, $config, "$key must be config-visible");
            self::assertIsBool($config[$key]);
            self::assertFalse($config[$key], "$key must default false (default-off estate law)");
        }
        foreach (['client_relay_tls_cert', 'client_relay_tls_key'] as $key) {
            self::assertArrayHasKey($key, $config);
            self::assertNull($config[$key]);
        }
        self::assertArrayHasKey('ws_bind_host', $config);
        self::assertSame(
            '0.0.0.0',
            $config['ws_bind_host'],
            'the bind DEFAULT is not flipped here (that is an estate owner call); '
                . 'the knob makes the documented proxy-fronted escape real',
        );
    }

    public function testConfigKnobsHonorEnvTruthinessWhenUnset(): void
    {
        $restore = [];
        foreach (['HUB_CLIENT_RELAY_REQUIRE_TLS', 'HUB_SYNCPLAY_REQUIRE_TLS', 'HUB_WS_BIND'] as $name) {
            $restore[$name] = getenv($name);
            putenv($name);
        }

        try {
            /** @var array<string, mixed> $config */
            $config = require __DIR__ . '/../../../config/server.php';
            self::assertFalse($config['client_relay_require_tls']);
            self::assertSame('0.0.0.0', $config['ws_bind_host']);
        } finally {
            foreach ($restore as $name => $value) {
                if (is_string($value)) {
                    putenv($name . '=' . $value);
                }
            }
        }
    }
}
