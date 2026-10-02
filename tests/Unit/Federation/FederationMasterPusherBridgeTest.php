<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationMasterPusher;
use Phlix\Hub\Federation\FederationPushBridge;
use Phlix\Hub\Federation\FederationPushProtocol;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\ConnectionInterface;

use function json_decode;
use function mkdir;
use function random_bytes;
use function bin2hex;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * The LOCAL-first triage inside {@see FederationMasterPusher} once a bridge is
 * wired: which transport each mutation surface takes, and what it does when no
 * transport exists.
 *
 * The pusher owns resolution + payload construction; the frame never leaves
 * this class through the bridge — only the INTENT (action, leaf-hub key, JSON
 * payload). These tests pin that split: the command published through the
 * capture-publisher carries the exact wire payload the local path would have
 * written, so both transports are provably the same protocol on either side of
 * the broker.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationMasterPusherBridgeTest extends TestCase
{
    private FederationConnectionManager $connMgr;

    private FederationHubRepository&\PHPUnit\Framework\MockObject\MockObject $hubRepo;

    private ?string $loggerTmp = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connMgr = new FederationConnectionManager();
        $this->hubRepo = $this->createMock(FederationHubRepository::class);

        $this->loggerTmp = sys_get_temp_dir() . '/phlix-hub-fed-push-bridge-' . bin2hex(random_bytes(6));
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
     * A bridge whose publisher records the command and answers the delivery
     * verdict the test asks for — the same synchronous wiring the round-trip
     * suite uses, without dragging the dispatcher in (its law is pinned there).
     *
     * @param list<array{event: string, data: array<string, mixed>}> $published
     */
    private function answeringBridge(array &$published, bool $verdict): FederationPushBridge
    {
        $published = [];

        $bridge = null;
        $bridge = new FederationPushBridge(
            new StructuredLogger('fed-pusher-bridge-test', []),
            static function (string $event, array $data) use (&$bridge, &$published, $verdict): void {
                $published[] = ['event' => $event, 'data' => $data];
                /** @var mixed $requestId */
                $requestId = $data['request_id'] ?? '';
                /** @var FederationPushBridge $bridge */
                $bridge->onReply([
                    'request_id' => is_string($requestId) ? $requestId : '',
                    'delivered' => $verdict,
                ]);
            },
        );

        return $bridge;
    }

    /**
     * @return array<string, mixed>
     */
    private function hubConfig(): array
    {
        return ['id' => 'master-hub-uuid'];
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

    private function pusherWith(?FederationPushBridge $bridge): FederationMasterPusher
    {
        return new FederationMasterPusher($this->hubRepo, $this->connMgr, $bridge);
    }

    // ------------------------------------------------------------------
    // Local-first law
    // ------------------------------------------------------------------

    /**
     * When THIS process holds the verified socket, the frame is written
     * locally and the bridge sees NOTHING. (Production HTTP workers never hit
     * this arm — their map is empty — but the :8805 process resolving the same
     * pusher, and every existing in-process test, depend on it staying first.)
     */
    public function testAVerifiedLocalConnectionIsUsedDirectlyAndTheBridgeIsIdle(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn($this->hubConfig());
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer('leaf-uuid'));

        $conn = $this->createMock(ConnectionInterface::class);
        $captured = '';
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $this->connMgr->addConnection('leaf-uuid', $conn);
        $this->connMgr->markVerified('leaf-uuid', $conn);

        $published = [];
        $pusher = $this->pusherWith($this->answeringBridge($published, true));

        self::assertTrue($pusher->pushOffer('peer-1', $this->shareRow()));
        self::assertStringContainsString('"id":"share-1"', $captured);
        self::assertSame(
            [],
            $published,
            'a locally-deliverable push crossed the broker anyway — the bridge must be the fallback, '
            . 'never the detour',
        );
    }

    // ------------------------------------------------------------------
    // Bridge path: the empty-map (HTTP worker) reality
    // ------------------------------------------------------------------

    /**
     * No local connection (the HTTP-worker reality): the OFFER intent crosses
     * the broker carrying exactly the payload the local path would have
     * encoded, and the reply verdict becomes the return value.
     */
    public function testPushOfferFallsThroughToTheBridgeCarryingTheWirePayload(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn($this->hubConfig());
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer('leaf-uuid'));

        $published = [];
        $pusher = $this->pusherWith($this->answeringBridge($published, true));

        self::assertTrue($pusher->pushOffer('peer-1', $this->shareRow()));

        self::assertCount(1, $published);
        self::assertSame(FederationPushProtocol::COMMAND_EVENT, $published[0]['event']);
        self::assertSame(FederationPushProtocol::ACTION_OFFER, $published[0]['data']['action']);
        self::assertSame('leaf-uuid', $published[0]['data']['leaf_hub_id']);

        $rawPayload = $published[0]['data']['payload'];
        self::assertIsString($rawPayload);
        /** @var array{shares: list<array<string, string>>} $payload */
        $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('share-1', $payload['shares'][0]['id']);
        // Wire identity: own hub uuid, never the local peer row FK.
        self::assertSame('master-hub-uuid', $payload['shares'][0]['peer_id']);
        self::assertSame('lib-9', $payload['shares'][0]['library_id']);
        self::assertSame('active', $payload['shares'][0]['status']);
    }

    public function testPushRevocationFallsThroughToTheBridgeAndReturnsTheReplyVerdict(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn($this->hubConfig());
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer('leaf-uuid'));

        // Dispatcher-measured refusal (unverified/absent leaf on the :8805 side)
        // must surface as false, not as a swallowed success.
        $published = [];
        $pusher = $this->pusherWith($this->answeringBridge($published, false));

        self::assertFalse($pusher->pushRevocation('peer-1', 'share-1'));
        self::assertSame(FederationPushProtocol::ACTION_REVOCATION, $published[0]['data']['action']);
        self::assertSame('{"share_id":"share-1"}', $published[0]['data']['payload']);
    }

    public function testClosePeerConnectionFallsThroughToTheBridge(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer('leaf-uuid'));

        $published = [];
        $pusher = $this->pusherWith($this->answeringBridge($published, true));

        self::assertTrue($pusher->closePeerConnection('peer-1'));
        self::assertSame(FederationPushProtocol::ACTION_CLOSE_PEER, $published[0]['data']['action']);
        self::assertSame('', $published[0]['data']['payload'], 'teardown carries no payload');
    }

    /**
     * close_peer over a live LOCAL connection still closes locally with the
     * bridge idle — the same local-first law as offers, on the third surface.
     */
    public function testClosePeerConnectionPrefersTheLocalSocket(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer('leaf-uuid'));

        $conn = $this->createMock(ConnectionInterface::class);
        $conn->expects(self::once())->method('close');
        $this->connMgr->addConnection('leaf-uuid', $conn);
        $this->connMgr->markVerified('leaf-uuid', $conn);

        $published = [];
        $pusher = $this->pusherWith($this->answeringBridge($published, true));

        self::assertTrue($pusher->closePeerConnection('peer-1'));
        self::assertSame([], $published);
        self::assertNull($this->connMgr->getConnection('leaf-uuid'));
    }

    // ------------------------------------------------------------------
    // No-bridge path: historical behaviour preserved exactly
    // ------------------------------------------------------------------

    /**
     * Without a bridge (2-arg construction — every existing call site and
     * test), the pre-bridge semantics stand verbatim: DATA pushes refuse false
     * with the "channel not verified" warning, close no-ops true.
     */
    public function testWithoutABridgeTheHistoricalSemanticsStand(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn($this->hubConfig());
        $this->hubRepo->method('getPeerById')->willReturn($this->boundPeer('leaf-uuid'));

        $pusher = $this->pusherWith(null);

        self::assertFalse($pusher->pushOffer('peer-1', $this->shareRow()));
        self::assertFalse($pusher->pushRevocation('peer-1', 'share-1'));
        self::assertTrue($pusher->closePeerConnection('peer-1'));
    }

    /**
     * Guards that precede transport must stay in front of the bridge too: an
     * unknown/unbound peer or a payload-less share never publishes anything.
     */
    public function testResolutionGuardsPrecedeTheBridge(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn($this->hubConfig());

        $unknown = $this->createMock(FederationHubRepository::class);
        $unknown->method('getHubConfig')->willReturn($this->hubConfig());
        $unknown->method('getPeerById')->willReturn(null);

        $published = [];
        $pusher = new FederationMasterPusher(
            $unknown,
            $this->connMgr,
            $this->answeringBridge($published, true),
        );

        self::assertFalse($pusher->pushOffer('ghost', $this->shareRow()));
        self::assertFalse($pusher->pushRevocation('ghost', 'share-1'));
        self::assertTrue($pusher->closePeerConnection('ghost'));
        self::assertSame([], $published, 'an unresolvable peer must not publish a command');

        // Unbound peer (empty leaf_hub_id) on the close path: resolve() yields
        // null → the historical "nothing to close" true, bridge idle.
        $unbound = $this->createMock(FederationHubRepository::class);
        $unbound->method('getHubConfig')->willReturn($this->hubConfig());
        $unbound->method('getPeerById')->willReturn($this->boundPeer(''));

        $published2 = [];
        $closePusher = new FederationMasterPusher(
            $unbound,
            $this->connMgr,
            $this->answeringBridge($published2, true),
        );
        self::assertTrue($closePusher->closePeerConnection('peer-1'));
        self::assertSame([], $published2);
    }
}
