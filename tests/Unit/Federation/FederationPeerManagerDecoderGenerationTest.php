<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Events\Select;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
 * Review hub__asphp M-HUB-02: the leaf-side FederationPeerManager owns ONE
 * FrameDecoder for the whole process lifetime, while every re-dial creates a
 * fresh AsyncTcpConnection on the same instance. When a link tore mid-frame,
 * the partial bytes stayed buffered and spliced into the NEXT connection's
 * stream — on a freshly authenticated link.
 *
 * The cure is law 8 (class header): establishConnection() resets the decoder
 * at the head of every dial. These tests pin the disease at the true dispatch
 * seam (handleMessage → isTextFrame → handleBinaryFrame → decode), driving the
 * REAL establishConnection for the generation transition — no reflecting into
 * the decoder itself, so a future cure that replaces (instead of resets) the
 * decoder instance still passes only if it is genuinely generation-clean.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationPeerManagerDecoderGenerationTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private FederationPeerManager $manager;

    private FederationHubRepository $hubRepo;

    private FederationSessionManager $sessions;

    private FederationLibraryShareRepository $libraryShares;

    private FederationAdminDelegationRepository $adminDel;

    /**
     * @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject
     */
    private AuditLogger $audit;

    private string $keyDir;

    private string|false $loggerTmp = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->sessions = $this->createMock(FederationSessionManager::class);
        $this->libraryShares = $this->createMock(FederationLibraryShareRepository::class);
        $this->adminDel = $this->createMock(FederationAdminDelegationRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);

        $this->keyDir = sys_get_temp_dir() . '/phlix-hub-fed-decgen-keys-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);

        $this->manager = new FederationPeerManager(
            $this->hubRepo,
            $this->sessions,
            $this->libraryShares,
            $this->adminDel,
            $this->audit,
            new Ed25519KeyManager($this->keyDir . '/leaf-ed25519.pem'),
        );

        // Reconnect/ack paths log through LoggerFactory::get(RELAY).
        $this->loggerTmp = sys_get_temp_dir() . '/phlix-hub-fed-decgen-' . bin2hex(random_bytes(6));
        mkdir($this->loggerTmp, 0700, true);
        file_put_contents(
            $this->loggerTmp . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->loggerTmp . '/logger.php');

        // establishConnection arms the HELLO_ACK timeout; seed the Workerman
        // registry so Timer::add() takes its succeeding task-table arm.
        // The trait restores every process-global it touches after each test.
        $this->forceWorkermanRuntime();
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        if ($this->loggerTmp !== false && is_file($this->loggerTmp . '/logger.php')) {
            unlink($this->loggerTmp . '/logger.php');
            rmdir($this->loggerTmp);
        }
        if (is_dir($this->keyDir)) {
            foreach (glob($this->keyDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($this->keyDir);
        }
        parent::tearDown();
    }

    /**
     * THE regression: generation 1 tears with a DATA header buffered and its
     * payload missing; generation 2 (a NEW dial through the REAL
     * establishConnection) then receives one clean HEARTBEAT.
     *
     * Pre-fix the stale header consumed the heartbeat's 7 bytes as its own
     * payload: a spliced garbage DATA frame reached handleDataFrame() (the H-4
     * unverified-channel gate logged the refusal) and the legitimate heartbeat
     * was swallowed — zero pong, and the master's liveness probe would
     * eventually drop the link. Post-fix the heartbeat decodes exactly:
     * one HEARTBEAT pong on the new socket, zero audit noise.
     */
    public function testTornFrameResidueCannotSpliceIntoNextGeneration(): void
    {
        // --- Generation 1: live link, torn mid-frame.
        $gen1 = $this->createMock(AsyncTcpConnection::class);
        $this->setMasterConnection($gen1);

        $encoder = new FrameEncoder();
        // A DATA frame whose 7-byte header declares a 7-byte payload; only
        // the header arrives before the link dies.
        $full = $encoder->encode(RelayFrameType::DATA, 1, '{"x":1}');
        self::assertSame(14, strlen($full), 'premise: header(7) + payload(7)');
        $this->invokeHandleMessage(substr($full, 0, 7));

        self::assertSame(
            7,
            $this->decoderBufferSize(),
            'premise: the torn header must be buffering inside the shared decoder',
        );

        // --- Teardown exactly as the deliberate/timeout paths do.
        $this->manager->disconnectFromMaster();
        self::assertSame(
            7,
            $this->decoderBufferSize(),
            'premise: NO teardown path resets the decoder — the residue survives',
        );

        // --- Generation 2: the real establishConnection seam dials a fresh
        // socket (law 8 must fire here); swap in a recording mock for delivery.
        $prevLoop = Worker::$globalEvent;
        try {
            Worker::$globalEvent = new Select();
            $this->invokeEstablish();
        } finally {
            Worker::$globalEvent = $prevLoop;
        }

        $fresh = $this->readProp('masterConnection');
        self::assertInstanceOf(
            AsyncTcpConnection::class,
            $fresh,
            'premise: the dial must have constructed the new-generation socket',
        );
        $fresh->destroy();

        $sent = [];
        $gen2 = $this->createMock(AsyncTcpConnection::class);
        $gen2->method('send')->willReturnCallback(
            static function (string $data) use (&$sent): bool {
                $sent[] = $data;
                return true;
            },
        );
        $this->setMasterConnection($gen2);

        // Zero unverified-channel DATA refusals across the whole scenario:
        // pre-fix the spliced stale header + heartbeat bytes IS such a frame.
        $this->audit->expects(self::never())->method('logFailedAuth');

        $this->invokeHandleMessage($encoder->encode(RelayFrameType::HEARTBEAT, 0, ''));

        self::assertCount(
            1,
            $sent,
            'the clean heartbeat on the new generation must decode and answer with exactly one pong',
        );
        $pong = (new FrameDecoder())->decode($sent[0]);
        self::assertNotNull($pong, 'the recorded reply must be a complete frame');
        self::assertSame(RelayFrameType::HEARTBEAT, $pong->type, 'the reply must be a HEARTBEAT');

        self::assertSame(
            0,
            $this->decoderBufferSize(),
            'generation 2 must finish with zero residue carried into generation 3',
        );

        $this->manager->disconnectFromMaster();
    }

    /**
     * Control pinning the cure is per-GENERATION, not per-message: a frame
     * legitimately split across two messages of the SAME live link must still
     * accumulate and decode once whole. Guards against a future "fix" that
     * resets on every message arrival and silently breaks fragmentation.
     */
    public function testPartialFramesStillAccumulateWithinOneGeneration(): void
    {
        $sent = [];
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$sent): bool {
                $sent[] = $data;
                return true;
            },
        );
        $this->setMasterConnection($conn);

        $heartbeat = (new FrameEncoder())->encode(RelayFrameType::HEARTBEAT, 0, '');

        $this->invokeHandleMessage(substr($heartbeat, 0, 3));
        self::assertSame(3, $this->decoderBufferSize(), 'premise: the split prefix buffers');
        self::assertCount(0, $sent, 'an incomplete frame must not answer yet');

        $this->invokeHandleMessage(substr($heartbeat, 3));

        self::assertCount(1, $sent, 'the completed same-generation frame must answer once');
        self::assertSame(0, $this->decoderBufferSize());

        $this->manager->disconnectFromMaster();
    }

    // ------------------------------------------------------------- harness

    /**
     * Fire the private dial site with the plaintext loopback shape, exactly
     * as connectToMaster would after a reconnect tick.
     */
    private function invokeEstablish(): void
    {
        $method = new ReflectionMethod($this->manager, 'establishConnection');
        $method->setAccessible(true);
        $method->invoke($this->manager, [
            'uri' => 'ws://127.0.0.1:1/relay/federation/leaf',
            'transport' => '',
            'socket_context' => [],
        ], ['id' => 'own-leaf-id']);
    }

    /**
     * Drive bytes through the real WS dispatch: handleMessage → isTextFrame →
     * handleBinaryFrame → the shared decoder.
     */
    private function invokeHandleMessage(string $data): void
    {
        $method = new ReflectionMethod($this->manager, 'handleMessage');
        $method->setAccessible(true);
        $method->invoke($this->manager, $data);
    }

    private function decoderBufferSize(): int
    {
        $decoder = $this->readProp('decoder');
        self::assertInstanceOf(FrameDecoder::class, $decoder);

        return $decoder->getBufferSize();
    }

    private function setMasterConnection(AsyncTcpConnection $conn): void
    {
        $this->writeProp('masterConnection', $conn);
    }

    private function readProp(string $name): mixed
    {
        $property = new ReflectionProperty($this->manager, $name);
        $property->setAccessible(true);

        return $property->getValue($this->manager);
    }

    private function writeProp(string $name, mixed $value): void
    {
        $property = new ReflectionProperty($this->manager, $name);
        $property->setAccessible(true);
        $property->setValue($this->manager, $value);
    }
}
