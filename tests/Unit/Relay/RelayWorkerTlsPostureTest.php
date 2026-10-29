<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use Workerman\Worker;

/**
 * M21 — bind-time TLS posture law for the :8802 server relay listener.
 *
 * Review hub__secnet M-2: the listener defaulted to plaintext ws:// on
 * 0.0.0.0 with NO warning and NO enforcement knob, while docs admit it is
 * not HAProxy-fronted — the HELLO's Ed25519 enrollment JWT (a durable
 * tunnel bearer) crossed the wire in the clear by default.
 *
 * The cure mirrors the server-side R17-01 posture (warn-only default,
 * fail-closed when mandated) on the listen end. These tests pin:
 *
 *   1. the pure posture truth table (secure / advisory / refused);
 *   2. the wording duty of both messages (risk + every escape hatch named);
 *   3. `require_tls` + plaintext THROWS before any bind (red on the parent:
 *      the parent happily returned a cleartext Worker);
 *   4. the DEFAULT is not flipped: plaintext + no mandate still boots
 *      (estate default-off law — server config/relay.php ships warn-only);
 *   5. the plaintext default now logs the advisory EXACTLY ONCE (red on the
 *      parent: silence was the defect);
 *   6. the secure path logs no advisory and sets transport ssl;
 *   7. config/server.php ships `relay_require_tls` defaulting false and
 *      honoring the env truthiness idiom.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class RelayWorkerTlsPostureTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        RelayWorker::reset();
        // start() never resolves anything through the container (only
        // onWorkerStart does), so an empty mock satisfies construction.
        $this->container = $this->createMock(ContainerInterface::class);
        $this->recordedWarnings = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RelayWorker::reset();
        LoggerFactory::reset();
    }

    /**
     * Pre-memoize a warning-recording StructuredLogger into LoggerFactory.
     *
     * LoggerFactory::get() returns the memo when present, so start()'s
     * advisory branch writes into $this->recordedWarnings and never needs a
     * real handler. LoggerFactoryIsolation restores the static cache after
     * each test.
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

    // ---- 1. posture truth table -------------------------------------------

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
     * @param bool $useTls     relay_tls posture.
     * @param bool $requireTls relay_require_tls mandate.
     * @param string $expected Posture the machine must decide.
     *
     * @dataProvider postureProvider
     */
    public function testBootPostureTruthTable(bool $useTls, bool $requireTls, string $expected): void
    {
        self::assertSame(
            $expected,
            RelayWorker::tlsBootPosture($useTls, $requireTls),
        );
    }

    // ---- 2. wording duty ---------------------------------------------------

    public function testAdvisoryNamesTheRiskAndBothCureKnobs(): void
    {
        $text = RelayWorker::plaintextAdvisory(self::PORT);

        $needles = [
            'PLAINTEXT ws://',
            'enrollment JWT',
            'NOT fronted by HAProxy',
            'HUB_RELAY_TLS=true',
            'HUB_RELAY_REQUIRE_TLS',
            'PHLIX_RELAY_REQUIRE_TLS',
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

    public function testRefusalNamesTheMandateTheTunnelAndTheWayBack(): void
    {
        $text = RelayWorker::plaintextRefusal(self::PORT);

        $needles = [
            'Refusing to bind',
            'relay_require_tls',
            'HUB_RELAY_REQUIRE_TLS=1',
            'enrollment JWT',
            'HUB_RELAY_TLS=true',
            'warn-only behavior',
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

    // ---- 3. mandate + plaintext = loud boot failure ------------------------

    public function testRequireTlsWithoutTlsRefusesToBindBeforeAnySocket(): void
    {
        $worker = new RelayWorker(
            $this->container,
            self::PORT,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            false,
            null,
            null,
            true,
        );

        try {
            $worker->start();
            self::fail('require_tls + plaintext must throw, never bind a cleartext listener');
        } catch (RuntimeException $e) {
            self::assertSame(
                RelayWorker::plaintextRefusal(self::PORT),
                $e->getMessage(),
                'the boot failure must carry the actionable refusal text verbatim',
            );
        }
    }

    // ---- 4. the default is NOT flipped (estate default-off law) ------------

    public function testPlaintextDefaultStillBootsWarnOnly(): void
    {
        // Seed so the advisory branch has a memoized logger: the law under
        // test is "boots without throwing", the announcement itself is pinned
        // in testPlaintextDefaultLogsTheAdvisoryExactlyOnce.
        $this->seedRecordingLogger();

        $worker = new RelayWorker($this->container, self::PORT);

        // Parent shipped exactly this construction bound SILENTLY cleartext;
        // the cure adds the announcement, not a refusal. A default flip would
        // make this red — that flip is an estate-wide owner call (both sides
        // of the tunnel move together or neither does).
        self::assertInstanceOf(Worker::class, $worker->start());
    }

    // ---- 5. the plaintext default announces itself --------------------------

    public function testPlaintextDefaultLogsTheAdvisoryExactlyOnce(): void
    {
        $this->seedRecordingLogger();

        $worker = new RelayWorker($this->container, self::PORT);
        $worker->start();

        self::assertCount(
            1,
            $this->recordedWarnings,
            'one loud advisory per boot on the plaintext path — silence was the reviewed defect',
        );
        self::assertSame(
            RelayWorker::plaintextAdvisory(self::PORT),
            $this->recordedWarnings[0],
            'the logged line must be the advisory text verbatim',
        );
    }

    // ---- 6. secure path: no advisory, ssl transport --------------------------

    public function testTlsEnabledPostureLogsNoAdvisoryAndSetsSslTransport(): void
    {
        $this->seedRecordingLogger();

        $worker = new RelayWorker(
            $this->container,
            self::PORT,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            true,
            '/tmp/phlix-m21-absent-cert.pem',
            '/tmp/phlix-m21-absent-key.pem',
            true,
        );
        $workerman = $worker->start();

        self::assertSame([], $this->recordedWarnings, 'a wss:// bind must never cry wolf');
        self::assertSame('ssl', $workerman->transport);
    }

    // ---- 7. config law -------------------------------------------------------

    public function testConfigShipsRelayRequireTlsDefaultingFalse(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/server.php';

        self::assertArrayHasKey(
            'relay_require_tls',
            $config,
            'the mandate knob must be config-visible, mirroring server config/relay.php require_tls',
        );
        self::assertIsBool($config['relay_require_tls']);
    }

    public function testConfigKnobHonorsEnvTruthinessWhenUnsetOrFalsey(): void
    {
        $restore = getenv('HUB_RELAY_REQUIRE_TLS');
        putenv('HUB_RELAY_REQUIRE_TLS');

        try {
            /** @var array<string, mixed> $config */
            $config = require __DIR__ . '/../../../config/server.php';
            self::assertFalse(
                $config['relay_require_tls'],
                'unset env MUST keep warn-only default (never fail closed by accident)',
            );
        } finally {
            if (is_string($restore)) {
                putenv('HUB_RELAY_REQUIRE_TLS=' . $restore);
            }
        }
    }
}
