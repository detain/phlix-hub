<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Tests\Support\TempLoggerCapture;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;

use function in_array;
use function json_encode;

/**
 * Observability pins for {@see RelayWorker} — F-3/F-4 of the live-host review
 * (.reviews/2026-11-05/HOST-2.md).
 *
 * Both findings were silence, not misbehavior:
 *
 *   - F-4: the three HELLO boundary rejects in `handleHello()` closed the
 *     connection without a single log line while the Tunnel-side HELLO rejects
 *     logged theirs. These tests pin the new warn line, its reason, its
 *     shape-failure class — and the REDACTION LAW: a planted fake enrollment
 *     JWT inside the rejected payload must never appear in the capture.
 *   - F-3: a peer that completes TCP (and possibly a ClientHello) but never
 *     finishes the TLS/WS handshake is destroyed by the vendor transport and
 *     lands in `onClose()` with no tunnel registered — previously invisible.
 *     This test pins the lifecycle line with the peer address and byte
 *     counts, and its negative: a tunnelled close never logs it.
 *
 * The close reasons on the wire are byte-identical to the pre-observability
 * behavior; the sibling pins in {@see RelayWorkerTest}
 * (testMalformedHelloClosesConnection / testHelloMissingServerIdClosesConnection)
 * stay green UNMODIFIED and together with these tests form the zero-behavior-
 * change proof.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class RelayWorkerObservabilityTest extends TestCase
{
    use TempLoggerCapture;

    /**
     * Marker planted as a HELLO enrollment_jwt value. If it ever surfaces in
     * the relay log the redaction law is broken — this string is the tripwire.
     */
    private const FAKE_JWT = 'FAKE.ENROLLMENT.JWT.MUST.NEVER.BE.LOGGED';

    private RelaySessionManager $sessionManager;
    private FrameDecoder $codec;
    private StructuredLogger $managerLogger;
    private TunnelManager $tunnelManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startLoggerCapture();

        $this->managerLogger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $this->codec = new FrameDecoder();
        $this->tunnelManager = new TunnelManager($this->sessionManager, $this->codec, $this->managerLogger);

        RelayWorker::reset();
    }

    protected function tearDown(): void
    {
        RelayWorker::reset();
        $this->stopLoggerCapture();

        parent::tearDown();
    }

    // ---- F-4: HELLO boundary rejects now warn -----------------------------

    public function testHelloJsonDecodeFailureWarnsWithReasonAndParserError(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->expects($this->once())->method('close')->with('invalid_hello');

        $relay->onMessage($serverWs, "\x00\x01not-json");

        $log = $this->capturedLoggerOutput();
        self::assertStringContainsString('Relay: server HELLO rejected before tunnel creation', $log);
        self::assertStringContainsString('"reason":"invalid_hello"', $log);
        self::assertStringContainsString('"shape_failure":"json_decode_failed"', $log);
        self::assertStringContainsString('parser_error', $log);
        self::assertSame(0, RelayWorker::getActiveConnectionCount());
    }

    public function testHelloScalarJsonWarnsWithNotAnObjectShapeFailure(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->expects($this->once())->method('close')->with('invalid_hello');

        // Valid JSON that decodes to a scalar: no parser throw, still a
        // shape failure — the distinct class must be visible in the log.
        $relay->onMessage($serverWs, '42');

        $log = $this->capturedLoggerOutput();
        self::assertStringContainsString('Relay: server HELLO rejected before tunnel creation', $log);
        self::assertStringContainsString('"reason":"invalid_hello"', $log);
        self::assertStringContainsString('"shape_failure":"hello_json_not_an_object"', $log);
    }

    public function testHelloMissingServerIdWarnsWithoutLeakingTheEnrollmentJwt(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->expects($this->once())->method('close')->with('missing_server_id');

        $payload = (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => self::FAKE_JWT,
            // server_id deliberately absent -> the third boundary reject.
        ], JSON_THROW_ON_ERROR);

        $relay->onMessage($serverWs, $payload);

        $log = $this->capturedLoggerOutput();
        self::assertStringContainsString('Relay: server HELLO rejected before tunnel creation', $log);
        self::assertStringContainsString('"reason":"missing_server_id"', $log);
        self::assertStringContainsString('"shape_failure":"server_id_absent_or_empty"', $log);
        // REDACTION LAW with teeth: the rejected payload carried a (fake)
        // durable bearer credential — it must never reach the log.
        self::assertStringNotContainsString(self::FAKE_JWT, $log);
    }

    // ---- F-3: stalled-handshake closes now log the lifecycle event --------

    public function testCloseWithoutTunnelLogsAcceptedConnectionLifecycleEvent(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $stalled = $this->createMock(TcpConnection::class);
        $stalled->method('getRemoteAddress')->willReturn('tcp://203.0.113.9:54321');
        // ClientHello-only fingerprint (live HOST-2 signature: ~1.5k read, no
        // application bytes ever written back).
        $stalled->bytesRead = 1533;
        $stalled->bytesWritten = 0;
        // LOG-ONLY law: ignoring the unregistered connection stays the entire
        // behavior — onClose must not act on the connection at all.
        $stalled->expects($this->never())->method('close');

        $relay->onClose($stalled);

        $log = $this->capturedLoggerOutput();
        self::assertStringContainsString('Relay: server connection closed without a tunnel', $log);
        self::assertStringContainsString('203.0.113.9:54321', $log);
        self::assertStringContainsString('1533', $log);
    }

    public function testCloseOfActiveTunnelDoesNotLogTheWithoutTunnelLine(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $hello = (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt.value.here',
            'server_id' => 'observed-server',
        ], JSON_THROW_ON_ERROR);
        $relay->onMessage($serverWs, $hello);

        $tunnel = $this->tunnelManager->getTunnelForServer('observed-server');
        self::assertInstanceOf(Tunnel::class, $tunnel);
        self::assertSame(Tunnel::STATUS_ACTIVE, $tunnel->status);

        $this->clearLoggerCapture();
        $relay->onClose($serverWs);

        self::assertSame(0, RelayWorker::getActiveConnectionCount());
        self::assertStringNotContainsString(
            'Relay: server connection closed without a tunnel',
            $this->capturedLoggerOutput(),
        );
    }

    /**
     * Minimal PSR-11 container exposing the real TunnelManager, mirroring
     * {@see RelayWorkerTest::buildContainer()} scaffolding.
     */
    private function buildContainer(): ContainerInterface
    {
        $tunnelManager = $this->tunnelManager;
        $sessionManager = $this->sessionManager;

        return new class ($tunnelManager, $sessionManager) implements ContainerInterface {
            public function __construct(
                private readonly TunnelManager $tunnelManager,
                private readonly RelaySessionManager $sessionManager,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    TunnelManager::class, TunnelManagerInterface::class => $this->tunnelManager,
                    RelaySessionManager::class => $this->sessionManager,
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    TunnelManager::class,
                    TunnelManagerInterface::class,
                    RelaySessionManager::class,
                ], true);
            }
        };
    }
}
