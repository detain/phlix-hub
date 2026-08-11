<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Common\Logger\AuditLogger;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\ConnectionInterface;

/**
 * Unit tests for {@see FederationFrameHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationFrameHandlerTest extends TestCase
{
    private FederationConnectionManager $realConnMgr;

    protected function setUp(): void
    {
        parent::setUp();
        // FederationConnectionManager is final, so we use a real instance
        $this->realConnMgr = new FederationConnectionManager();
    }

    private function handler(
        ?FederationHubRepository $hubRepo = null,
        ?FederationSessionManager $sessions = null,
        ?FederationLibraryShareRepository $libraryShares = null,
        ?AuditLogger $audit = null,
    ): FederationFrameHandler {
        return new FederationFrameHandler(
            $hubRepo ?? $this->createMock(FederationHubRepository::class),
            $sessions ?? $this->createMock(FederationSessionManager::class),
            $libraryShares ?? $this->createMock(FederationLibraryShareRepository::class),
            $this->realConnMgr,
            $audit ?? $this->createMock(AuditLogger::class),
        );
    }

    // -------------------------------------------------------- handleTextFrame

    public function testHandleTextFrameRejectsInvalidJson(): void
    {
        $handler = $this->handler();
        $result = $handler->handleTextFrame('hub-1', 'not valid json {');

        self::assertSame('Invalid JSON payload', $result);
    }

    public function testHandleTextFrameRejectsNonArrayPayload(): void
    {
        $handler = $this->handler();
        $result = $handler->handleTextFrame('hub-1', '"just a string"');

        self::assertSame('Invalid frame payload', $result);
    }

    public function testHandleTextFrameRejectsMissingType(): void
    {
        $handler = $this->handler();
        $result = $handler->handleTextFrame('hub-1', '{"foo":"bar"}');

        self::assertSame('Missing frame type', $result);
    }

    public function testHandleTextFrameAcceptsUnknownTypeAsNoOp(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->expects(self::never())->method('getPeerByPublicKey');

        $handler = $this->handler($hubRepo);
        $result = $handler->handleTextFrame('hub-1', '{"type":"unknown_type"}');

        self::assertNull($result);
    }

    public function testHandleTextFrameHubHelloRejectsEmptyPublicKey(): void
    {
        $handler = $this->handler();
        $result = $handler->handleTextFrame('hub-1', '{"type":"hub_hello","public_key":""}');

        self::assertSame('Invalid peer key', $result);
    }

    public function testHandleTextFrameHubHelloRejectsUnknownPeer(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn(null);

        $handler = $this->handler($hubRepo);
        $result = $handler->handleTextFrame('hub-1', '{"type":"hub_hello","public_key":"unknown_key"}');

        self::assertSame('Invalid peer key', $result);
    }

    public function testHandleTextFrameHubHelloRejectsNonPendingPeer(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn([
            'id' => 'peer-1',
            'name' => 'Test Peer',
            'url' => 'https://peer.example.com',
            'status' => 'connected', // not pending
        ]);

        $handler = $this->handler($hubRepo);
        $result = $handler->handleTextFrame('hub-1', '{"type":"hub_hello","public_key":"valid_key"}');

        self::assertSame('Peer not registered', $result);
    }

    public function testHandleTextFrameHubHelloAckIsNoOpOnMaster(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->expects(self::never())->method('getPeerByPublicKey');

        $handler = $this->handler($hubRepo);
        $result = $handler->handleTextFrame('hub-1', '{"type":"hub_hello_ack","session_id":"sess-1"}');

        // Master hub ignores HELLO_ACK from other hubs
        self::assertNull($result);
    }

    // ------------------------------------------------------ handleBinaryFrame

    public function testHandleBinaryFrameIgnoresUnknownFrameType(): void
    {
        $handler = $this->handler();
        // Invalid frame type 9999 should be ignored
        $handler->handleBinaryFrame('hub-1', 'payload', 9999);
        // No exception means success - we're just verifying it doesn't crash
        self::assertTrue(true);
    }

    public function testHandleBinaryFrameDisconnectedIgnoresWhenNoConnection(): void
    {
        $connMgr = new FederationConnectionManager();
        $connMgr->removeConnection('non-existent'); // just to ensure it's clean

        $handler = new FederationFrameHandler(
            $this->createMock(FederationHubRepository::class),
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $connMgr,
            $this->createMock(AuditLogger::class)
        );
        // Should not throw - no connection to close
        $handler->handleBinaryFrame('hub-1', '', 7); // DISCONNECTED frame type value
        self::assertTrue(true);
    }
}
