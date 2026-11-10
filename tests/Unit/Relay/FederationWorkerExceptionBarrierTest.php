<?php

/**
 * Phlix hub component: Relay tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationPushDispatcher;
use Phlix\Hub\Federation\FederationPushProtocol;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Http\Controllers\FederationRelayController;
use Phlix\Hub\Relay\FederationWorker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use TypeError;
use stdClass;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * S312/M-HUB-03 exception barrier on the :8805 federation worker (review
 * hub__asphp Major M-A). The worker runs count=1: a Throwable escaping any
 * Workerman callback takes Worker::stopAll(250) through the process and the
 * crash-loop silently drops every live leaf link. Pre-cure, the three
 * dispatch sites (WS message, WS close, Channel-bridge command) called
 * DB-touching code unwrapped — a throwing `federation_peers` read in
 * `handleHubHello`/`handleConnectionClosed` or a leaf verb in the push
 * dispatcher escaped straight into the event loop.
 *
 * Pins per site: contained (no throw out of the worker callback), exactly
 * ONE relay error line, the poisoned path is refused (message dispatch) or
 * its invariants still hold (close dispatch clears the controller statics),
 * and the worker keeps serving afterwards. The bridge barrier publishes NO
 * reply on a throw — the malformed-command doctrine: the bridge degrades to
 * a measured false on its own timeout rather than being handed a verdict
 * nobody executed.
 */
final class FederationWorkerExceptionBarrierTest extends TestCase
{
    /** Hub id used by the canonical upgrade path (mirrors the rate-limit harness). */
    private const HUB_ID = 'hub-alpha-uuid';

    private string $tmpDir = '';

    /** @var array<int, \ArrayObject<int, array<array-key, mixed>>> connection id → close calls */
    private array $closeRecorders = [];

    protected function setUp(): void
    {
        FederationWorker::reset();
        // Static connection maps are process-wide; wipe them so recycled
        // spl_object_ids of freed mocks cannot alias stale entries (house
        // idiom from FederationWorkerRateLimitTest — matters suite-wide).
        (new ReflectionProperty(FederationRelayController::class, 'connHubIds'))->setValue(null, []);
        (new ReflectionProperty(FederationRelayController::class, 'connDecoders'))->setValue(null, []);

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-fed-barrier-' . uniqid('', true);
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

    // ------------------------------------------------- site 1: WS message

    public function testThrowingTextDispatchIsContainedAndWorkerKeepsServing(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            static fn (string $id): array => ['id' => $id]
        );
        // The review's exact vector: a live DB read inside handleHubHello.
        $hubRepo->method('getPeerByPublicKey')
            ->willThrowException(new RuntimeException('barrier-probe-down'));

        $worker = $this->worker($this->controller($hubRepo), $hubRepo);
        $connection = $this->connection('198.51.100.7');
        $worker->onWebSocketConnect($connection, $this->upgradeRequest());
        $connection->context = $this->established();

        $logger = $this->injectRelayLogger();
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('kept alive'),
                self::callback(
                    static fn (array $ctx): bool => ($ctx['hub_id'] ?? null) === self::HUB_ID
                        && ($ctx['direction'] ?? null) === 'message'
                        && ($ctx['error'] ?? null) === 'barrier-probe-down'
                        && ($ctx['class'] ?? null) === RuntimeException::class
                ),
            );

        // Pre-cure the RuntimeException escaped onMessage() and stopped the
        // worker; post-cure the call simply returns.
        $worker->onMessage($connection, '{"type":"hub_hello","public_key":"pk"}');

        self::assertSame(
            ['handler_error'],
            $this->closePayloads($connection),
            'the poisoned socket must be refused once, with the named payload',
        );

        // Liveness: the SAME callback path still dispatches for the next
        // frame — the process is alive and the controller still answers.
        $worker->onMessage($connection, '[1,2]');
        self::assertSame(
            ['handler_error', 'Missing frame type'],
            $this->closePayloads($connection),
            'dispatch after a contained throw must keep reaching the controller',
        );
    }

    // --------------------------------------------------- site 2: WS close

    /**
     * Review Info twin: the barrier answers EVERY Throwable shape — a
     * TypeError (loop-killing Error, not Exception) is contained with its
     * CLASS logged — and liveness extends to a SECOND, unrelated peer: the
     * count=1 worker keeps serving sockets that never poisoned it.
     */
    public function testErrorVectorIsContainedAndASecondPeerKeepsServing(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            static fn (string $id): array => ['id' => $id]
        );
        $hubRepo->method('getPeerByPublicKey')
            ->willThrowException(new TypeError('barrier-error-vector'));

        $worker = $this->worker($this->controller($hubRepo), $hubRepo);
        $poisoned = $this->connection('198.51.100.8');
        $worker->onWebSocketConnect($poisoned, $this->upgradeRequest());
        $poisoned->context = $this->established();

        $logger = $this->injectRelayLogger();
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('kept alive'),
                self::callback(
                    static fn (array $ctx): bool => ($ctx['class'] ?? null) === TypeError::class
                        && ($ctx['error'] ?? null) === 'barrier-error-vector'
                ),
            );

        $worker->onMessage($poisoned, '{"type":"hub_hello","public_key":"pk"}');

        // Second peer on the same live worker: full round trip unaffected.
        $bystander = $this->connection('198.51.100.9');
        $worker->onWebSocketConnect($bystander, $this->upgradeRequest());
        $bystander->context = $this->established();
        $worker->onMessage($bystander, '[1,2]');

        self::assertSame(
            ['Missing frame type'],
            $this->closePayloads($bystander),
            'the untouched peer still reaches the controller — process alive, loop intact',
        );
    }

    public function testThrowingCloseDispatchIsContainedAndStaticsAreCleared(): void
    {
        $calls = 0;
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            static function (string $id) use (&$calls): array {
                $calls++;
                if ($calls > 1) {
                    // Call 1 is the connect validation; call 2+ is
                    // handleConnectionClosed's own DB read — that one throws.
                    throw new RuntimeException('close-probe-down');
                }

                return ['id' => $id];
            }
        );

        $worker = $this->worker($this->controller($hubRepo), $hubRepo);
        $connection = $this->connection('198.51.100.8');
        $worker->onWebSocketConnect($connection, $this->upgradeRequest());
        self::assertSame(1, $calls, 'premise: connect validation consumed call 1');

        $connId = spl_object_id($connection);
        /** @var array<int, string> $hubIds */
        $hubIds = (new ReflectionProperty(FederationRelayController::class, 'connHubIds'))->getValue(null) ?? [];
        /** @var array<int, mixed> $decoders */
        $decoders = (new ReflectionProperty(FederationRelayController::class, 'connDecoders'))->getValue(null) ?? [];
        self::assertArrayHasKey($connId, $hubIds, 'premise: connect registered the controller maps');
        self::assertArrayHasKey($connId, $decoders);

        $logger = $this->injectRelayLogger();
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('kept alive'),
                self::callback(
                    static fn (array $ctx): bool => ($ctx['hub_id'] ?? null) === self::HUB_ID
                        && ($ctx['direction'] ?? null) === 'close'
                        && ($ctx['error'] ?? null) === 'close-probe-down'
                        && ($ctx['class'] ?? null) === RuntimeException::class
                ),
            );

        $worker->onClose($connection);

        // Pre-cure this threw out of onClose AND leaked the static entry
        // (a recycled spl_object_id would alias a dead hub's id). Post-cure:
        // contained, and the try/finally invariant holds even through the throw.
        /** @var array<int, string> $hubIdsAfter */
        $hubIdsAfter = (new ReflectionProperty(
            FederationRelayController::class,
            'connHubIds',
        ))->getValue(null) ?? [];
        /** @var array<int, mixed> $decodersAfter */
        $decodersAfter = (new ReflectionProperty(
            FederationRelayController::class,
            'connDecoders',
        ))->getValue(null) ?? [];
        self::assertArrayNotHasKey($connId, $hubIdsAfter);
        self::assertArrayNotHasKey($connId, $decodersAfter);
    }

    // ------------------------------------------- site 3: Channel bridge

    public function testThrowingChannelCommandIsContainedAndPublishesNoVerdict(): void
    {
        /** @var list<array{0: string, 1: array<string, mixed>}> $replies */
        $replies = [];

        $peerManager = $this->createMock(FederationPeerManager::class);
        $peerManager->method('connectToMaster')
            ->willThrowException(new RuntimeException('leaf-verb-down'));

        $dispatcher = new FederationPushDispatcher(
            $this->createMock(StructuredLogger::class),
            new FederationConnectionManager(),
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
            $peerManager,
        );

        $emptyRepo = $this->createMock(FederationHubRepository::class);
        $worker = $this->worker($this->controller($emptyRepo), $emptyRepo);

        $logger = $this->injectRelayLogger();
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('kept alive'),
                self::callback(
                    static fn (array $ctx): bool => ($ctx['direction'] ?? null) === 'push_command'
                        && ($ctx['error'] ?? null) === 'leaf-verb-down'
                        && ($ctx['class'] ?? null) === RuntimeException::class
                ),
            );

        // Well-formed leaf_connect envelope (lifecycle verb: empty
        // leaf_hub_id, empty payload) — validation passes, the OWNER-SEAT
        // call itself throws. Pre-cure this was an undefined-method red (the
        // barrier did not exist); the throw escaped the broker callback.
        $worker->onPushCommand($dispatcher, [
            'request_id'  => 'req-1',
            'reply_event' => 'reply.event.test',
            'action'      => FederationPushProtocol::ACTION_LEAF_CONNECT,
            'leaf_hub_id' => '',
            'payload'     => '',
        ]);

        // No fabricated delivery verdict — the no-reply doctrine of the
        // malformed path applies to the contained throw too.
        self::assertSame([], $replies);
    }

    // --------------------------------------- subscription-site law (t4)

    public function testChannelSubscriptionRoutesThroughTheNamedBarrier(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Relay/FederationWorker.php',
        );

        // The broker must subscribe the BARRIER, never the raw executor —
        // a re-swap to `$dispatcher->onCommand(...)` would silently restore
        // the crash vector (the only guard is this pin).
        self::assertStringNotContainsString(
            '$dispatcher->onCommand(...)',
            $src,
            'the Channel subscription may not pass the raw onCommand callable',
        );
        self::assertStringContainsString(
            '$this->onPushCommand($dispatcher, $data)',
            $src,
            'the Channel subscription must route through the onPushCommand barrier',
        );
    }

    // ---------------------------------------------------------------- helpers

    /** Container-backed worker for the given controller + hub repository. */
    private function worker(
        FederationRelayController $controller,
        FederationHubRepository $hubRepo,
    ): FederationWorker {
        $entries = [
            FederationRelayController::class      => $controller,
            // The connect-time validation reads federation_peers through the
            // container-resolved repository (same entry the rate-limit
            // harness wires — the controller's handler copy is separate).
            FederationHubRepository::class        => $hubRepo,
            RateLimitProfiles::FEDERATION_CONNECT => new RateLimiter(60, PHP_INT_MAX, 10000),
            // Generous frame budget: the only refusal noise in these tests is
            // the barrier's, never a limiter's.
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

    /**
     * REAL controller over a REAL frame handler (both final — composition,
     * not mocks) with the hub repository under test injected; every other
     * collaborator is inert. Venue mirrors FederationWorkerRateLimitTest.
     */
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

    /** Mock TCP connection with a fixed raw peer address + close recorder. */
    private function connection(string $remoteIp): TcpConnection&MockObject
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('getRemoteIp')->willReturn($remoteIp);

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

    /** @return list<mixed> close() first arguments, in call order */
    private function closePayloads(TcpConnection $connection): array
    {
        $recorder = $this->closeRecorders[spl_object_id($connection)] ?? null;
        self::assertNotNull($recorder, 'close recorder must exist for a mocked connection');

        /** @var list<array<array-key, mixed>> $calls */
        $calls = $recorder->getArrayCopy();

        return array_values(array_map(static fn (array $call): mixed => $call[0], $calls));
    }

    /** Mark a mocked connection as post-upgrade. */
    private function established(): stdClass
    {
        $context = new stdClass();
        $context->websocketHandshake = true;

        return $context;
    }

    /** Real Workerman WS-upgrade request for the canonical federation path. */
    private function upgradeRequest(): WorkermanRequest
    {
        $lines = ['GET /relay/federation/' . self::HUB_ID . ' HTTP/1.1'];
        foreach (
            [
            'Host' => 'master.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            ] as $name => $value
        ) {
            $lines[] = "$name: $value";
        }

        return new WorkermanRequest(implode("\r\n", $lines) . "\r\n\r\n");
    }

    /**
     * Replace the memoised RELAY channel logger with a strict mock, pinning
     * "exactly ONE error line with this context".
     */
    private function injectRelayLogger(): StructuredLogger&MockObject
    {
        $logger = $this->createMock(StructuredLogger::class);
        LoggerFactory::reset();

        $property = new ReflectionProperty(LoggerFactory::class, 'loggers');
        $property->setValue(null, [LogChannels::RELAY => $logger]);

        return $logger;
    }
}
