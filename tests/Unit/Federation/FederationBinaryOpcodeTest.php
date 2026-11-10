<?php

/**
 * Phlix hub component: Federation tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Http\Controllers\FederationRelayController;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Websocket;
use Workerman\Worker;

/**
 * Binary-opcode law for federation frames (review hub__asphp Major M-B).
 *
 * Every relay envelope leaving a federation socket is a BINARY frame per
 * docs/H-5 — yet both sides let the vendored encoder default the opcode to
 * TEXT (0x81, `websocketType ??= BINARY_TYPE_BLOB`), and DATA payloads
 * routinely carry length-prefixed bytes that are not valid UTF-8: a strict
 * RFC 6455 peer must fail such a link. The cure pins `websocketType =
 * BINARY_TYPE_ARRAYBUFFER` (0x82) once at the two seams where every
 * federation connection exists:
 *
 *   - master side: {@see FederationRelayController::onConnect()} — the sole
 *     registration seam for inbound leaf connections;
 *   - leaf side: {@see FederationPeerManager::establishConnection()} at the
 *     SOLE AsyncTcpConnection construction site (same site the dial-
 *     construction tripwire pins).
 *
 * The pins are wire-invisible to the estate: JSON handshake frames riding
 * the binary opcode are RFC-valid (binary frames skip UTF-8 validation) and
 * both endpoints classify frames by CONTENT, never by opcode.
 */
final class FederationBinaryOpcodeTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private const HUB_ID = 'hub-binary-opcode-uuid';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        FederationWorker::reset();
        (new ReflectionProperty(FederationRelayController::class, 'connHubIds'))->setValue(null, []);
        (new ReflectionProperty(FederationRelayController::class, 'connDecoders'))->setValue(null, []);

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-fed-opcode-' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        // establishConnection arms the HELLO_ACK timeout; seed the Workerman
        // registry so Timer::add() takes its succeeding task-table arm.
        // The trait restores every process-global it touches after each test.
        $this->forceWorkermanRuntime();
    }

    protected function tearDown(): void
    {
        FederationWorker::reset();
        LoggerFactory::reset();
        if (is_file($this->tmpDir . '/logger.php')) {
            unlink($this->tmpDir . '/logger.php');
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    // ------------------------------------------------ master-side seam

    /**
     * THE conviction test: a production-registered master connection must
     * hand the vendored encoder a BINARY opcode — even for the review's
     * exact hazard, a payload that is not valid UTF-8. Pre-cure the
     * property is never set, encode defaults it to TEXT (0x81), and the
     * first assertion below is red.
     */
    public function testMasterConnectionEncodesFederationFramesAsBinary(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            static fn (string $id): array => ['id' => $id]
        );

        $worker = $this->worker($this->controller($hubRepo), $hubRepo);
        $connection = $this->connection('198.51.100.21');

        // Full production upgrade path — the pin must ride the real seam,
        // not a hand-set property.
        $worker->onWebSocketConnect($connection, $this->upgradeRequest());
        // Post-handshake context (the encoder buffers pre-upgrade sends; the
        // federation seams only ever send after the handshake).
        $connection->context = $this->established();

        self::assertSame(
            "\x82",
            $connection->websocketType,
            'the master-side registration seam must pin the BINARY opcode',
        );

        $frame = Websocket::encode("\x00\x80\xff not-valid-utf8 DATA payload", $connection);
        self::assertSame(
            0x82,
            ord($frame[0]),
            'a federation envelope with invalid UTF-8 must leave as a binary frame',
        );
    }

    /**
     * The pin must survive the vendor's post-handshake defaulting: the
     * handshake-complete path `??=`-defaults TEXT ONLY when nothing set the
     * type yet — pin-once is a sound strategy exactly because every later
     * federation send keeps 0x82.
     */
    public function testPinSurvivesRepeatedEncodes(): void
    {
        $connection = $this->connection('198.51.100.22');
        $connection->context = $this->established();
        /** @psalm-suppress UndefinedPropertyAssignment — magic vendor @property */
        $connection->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;

        foreach (["\x00\x01short", '{"type":"hub_hello_ack"}'] as $payload) {
            $frame = Websocket::encode($payload, $connection);
            self::assertSame(0x82, ord($frame[0]), 'every federation send keeps the pinned opcode');
        }
    }

    // -------------------------------------------------- leaf-side seam

    /**
     * The real dial site: run the ACTUAL establishConnection (venue copied
     * from FederationPeerManagerTest::assertDialReachesConstruction) and
     * assert the constructed leaf connection carries the binary pin BEFORE
     * any callback is armed. Pre-cure the property is never assigned.
     */
    public function testLeafDialPinsBinaryOpcodeAtConstruction(): void
    {
        $prevLoop = Worker::$globalEvent;

        try {
            Worker::$globalEvent = new Select();

            $manager = new FederationPeerManager(
                $this->createMock(FederationHubRepository::class),
                $this->createMock(FederationSessionManager::class),
                $this->createMock(FederationLibraryShareRepository::class),
                $this->createMock(FederationAdminDelegationRepository::class),
                $this->createMock(AuditLogger::class),
                new Ed25519KeyManager($this->tmpDir . '/leaf-ed25519.pem'),
                null,
                null,
            );
            $manager->claimLeafLinkOwnership();

            $establish = new ReflectionMethod($manager, 'establishConnection');
            $establish->setAccessible(true);
            $establish->invoke($manager, [
                'uri' => 'ws://127.0.0.1:1',
                'transport' => '',
                'socket_context' => [],
            ], ['id' => self::HUB_ID]);

            /** @var AsyncTcpConnection $conn */
            $conn = (new ReflectionProperty($manager, 'masterConnection'))->getValue($manager);
            self::assertInstanceOf(AsyncTcpConnection::class, $conn);
            self::assertSame(
                "\x82",
                $conn->websocketType,
                'the leaf dial must pin the BINARY opcode at its sole construction site',
            );

            // The pin lands BEFORE connect(), transport wiring, and every
            // callback — so it is in place before the vendor's own
            // post-handshake `??=` default (which the master-side encode
            // conviction above proves is beatable). An un-handshaken client
            // socket buffers sends (handshakeStep gate) and never reaches
            // the encoder in this venue, hence no frame assertion here.
            $conn->destroy();
        } finally {
            Worker::$globalEvent = $prevLoop;
        }
    }

    // --------------------------------------------------- source law (t3)

    /**
     * Exactly two websocketType assignment sites exist in src/, one per
     * seam, and neither may assign TEXT. A third hand-rolled site would
     * drift from the two production seams; a TEXT assignment would restore
     * the defect silently (the estate parser never reads the opcode).
     */
    public function testOpcodePinsLiveExactlyAtTheTwoSeams(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $hits = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = file((string) $file->getRealPath()) ?: [];
            foreach ($lines as $number => $line) {
                if (str_contains((string) $line, '->websocketType =')) {
                    $hits[] = $file->getFilename() . ':' . (int) $number . ':' . trim((string) $line);
                }
            }
        }

        self::assertCount(2, $hits, 'src must carry exactly two opcode assignments: ' . print_r($hits, true));
        self::assertSame([], array_values(array_filter(
            $hits,
            static fn (string $hit): bool => !str_contains($hit, 'BINARY_TYPE_ARRAYBUFFER'),
        )), 'no federation send seam may assign the TEXT opcode');
        self::assertSame(1, count(array_filter(
            $hits,
            static fn (string $hit): bool => str_starts_with($hit, 'FederationRelayController.php'),
        )));
        self::assertSame(1, count(array_filter(
            $hits,
            static fn (string $hit): bool => str_starts_with($hit, 'FederationPeerManager.php'),
        )));
    }

    // ---------------------------------------------------------------- helpers

    private function worker(FederationRelayController $controller, FederationHubRepository $hubRepo): FederationWorker
    {
        $entries = [
            FederationRelayController::class => $controller,
            FederationHubRepository::class   => $hubRepo,
            RateLimitProfiles::FEDERATION_CONNECT => new RateLimiter(60, PHP_INT_MAX, 10000),
            RateLimitProfiles::FEDERATION_FRAME   => new RateLimiter(60, PHP_INT_MAX, 10000),
        ];

        $container = new class ($entries) implements ContainerInterface {
            /** @param array<string, object> $entries */
            public function __construct(private readonly array $entries)
            {
            }

            public function get(string $id): object
            {
                if (!isset($this->entries[$id])) {
                    throw new RuntimeException("unresolved: $id");
                }

                return $this->entries[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };

        return new FederationWorker($container);
    }

    /** REAL controller over a REAL frame handler (final classes — composition). */
    private function controller(FederationHubRepository $hubRepo): FederationRelayController
    {
        $connMgr = new FederationConnectionManager();
        $keyDir = $this->tmpDir . '/keys';
        mkdir($keyDir, 0700, true);

        $handler = new FederationFrameHandler(
            $hubRepo,
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $connMgr,
            $this->createMock(AuditLogger::class),
            new Ed25519KeyManager($keyDir . '/master-ed25519.pem'),
        );

        return new FederationRelayController($handler, $connMgr);
    }

    /** Mock TCP connection with a fixed raw peer address. */
    private function connection(string $remoteIp): TcpConnection&MockObject
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('getRemoteIp')->willReturn($remoteIp);

        return $connection;
    }

    /** Mark a mocked connection as post-upgrade (the encoder gates on it). */
    private function established(): stdClass
    {
        $context = new stdClass();
        $context->websocketHandshake = true;

        return $context;
    }

    /** Real Workerman WS-upgrade request for the canonical federation path. */
    private function upgradeRequest(): WorkermanRequest
    {
        $headers = [
            'Host' => 'master.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ];

        $lines = ['GET /relay/federation/' . self::HUB_ID . ' HTTP/1.1'];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }

        return new WorkermanRequest(implode("\r\n", $lines) . "\r\n\r\n");
    }
}
