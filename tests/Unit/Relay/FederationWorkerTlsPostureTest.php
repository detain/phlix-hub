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
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use Workerman\Worker;

/**
 * M22 — bind-time TLS posture law for the :8805 federation listener.
 *
 * Review hub__secnet M-3: the listener had NO native TLS path and NO posture
 * warning at all (not even the pre-M21 :8802 shape — there was nothing to
 * opt into), while its own class docblock claimed "Leaf hubs connect via
 * WSS". Confidentiality of the hub↔hub link was silently delegated to an
 * upstream proxy nothing in the repo verifies; an active on-path adversary on
 * a raw-exposed :8805 relays the whole signature-based H-4 ceremony untouched
 * (no canonical string binds the TLS channel), gets both hubs to mark
 * VERIFIED, and reads/mutates every federation payload afterwards.
 *
 * The cure mirrors the :8802 sibling shipped at c32bb524 (review hub__secnet
 * M21) — warn-only default, fail-closed when mandated, one loud advisory,
 * native opt-in wss:// — plus the docblock/docs truth fixes. These tests pin:
 *
 *   1. the pure posture truth table (secure / advisory / refused);
 *   2. the wording duty of both messages (risk + every escape hatch named);
 *   3. `require_tls` + plaintext THROWS before any bind (red on the parent:
 *      the parent happily returned a cleartext Worker — worse than :8802's
 *      pre-M21 parent, which at least had a TLS path to enable);
 *   4. the DEFAULT is not flipped: plaintext + no mandate still boots
 *      (estate default-off law — c32bb524's own rationale; the managed
 *      install's proxy-terminated :8805 is the documented common shape and a
 *      default-on mandate would break it);
 *   5. the plaintext default now logs the advisory EXACTLY ONCE (red on the
 *      parent: silence was the reviewed defect);
 *   6. the secure path logs no advisory and sets transport ssl;
 *   7. config/server.php ships federation_tls / federation_require_tls with
 *      the relay-block shapes and env truthiness (unset MUST stay warn-only);
 *   8. the construction site (HubServicesProvider::boot()) actually READS the
 *      keys and hands them to the constructor (wiring, not theater).
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class FederationWorkerTlsPostureTest extends TestCase
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
        FederationWorker::reset();
        // start() never resolves anything through the container (only
        // onWorkerStart does), so an empty mock satisfies construction.
        $this->container = $this->createMock(ContainerInterface::class);
        $this->recordedWarnings = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        FederationWorker::reset();
        LoggerFactory::reset();
    }

    /**
     * Pre-memoize a warning-recording StructuredLogger into LoggerFactory.
     *
     * LoggerFactory::get() returns the memo when present, so start()'s
     * advisory branch writes into $this->recordedWarnings and never needs a
     * real handler. LoggerFactoryIsolation restores the static cache after
     * each test. The advisory rides LogChannels::RELAY — the channel this
     * worker already logs its boot failures on, and the :8802 sibling's
     * advisory channel (M22 parity).
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
     * @param bool $useTls     federation_tls posture.
     * @param bool $requireTls federation_require_tls mandate.
     * @param string $expected Posture the machine must decide.
     *
     * @dataProvider postureProvider
     */
    public function testBootPostureTruthTable(bool $useTls, bool $requireTls, string $expected): void
    {
        self::assertSame(
            $expected,
            FederationWorker::tlsBootPosture($useTls, $requireTls),
        );
    }

    // ---- 2. wording duty ---------------------------------------------------

    public function testAdvisoryNamesTheRiskTheTopologyAndBothCureKnobs(): void
    {
        $text = FederationWorker::plaintextAdvisory(self::PORT);

        $needles = [
            'PLAINTEXT ws://',
            'H-4',
            'VERIFIED',
            'library metadata',
            'HAProxy',
            'HUB_FEDERATION_TLS=true',
            'HUB_FEDERATION_REQUIRE_TLS',
            ':' . self::PORT,
        ];
        foreach ($needles as $needle) {
            self::assertStringContainsString(
                $needle,
                $text,
                "advisory must carry '{$needle}' — operators need the risk, the one topology "
                    . 'where plaintext is intended, AND every way out in one line',
            );
        }
    }

    public function testRefusalNamesTheMandateTheConsequenceAndTheWayBack(): void
    {
        $text = FederationWorker::plaintextRefusal(self::PORT);

        $needles = [
            'Refusing to bind',
            'federation_require_tls',
            'HUB_FEDERATION_REQUIRE_TLS=1',
            'Federation inbound is NOT started',
            'HUB_FEDERATION_TLS=true',
            'warn-only behavior',
            ':' . self::PORT,
        ];
        foreach ($needles as $needle) {
            self::assertStringContainsString(
                $needle,
                $text,
                'refusal must be actionable through boot()\'s skip log line: mandate, '
                    . 'consequence, and BOTH exits (secure it / drop the mandate)',
            );
        }
    }

    // ---- 3. mandate + plaintext = no listener, ever ------------------------

    public function testRequireTlsWithoutTlsRefusesToBindBeforeAnySocket(): void
    {
        $worker = new FederationWorker(
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
            self::fail('require_tls + plaintext must throw, never bind a cleartext federation listener');
        } catch (RuntimeException $e) {
            self::assertSame(
                FederationWorker::plaintextRefusal(self::PORT),
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

        $worker = new FederationWorker($this->container, self::PORT);

        // Parent shipped exactly this construction bound SILENTLY cleartext;
        // the cure adds the announcement, not a refusal. A default flip would
        // make this red — that flip is an estate-wide owner call (c32bb524's
        // own law: both ends of a link move in one wave, never one alone; the
        // managed install's proxy-terminated :8805 is the shape a mandate-by-
        // default would punish).
        self::assertInstanceOf(Worker::class, $worker->start());
    }

    // ---- 5. the plaintext default announces itself --------------------------

    public function testPlaintextDefaultLogsTheAdvisoryExactlyOnce(): void
    {
        $this->seedRecordingLogger();

        $worker = new FederationWorker($this->container, self::PORT);
        $worker->start();

        self::assertCount(
            1,
            $this->recordedWarnings,
            'one loud advisory per boot on the plaintext path — silence was the reviewed defect',
        );
        self::assertSame(
            FederationWorker::plaintextAdvisory(self::PORT),
            $this->recordedWarnings[0],
            'the logged line must be the advisory text verbatim',
        );
    }

    // ---- 6. secure path: no advisory, ssl transport --------------------------

    public function testTlsEnabledPostureLogsNoAdvisoryAndSetsSslTransport(): void
    {
        $this->seedRecordingLogger();

        $worker = new FederationWorker(
            $this->container,
            self::PORT,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            true,
            '/tmp/phlix-m22-absent-cert.pem',
            '/tmp/phlix-m22-absent-key.pem',
            true,
        );
        $workerman = $worker->start();

        self::assertSame([], $this->recordedWarnings, 'a wss:// bind must never cry wolf');
        self::assertSame('ssl', $workerman->transport);
    }

    public function testTlsEnabledWithoutCertKeyFailsLoud(): void
    {
        $worker = new FederationWorker(
            $this->container,
            self::PORT,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            true,
            null,
            null,
            false,
        );

        try {
            $worker->start();
            self::fail('TLS on without cert/key must name the missing envs, never bind');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('HUB_FEDERATION_TLS_CERT', $e->getMessage());
            self::assertStringContainsString('HUB_FEDERATION_TLS_KEY', $e->getMessage());
        }
    }

    // ---- 7. config law -------------------------------------------------------

    public function testConfigShipsFederationTlsKnobs(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/server.php';

        foreach (['federation_tls', 'federation_tls_cert', 'federation_tls_key', 'federation_require_tls'] as $key) {
            self::assertArrayHasKey(
                $key,
                $config,
                "the federation TLS knob '{$key}' must be config-visible, mirroring the relay block",
            );
        }

        self::assertIsBool($config['federation_tls']);
        self::assertIsBool($config['federation_require_tls']);
    }

    public function testConfigMandateHonorsEnvTruthinessWhenUnset(): void
    {
        $restore = getenv('HUB_FEDERATION_REQUIRE_TLS');
        putenv('HUB_FEDERATION_REQUIRE_TLS');

        try {
            /** @var array<string, mixed> $config */
            $config = require __DIR__ . '/../../../config/server.php';
            self::assertFalse(
                $config['federation_require_tls'],
                'unset env MUST keep warn-only default (never fail closed by accident)',
            );
        } finally {
            if (is_string($restore)) {
                putenv('HUB_FEDERATION_REQUIRE_TLS=' . $restore);
            }
        }
    }

    // ---- 8. the construction site is real wiring -----------------------------

    public function testBootProviderReadsFederationTlsKeysAndPassesThem(): void
    {
        $provider = (string) file_get_contents(
            __DIR__ . '/../../../src/Common/Container/Providers/HubServicesProvider.php'
        );

        foreach (
            [
                '$appConfig[\'federation_tls\'] ?? false',
                '$appConfig[\'federation_tls_cert\'] ?? null',
                '$appConfig[\'federation_tls_key\'] ?? null',
                '$appConfig[\'federation_require_tls\'] ?? false',
            ] as $read
        ) {
            self::assertStringContainsString(
                $read,
                $provider,
                'boot() must read the key (the constructor default alone is not wiring)',
            );
        }

        self::assertStringContainsString(
            "                \$channelPort,
                \$federationTls,
                \$federationTlsCert,
                \$federationTlsKey,
                \$federationRequireTls,
            );",
            $provider,
            'the parsed TLS values must be what is actually constructed'
        );
    }
}
