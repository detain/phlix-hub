<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\RelayServerHandler;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\Tunnel;
use RuntimeException;
use Workerman\Connection\TcpConnection;

/**
 * Review hub Major 45 law: relay teardown must survive MySQL being down.
 *
 * Tunnel::close(), Tunnel::onServerClose() and RelayServerHandler::onClose()
 * run directly inside Workerman callbacks; the repo's own doctrine
 * (Tunnel.php's decode-failure note, IdleReaper's per-section catches)
 * treats an escaping throw there as fatal to the count=1 relay worker —
 * flapping every server tunnel network-wide during a DB blip. The session
 * close is best-effort bookkeeping: reapStaleSessions() closes the orphan
 * row durably. These pins fail if the try/catch is stripped.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class TunnelTeardownDbGuardTest extends TestCase
{
    /**
     * @return array{Tunnel, RelaySessionManager&MockObject, StructuredLogger&MockObject}
     */
    private function makeTunnel(): array
    {
        $serverWs = $this->createMock(TcpConnection::class);
        $sessionManager = $this->createMock(RelaySessionManager::class);
        $logger = $this->createMock(StructuredLogger::class);

        $tunnel = new Tunnel(
            'server-123',
            $serverWs,
            $sessionManager,
            new FrameDecoder(),
            $logger,
        );
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        return [$tunnel, $sessionManager, $logger];
    }

    public function testCloseSurvivesAFailingSessionCloseAndFinishesTeardown(): void
    {
        [$tunnel, $sessionManager, $logger] = $this->makeTunnel();

        $sessionManager->method('closeSession')->willThrowException(new RuntimeException('MySQL is down'));
        $logger->expects(self::once())->method('error');

        $tunnel->close('over_flow');

        self::assertSame(
            Tunnel::STATUS_CLOSED,
            $tunnel->status,
            'teardown must run to completion despite the failed close',
        );
    }

    public function testOnServerCloseSurvivesAFailingSessionClose(): void
    {
        [$tunnel, $sessionManager, $logger] = $this->makeTunnel();

        $sessionManager->method('closeSession')->willThrowException(new RuntimeException('MySQL is down'));
        $logger->expects(self::once())->method('error');

        $tunnel->onServerClose();

        self::assertSame(Tunnel::STATUS_CLOSED, $tunnel->status, 'onClose callback path must not escape the DB error');
    }

    public function testHandlerOnCloseSurvivesAFailingSessionClose(): void
    {
        $sessionManager = $this->createMock(RelaySessionManager::class);
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new RelayServerHandler(
            $sessionManager,
            $this->createMock(EnrollmentJwtService::class),
            $logger,
        );

        $sessionManager->method('closeSession')->willThrowException(new RuntimeException('MySQL is down'));
        $logger->expects(self::once())->method('error');
        $logger->expects(self::once())->method('info');

        $handler->onClose('session-123', 'server_disconnect');
    }

    public function testHappyPathStillClosesTheSessionOnce(): void
    {
        $serverWs = $this->createMock(TcpConnection::class);
        $sessionManager = $this->createMock(RelaySessionManager::class);
        $logger = $this->createMock(StructuredLogger::class);

        $sessionManager->expects(self::once())->method('closeSession')->with('session-123', 'normal');
        $logger->expects(self::never())->method('error');

        $tunnel = new Tunnel('server-123', $serverWs, $sessionManager, new FrameDecoder(), $logger);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $tunnel->close();

        self::assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }
}
