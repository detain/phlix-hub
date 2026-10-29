<?php

/**
 * Phlix hub test: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Http\Controllers\FederationRelayController;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;

use function spl_object_id;
use function sys_get_temp_dir;

/**
 * M-HUB-20 (review hub__secnet M-1): the master-side :8805 classifier must
 * deliver JSON OBJECT frames (the entire H-4 handshake vocabulary —
 * hub_hello / hub_hello_ack / hub_hello_auth are objects) to
 * FederationFrameHandler::handleTextFrame(). The pre-fix implementation
 * decoded with `json_decode($data, false, 2)` and accepted
 * `is_array || is_scalar`: a stdClass is neither, so EVERY hub_hello fell to
 * the binary decoder, which read `{"ty` as a frame header, threw
 * InvalidFrameTypeException, and closed the link 'invalid_frame' — the
 * master-side twin of the leaf-side defect the real-TLS E2E caught at
 * 519c0c0. The security framing: the whole master trust model (signed ACK,
 * single-use nonce, carrier-bound HELLO_AUTH, pre-verification DATA drop)
 * is gated behind text-frame delivery and was never exercised through this
 * production boundary.
 *
 * These pins drive the REAL FederationRelayController over the REAL (final)
 * FederationFrameHandler with inert collaborators — the same composition
 * recipe FederationWorkerRateLimitTest established — so the observable is
 * which branch the controller takes and which refusal string the handler
 * (text branch) or the FrameDecoder (binary branch) produces.
 */
final class FederationRelayControllerClassifierTest extends TestCase
{
    use LoggerFactoryIsolation;

    private const HUB_ID = 'leaf-hub-classifier-uuid';

    private string $tmpDir = '';

    /** @var array<int, \ArrayObject<int, array<array-key, mixed>>> */
    private array $closeRecorders = [];

    protected function setUp(): void
    {
        // The controller keys connections by spl_object_id in process-wide
        // static maps; freed mock ids from earlier tests can alias stale
        // entries. Wipe both before every test (matters in full-suite order).
        (new ReflectionProperty(FederationRelayController::class, 'connHubIds'))->setValue(null, []);
        (new ReflectionProperty(FederationRelayController::class, 'connDecoders'))->setValue(null, []);

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-m20f-' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        $this->closeRecorders = [];
        if (is_file($this->tmpDir . '/logger.php')) {
            unlink($this->tmpDir . '/logger.php');
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    // ------------------------------------------- red-on-parent classification

    /**
     * T1 (flagship): a hub_hello JSON OBJECT reaches the TEXT branch. The
     * handler's own shape guard (missing public_key) refuses with
     * 'Invalid peer key' — proof the bytes entered handleTextFrame. On the
     * parent classifier the object misroutes to the binary decoder and the
     * observable close payload is 'invalid_frame' instead.
     */
    public function testJsonObjectHandshakeFrameReachesTheTextBranch(): void
    {
        [$controller, $connection] = $this->connectedController();

        $controller->onMessage($connection, '{"type":"hub_hello"}');

        self::assertSame(
            ['Invalid peer key'],
            $this->closePayloads($connection),
            'a JSON-object hub_hello must be delivered to handleTextFrame (handler refusal is the '
            . 'branch marker), never to the binary decoder (parent shape: close \'invalid_frame\')',
        );
    }

    /**
     * T2 (depth pin): a legitimately nested JSON object (three container
     * levels — inside the handler's own depth=4 parse law, but beyond the
     * parent classifier's depth=2 cap) classifies as TEXT and an unknown
     * text frame keeps the connection open (the handler's documented law).
     * Parent: depth cap → json_decode(null, no THROW) → not scalar → binary
     * → close 'invalid_frame'.
     */
    public function testNestedJsonObjectClassifiesAsTextAndStaysOpenOnUnknownType(): void
    {
        [$controller, $connection] = $this->connectedController();

        $controller->onMessage($connection, '{"type":"some_future_frame","meta":{"a":{"b":1}}}');

        self::assertSame(
            [],
            $this->closePayloads($connection),
            'deep JSON objects must classify as text; an unknown text type is ignored per the '
            . "handler's match-default law (parent shape: binary misroute + 'invalid_frame')",
        );
    }

    /**
     * T3 (scalar pin): a bare JSON scalar is NOT handshake text — it must
     * take the binary branch and die at the decoder ('invalid_frame'), the
     * honest refusal. The parent's `is_scalar` acceptance misrouted it into
     * the handshake path, where the handler answered with its own
     * 'Invalid frame payload' shape refusal.
     */
    public function testBareJsonScalarFallsToTheBinaryBranchNotTheHandshakePath(): void
    {
        [$controller, $connection] = $this->connectedController();

        $controller->onMessage($connection, '12345678');

        self::assertSame(
            ['invalid_frame'],
            $this->closePayloads($connection),
            'bare scalars are not handshake text (parent shape: text misroute closing '
            . "'Invalid frame payload')",
        );
    }

    // ------------------------------------------------------- law guards (both eras)

    /**
     * T4: JSON arrays keep classifying as text (guard against an
     * object-only over-correction). The handler's decoded array has no
     * string 'type' → 'Missing frame type' refusal — the same observable
     * FederationWorkerRateLimitTest pins at the worker layer.
     */
    public function testJsonArrayStillClassifiesAsText(): void
    {
        [$controller, $connection] = $this->connectedController();

        $controller->onMessage($connection, '[1,2]');

        self::assertSame(['Missing frame type'], $this->closePayloads($connection));
    }

    /**
     * T5: a genuine binary RelayFrame (HEARTBEAT, real FrameEncoder bytes,
     * 0x00-prefixed) keeps taking the binary branch and is consumed without
     * any close. The classifier fix must not touch binary traffic: the
     * heartbeat lands on the reaped-session inert path (mocked peer lookup
     * returns null), producing zero socket effect either era.
     */
    public function testEncodedBinaryHeartbeatKeepsTheBinaryBranchAndStaysOpen(): void
    {
        [$controller, $connection] = $this->connectedController();

        $bytes = (new FrameEncoder())->encode(RelayFrameType::HEARTBEAT, 1, '');
        $controller->onMessage($connection, $bytes);

        self::assertSame(
            [],
            $this->closePayloads($connection),
            'binary relay frames must never be re-routed to the handshake text path',
        );
    }

    /**
     * T6: an empty payload is not text (the 0-byte guard) and the decoder
     * holds it as an incomplete frame — no close, no crash.
     */
    public function testEmptyPayloadIsHeldByTheBinaryBranchWithoutClose(): void
    {
        [$controller, $connection] = $this->connectedController();

        $controller->onMessage($connection, '');

        self::assertSame([], $this->closePayloads($connection));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A REAL FederationRelayController over a REAL (final) FederationFrameHandler
     * with inert collaborators, plus a registered mock connection. The chosen
     * payloads all stop at a first guard (missing key / unknown type /
     * non-array / null peer row), so no mock needs behavior stubs — the
     * composition mirrors FederationWorkerRateLimitTest::controller() exactly.
     *
     * @return array{0: FederationRelayController, 1: TcpConnection&MockObject}
     */
    private function connectedController(): array
    {
        $connMgr = new FederationConnectionManager();
        $keyDir = $this->tmpDir . '/keys';
        mkdir($keyDir, 0700, true);

        $handler = new FederationFrameHandler(
            $this->createMock(FederationHubRepository::class),
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $connMgr,
            $this->createMock(AuditLogger::class),
            new Ed25519KeyManager($keyDir . '/master-ed25519.pem'),
        );

        $controller = new FederationRelayController($handler, $connMgr);
        $connection = $this->connection();
        $controller->onConnect($connection, self::HUB_ID);

        return [$controller, $connection];
    }

    /** Mock TCP connection with a close recorder keyed by object id. */
    private function connection(): TcpConnection&MockObject
    {
        $connection = $this->createMock(TcpConnection::class);

        /** @var \ArrayObject<int, array<array-key, mixed>> $recorder */
        $recorder = new \ArrayObject();
        $connection->method('close')->willReturnCallback(
            static function (mixed ...$args) use ($recorder): void {
                $recorder->append($args);
            }
        );
        $this->closeRecorders[spl_object_id($connection)] = $recorder;

        return $connection;
    }

    /** @return array<int, mixed> the first argument of every close(...) call, in order */
    private function closePayloads(TcpConnection $connection): array
    {
        $recorder = $this->closeRecorders[spl_object_id($connection)] ?? null;
        self::assertNotNull($recorder, 'close recorder must exist for a mocked connection');

        return array_map(
            static fn (array $call): mixed => $call[0],
            $recorder->getArrayCopy()
        );
    }
}
