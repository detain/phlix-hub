<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationMasterPusher;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\ConnectionInterface;

/**
 * Unit tests for {@see FederationMasterPusher}.
 *
 * FederationConnectionManager is final → real instance, matching the
 * FederationFrameHandlerTest pattern. Frames are asserted on their raw
 * bytes (the wire header precedes the JSON payload; str_contains over the
 * captured send is how the sibling suite pins push payloads too).
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationMasterPusherTest extends TestCase
{
    private FederationConnectionManager $connMgr;

    private FederationHubRepository&\PHPUnit\Framework\MockObject\MockObject $hubRepo;

    private FederationMasterPusher $pusher;

    private ?string $loggerTmp = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connMgr = new FederationConnectionManager();
        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->pusher = new FederationMasterPusher($this->hubRepo, $this->connMgr);

        // resolve()/ownHubId() log on skip paths; seed the in-memory channel.
        $this->loggerTmp = sys_get_temp_dir() . '/phlix-hub-fed-push-' . bin2hex(random_bytes(6));
        mkdir($this->loggerTmp, 0700, true);
        file_put_contents(
            $this->loggerTmp . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->loggerTmp . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        if ($this->loggerTmp !== null && is_file($this->loggerTmp . '/logger.php')) {
            unlink($this->loggerTmp . '/logger.php');
            rmdir($this->loggerTmp);
        }
        $this->loggerTmp = null;
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function boundPeer(string $leafHubId): array
    {
        return [
            'id' => 'peer-1',
            'name' => 'Leaf One',
            'status' => 'connected',
            'leaf_hub_id' => $leafHubId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shareRow(): array
    {
        return [
            'id' => 'share-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-9',
            'library_name' => 'Movies',
            'permission' => 'read',
            'status' => 'active',
        ];
    }

    private function liveConnection(ConnectionInterface $conn, string $leafHubId): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-uuid']);
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer($leafHubId));
        $this->connMgr->addConnection($leafHubId, $conn);
    }

    public function testPushOfferWritesDataFrameToTargetLeafConnection(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $captured = '';
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $this->liveConnection($conn, 'leaf-own-uuid');

        self::assertTrue($this->pusher->pushOffer('peer-1', $this->shareRow()));
        // Offer wire shape (DATA, seq 0): shares[] with THIS hub's uuid as
        // the origin identity, never the local peer-row FK.
        self::assertStringContainsString('"shares"', $captured);
        self::assertStringContainsString('"id":"share-1"', $captured);
        self::assertStringContainsString('"peer_id":"master-hub-uuid"', $captured);
        self::assertStringContainsString('"library_id":"lib-9"', $captured);
        self::assertStringContainsString('"permission":"read"', $captured);
        self::assertStringContainsString('"status":"active"', $captured);
        self::assertStringNotContainsString('peer-1"', $captured);
    }

    public function testPushOfferSkipsUnboundPeer(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-uuid']);
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer(''));

        $conn = $this->createMock(ConnectionInterface::class);
        $conn->expects(self::never())->method('send');

        self::assertFalse($this->pusher->pushOffer('peer-1', $this->shareRow()));
    }

    public function testPushOfferSkipsUnknownPeer(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-uuid']);
        $this->hubRepo->method('getPeerById')->willReturn(null);

        self::assertFalse($this->pusher->pushOffer('ghost', $this->shareRow()));
    }

    public function testPushOfferFailsLoudWithoutHubId(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(null);

        self::assertFalse($this->pusher->pushOffer('peer-1', $this->shareRow()));
    }

    public function testPushOfferRejectsShareRowWithoutId(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-uuid']);
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer('leaf-own-uuid'));

        self::assertFalse($this->pusher->pushOffer('peer-1', ['library_id' => 'lib-9']));
    }

    public function testPushOfferReturnsFalseWhenConnectionDropped(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-uuid']);
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer('leaf-own-uuid'));
        // Resolved + bound, but no live socket registered.

        self::assertFalse($this->pusher->pushOffer('peer-1', $this->shareRow()));
    }

    public function testPushRevocationWritesShareIdFrame(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $captured = '';
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $this->liveConnection($conn, 'leaf-own-uuid');

        self::assertTrue($this->pusher->pushRevocation('peer-1', 'share-1'));
        self::assertStringContainsString('"share_id":"share-1"', $captured);
    }

    public function testPushRevocationRejectsEmptyShareId(): void
    {
        $this->hubRepo->expects(self::never())->method('getPeerById');

        self::assertFalse($this->pusher->pushRevocation('peer-1', ''));
    }

    public function testClosePeerConnectionGoodbyesUnmapsAndCloses(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $captured = '';
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $conn->expects(self::once())->method('close');
        $this->liveConnection($conn, 'leaf-own-uuid');

        self::assertTrue($this->pusher->closePeerConnection('peer-1'));
        self::assertStringContainsString('peer_deleted', $captured);
        self::assertNull($this->connMgr->getConnection('leaf-own-uuid'), 'Connection must be unmapped');
    }

    public function testClosePeerConnectionIsNoOpForUnboundPeer(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer(''));

        self::assertTrue($this->pusher->closePeerConnection('peer-1'));
    }

    public function testClosePeerConnectionSurvivesDeadSocket(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('send')->willThrowException(new \RuntimeException('socket gone'));
        $conn->method('close')->willThrowException(new \RuntimeException('already closed'));
        $this->liveConnection($conn, 'leaf-own-uuid');

        self::assertTrue($this->pusher->closePeerConnection('peer-1'));
        self::assertNull($this->connMgr->getConnection('leaf-own-uuid'));
    }
}
