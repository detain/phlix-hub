<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Relay\WebSocketCloseFrame;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

use function pack;

/**
 * Unit tests for {@see WebSocketCloseFrame} — the L-4 fix.
 *
 * Pins the exact RFC 6455 close-frame bytes (FIN+opcode 0x8, unmasked server
 * frame, 2-byte big-endian code) for the codes the relay workers reject with,
 * the fail-fast range guard, and the two reject() regimes:
 *
 *  - POST-handshake: a real close frame goes out immediately.
 *  - PRE-handshake (inside onWebSocketConnect, before Workerman emits the
 *    101): rejection is DEFERRED onto onWebSocketConnected so the client
 *    receives a proper WS close code instead of the historical literal-text
 *    garbage (`close((string) 4401, true)` — finding L-4).
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class WebSocketCloseFrameTest extends TestCase
{
    public function testBytesEncodeTheDocumentedCodesAsRealCloseFrames(): void
    {
        // 4401 = 0x1131, 1013 = 0x03f5, 1011 = 0x03f3 — FIN+close opcode,
        // unmasked (server→client), 2-byte payload.
        self::assertSame("\x88\x02\x11\x31", WebSocketCloseFrame::bytes(4401));
        self::assertSame("\x88\x02\x03\xf5", WebSocketCloseFrame::bytes(1013));
        self::assertSame("\x88\x02\x03\xf3", WebSocketCloseFrame::bytes(1011));
        self::assertSame("\x88\x02" . pack('n', 4000), WebSocketCloseFrame::bytes(4000));
    }

    public function testBytesRejectCodesOutsideTheLegalCloseRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebSocketCloseFrame::bytes(999);
    }

    public function testBytesRejectCodesAboveTheSixteenBitCeiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebSocketCloseFrame::bytes(5000);
    }

    public function testRejectAfterHandshakeClosesImmediatelyWithTheFrame(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->context = new \stdClass();
        $connection->context->websocketHandshake = true;

        /** @var list<array{0: mixed, 1: bool}> $closed */
        $closed = [];
        $connection->method('close')->willReturnCallback(
            static function (mixed $data, bool $raw = false) use (&$closed): void {
                $closed[] = [$data, $raw];
            },
        );

        WebSocketCloseFrame::reject($connection, 4401);

        self::assertCount(1, $closed, 'post-handshake rejection must close at once');
        self::assertSame(["\x88\x02\x11\x31", true], $closed[0]);
        self::assertNull($connection->onWebSocketConnected, 'no deferral needed post-handshake');
    }

    public function testRejectDuringHandshakeDefersUntilConnectedThenSendsTheFrame(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        // Pre-handshake: Workerman has NOT yet set context->websocketHandshake.
        $connection->context = new \stdClass();

        /** @var list<array{0: mixed, 1: bool}> $closed */
        $closed = [];
        $connection->method('close')->willReturnCallback(
            static function (mixed $data, bool $raw = false) use (&$closed): void {
                $closed[] = [$data, $raw];
            },
        );

        WebSocketCloseFrame::reject($connection, 4401);

        // Nothing may be closed yet — closing now would abort the 101 and put
        // raw frame bytes in front of an HTTP client (the L-4 defect shape).
        self::assertCount(0, $closed, 'pre-handshake rejection must NOT close before the 101');
        self::assertInstanceOf(\Closure::class, $connection->onWebSocketConnected);

        // Simulate Workerman finishing the handshake (dealHandshake passes
        // ($connection, $request)); the armed rejection must now fire.
        ($connection->onWebSocketConnected)(
            $connection,
            $this->createMock(\Workerman\Protocols\Http\Request::class),
        );

        self::assertCount(1, $closed, 'the armed rejection must close exactly once');
        self::assertSame(["\x88\x02\x11\x31", true], $closed[0]);
    }

    public function testRejectToleratesMissingContextObject(): void
    {
        // A connection whose context is null (never handshaked) must take the
        // deferral path rather than explode on property access.
        $connection = $this->createMock(TcpConnection::class);
        $connection->context = null;

        $connection->expects(self::never())->method('close');

        WebSocketCloseFrame::reject($connection, 1013);

        self::assertInstanceOf(\Closure::class, $connection->onWebSocketConnected);
    }
}
