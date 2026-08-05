<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\RelayProxyManager;
use Phlix\Hub\Relay\TokenBucket;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Workerman\Connection\TcpConnection;

/**
 * Pin BOTH arms of every `try { Timer::add(...) } catch (Throwable) {}` in
 * `src/Relay/`, deterministically, in any suite order.
 *
 * ## The defect this closes
 *
 * `Workerman\Timer::add()` throws outside a Workerman runtime and every relay
 * call site swallows that by design. Which arm ran was decided by a
 * process-global, one-way latch — `Worker::$workers` — that the production
 * `start()` methods populate and nobody ever clears (see the long docblock on
 * {@see WorkermanTimerRuntimeControl}). With `executionOrder="random"` the
 * measured line coverage of `Tunnel.php`, `ClientRelayWorker.php` and
 * `RelayProxyManager.php` therefore moved from run to run, and `codecov/project`
 * swung on its own noise. S195 raised the codecov threshold to 0.5% to stop the
 * red; that is a mask. This is the fix.
 *
 * ## Why this makes the number deterministic
 *
 * Line coverage is a UNION over the whole run. Every test below forces the arm it
 * wants regardless of what ran before it, so both arms of every site are hit on
 * EVERY run. The union is saturated, and the incidental order-dependence of the
 * other relay tests can no longer move the figure.
 *
 * ## Sites covered
 *
 * | file | site | throw arm | success arm |
 * |---|---|---|---|
 * | `Tunnel.php` | `armThrottleDrain()` | `1171-1173` | `1165` + `cancelThrottleDrain()` `1191-1197` |
 * | `Tunnel.php` | `armClientBackpressureTimer()` | `1358-1360` | `1354` + `cancelClientBackpressureTimer()` `1376-80` |
 * | `Tunnel.php` | `armServerBackpressureTimer()` | `1547-1549` | `1543` + `cancelServerBackpressureTimer()` `1565-69` |
 * | `Tunnel.php` | `beginDrain()` | `1921-1922` | `1917` + `close()` `1811-1815` + `onServerClose()` `654-658` |
 * | `ClientRelayWorker.php` | `onWorkerStart()` | `277-280` | `244`, `267`, `268`, `276` |
 * | `RelayProxyManager.php` | `__construct()` | `186` | `183` |
 *
 * The `catch (Throwable)` bodies guarding `Timer::del()` (`Tunnel.php:1193`,
 * `1377`, `1566`, `1812`, `655`) are deliberately NOT claimed here: `Timer::del()`
 * cannot throw with an empty registry — it has no runtime guard at all and returns
 * `true` — so those arms are unreachable under PHPUnit. They contain no
 * statements, so they never appear in the coverage report either way.
 */
final class RelayTimerArmDeterminismTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    /** Temp dir holding this test's memory-stream logger config. */
    private string $tmpDir = '';

    /** Snapshot of {@see LoggerFactory}'s private static config path. */
    private string $loggerConfigPathSnapshot = '';

    /**
     * Snapshot of {@see LoggerFactory}'s private static logger cache.
     *
     * @var array<string, StructuredLogger>
     */
    private array $loggerCacheSnapshot = [];

    /**
     * `ClientRelayWorker`'s Timer-failure arm logs through the static
     * {@see LoggerFactory}, whose `$configPath` is ANOTHER process-global that
     * earlier tests set (to a temp dir they then delete) and never restore. Left
     * alone, `include ''` raises `ValueError: Path cannot be empty` — so whether
     * this test passed would itself depend on suite order. Point the factory at a
     * `php://memory` config of our own and put the previous values back after.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->loggerConfigPathSnapshot = self::loggerConfigPath();
        $this->loggerCacheSnapshot = self::loggerCache();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-relay-timer-determinism-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        $configPath = $this->tmpDir . '/logger.php';
        file_put_contents(
            $configPath,
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );

        LoggerFactory::reset();
        LoggerFactory::init($configPath);
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        self::setLoggerConfigPath($this->loggerConfigPathSnapshot);
        self::setLoggerCache($this->loggerCacheSnapshot);

        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    // ---- the premise ------------------------------------------------------

    /**
     * Assert the mechanism rather than assuming it.
     *
     * If Workerman ever stops keying `Timer::add()` off `Worker::getAllWorkers()`,
     * every "throw arm" test below would silently start pinning the success arm
     * (or vice versa) while staying green. This fails first and loudly instead.
     */
    public function testTimerAddThrowsOnAnEmptyWorkerRegistryAndSucceedsOnASeededOne(): void
    {
        $this->forceNoWorkermanRuntime();
        self::assertSame(
            'throw:Timer can only be used in workerman running environment',
            $this->timerAddOutcome(),
            'an EMPTY Worker::$workers must route Timer::add() to its throwing arm',
        );

        $this->forceWorkermanRuntime();
        self::assertSame(
            'success',
            $this->timerAddOutcome(),
            'a SEEDED Worker::$workers must route Timer::add() to its task-table arm',
        );
    }

    // ---- Tunnel::armThrottleDrain / cancelThrottleDrain -------------------

    public function testArmThrottleDrainSwallowsTheTimerFailureWithNoWorkermanRuntime(): void
    {
        $this->forceNoWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $client = $this->throttledClient();

        $this->invokePrivate($tunnel, 'armThrottleDrain', $client);

        self::assertNull(
            $client->throttleDrainTimerId,
            'the catch arm must leave the drain timer id null',
        );
        self::assertSame(0, $this->pendingTimerTaskCount(), 'no timer may have been registered');
    }

    public function testArmThrottleDrainStoresARealTimerIdWithAWorkermanRuntime(): void
    {
        $this->forceWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $client = $this->throttledClient();

        $this->invokePrivate($tunnel, 'armThrottleDrain', $client);

        self::assertIsInt(
            $client->throttleDrainTimerId,
            'the success arm must store the id Timer::add() returned',
        );
        self::assertGreaterThan(0, $client->throttleDrainTimerId);
        self::assertSame(1, $this->pendingTimerTaskCount(), 'exactly one drain timer must be registered');

        // The cancel path is only reachable once an id is actually held.
        $this->invokePrivate($tunnel, 'cancelThrottleDrain', $client);
        self::assertNull($client->throttleDrainTimerId, 'cancelling must clear the stored id');
        self::assertSame(0, $this->pendingTimerTaskCount(), 'cancelling must remove the task');
    }

    // ---- Tunnel client-backpressure timer ---------------------------------

    public function testArmClientBackpressureTimerSwallowsTheTimerFailureWithNoWorkermanRuntime(): void
    {
        $this->forceNoWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $this->invokePrivate($tunnel, 'armClientBackpressureTimer');

        self::assertNull($this->readPrivate($tunnel, 'clientBackpressureTimerId'));
        self::assertSame(0, $this->pendingTimerTaskCount());
    }

    public function testArmClientBackpressureTimerStoresARealTimerIdAndIsCancellable(): void
    {
        $this->forceWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $this->invokePrivate($tunnel, 'armClientBackpressureTimer');

        $timerId = $this->readPrivate($tunnel, 'clientBackpressureTimerId');
        self::assertIsInt($timerId, 'the success arm must store the client backpressure timer id');
        self::assertSame(1, $this->pendingTimerTaskCount());

        $this->invokePrivate($tunnel, 'cancelClientBackpressureTimer');
        self::assertNull($this->readPrivate($tunnel, 'clientBackpressureTimerId'));
        self::assertSame(0, $this->pendingTimerTaskCount());
    }

    // ---- Tunnel server-backpressure timer ---------------------------------

    public function testArmServerBackpressureTimerSwallowsTheTimerFailureWithNoWorkermanRuntime(): void
    {
        $this->forceNoWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $this->invokePrivate($tunnel, 'armServerBackpressureTimer');

        self::assertNull($this->readPrivate($tunnel, 'serverBackpressureTimerId'));
        self::assertSame(0, $this->pendingTimerTaskCount());
    }

    public function testArmServerBackpressureTimerStoresARealTimerIdAndIsCancellable(): void
    {
        $this->forceWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $this->invokePrivate($tunnel, 'armServerBackpressureTimer');

        $timerId = $this->readPrivate($tunnel, 'serverBackpressureTimerId');
        self::assertIsInt($timerId, 'the success arm must store the server backpressure timer id');
        self::assertSame(1, $this->pendingTimerTaskCount());

        $this->invokePrivate($tunnel, 'cancelServerBackpressureTimer');
        self::assertNull($this->readPrivate($tunnel, 'serverBackpressureTimerId'));
        self::assertSame(0, $this->pendingTimerTaskCount());
    }

    // ---- Tunnel::beginDrain grace timer -----------------------------------

    public function testBeginDrainSwallowsTheGraceTimerFailureWithNoWorkermanRuntime(): void
    {
        $this->forceNoWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $tunnel->beginDrain(30.0, 'server_replaced');

        self::assertSame(Tunnel::STATUS_CLOSING, $tunnel->status, 'the drain must still be entered');
        self::assertNull($this->readPrivate($tunnel, 'drainTimerId'), 'the catch arm must null the id');
        self::assertSame(0, $this->pendingTimerTaskCount());
    }

    public function testBeginDrainArmsTheGraceTimerAndCloseCancelsIt(): void
    {
        $this->forceWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $tunnel->beginDrain(30.0, 'server_replaced');

        self::assertIsInt($this->readPrivate($tunnel, 'drainTimerId'), 'the grace timer id must be stored');
        self::assertSame(1, $this->pendingTimerTaskCount());

        // close() only reaches its Timer::del block when an id is actually held.
        $tunnel->close('displaced', false);

        self::assertNull($this->readPrivate($tunnel, 'drainTimerId'), 'close() must clear the grace timer id');
        self::assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
        self::assertSame(0, $this->pendingTimerTaskCount());
    }

    public function testOnServerCloseCancelsAnArmedGraceTimer(): void
    {
        $this->forceWorkermanRuntime();

        $tunnel = $this->throttleTunnel();
        $tunnel->beginDrain(30.0, 'server_replaced');
        self::assertIsInt($this->readPrivate($tunnel, 'drainTimerId'));

        $tunnel->onServerClose();

        self::assertNull($this->readPrivate($tunnel, 'drainTimerId'), 'onServerClose() must clear the grace timer id');
        self::assertSame(0, $this->pendingTimerTaskCount());
    }

    // ---- ClientRelayWorker::onWorkerStart metrics timers ------------------

    public function testClientRelayWorkerArmsBothMetricsTimersWithAWorkermanRuntime(): void
    {
        $this->forceWorkermanRuntime();

        $worker = new ClientRelayWorker($this->metricsContainer(), 0);
        $worker->onWorkerStart();

        self::assertSame(
            2,
            $this->pendingTimerTaskCount(),
            'onWorkerStart() must arm BOTH the flush timer and the live-connection touch timer',
        );
    }

    public function testClientRelayWorkerMetricsTimerInitIsGuardedWithNoWorkermanRuntime(): void
    {
        $this->forceNoWorkermanRuntime();

        $worker = new ClientRelayWorker($this->metricsContainer(), 0);

        // The whole metrics block is wrapped: a Timer failure must be logged and
        // swallowed, never escape and break the client relay worker's boot.
        $worker->onWorkerStart();

        self::assertSame(0, $this->pendingTimerTaskCount(), 'no metrics timer may have been registered');
    }

    // ---- RelayProxyManager sweep timer ------------------------------------

    public function testRelayProxyManagerConstructorSwallowsTheSweepTimerFailure(): void
    {
        $this->forceNoWorkermanRuntime();

        // Must not throw out of the constructor.
        $manager = $this->newProxyManager();

        self::assertInstanceOf(RelayProxyManager::class, $manager);
        self::assertSame(0, $this->pendingTimerTaskCount(), 'no sweep timer may have been registered');
    }

    public function testRelayProxyManagerConstructorArmsTheSweepTimer(): void
    {
        $this->forceWorkermanRuntime();

        $manager = $this->newProxyManager();

        self::assertInstanceOf(RelayProxyManager::class, $manager);
        self::assertSame(1, $this->pendingTimerTaskCount(), 'the sweep timer must be registered');
    }

    // ---- fixtures ---------------------------------------------------------

    /** An ACTIVE tunnel with a stubbed server socket, mirroring TunnelTest. */
    private function throttleTunnel(): Tunnel
    {
        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->method('registerServer')->willReturn('session-timer-determinism');

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $tunnel = new Tunnel(
            'server-timer-determinism',
            $serverWs,
            $sessionManager,
            new FrameDecoder(),
            $this->createMock(StructuredLogger::class),
        );
        $tunnel->relaySessionId = 'session-timer-determinism';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        return $tunnel;
    }

    /** A throttled client whose bucket is empty, so a drain timer is warranted. */
    private function throttledClient(): ClientConnection
    {
        $client = new ClientConnection(
            $this->createMock(TcpConnection::class),
            'server-timer-determinism',
            'client-timer-determinism',
            $this->createMock(StructuredLogger::class),
            '',
            8_000_000,
        );

        $bucket = new TokenBucket(100.0, 100.0, 1000.0);
        $bucket->spend(100.0);
        $client->throttleBucket = $bucket;

        return $client;
    }

    /** A real {@see RelayProxyManager} — it is final and cannot be doubled. */
    private function newProxyManager(): RelayProxyManager
    {
        return new RelayProxyManager(
            $this->createMock(TunnelManagerInterface::class),
            $this->createMock(StructuredLogger::class),
            30,
            static function (string $event, array $data): void {
            },
        );
    }

    /**
     * A PSR-11 container resolving ONLY the two metrics services
     * {@see ClientRelayWorker::onWorkerStart()} needs, with metrics enabled so it
     * reaches the timer-arming block. Everything else throws, which the worker's
     * own guards handle (the client-mount limiter lookup is `try`-wrapped).
     */
    private function metricsContainer(): ContainerInterface
    {
        $collector = new MetricsCollector(new MetricsRegistry(), true);
        $flush = new MetricsFlushService($collector, ['flush_interval_seconds' => 5]);

        return new class ($collector, $flush) implements ContainerInterface {
            public function __construct(
                private readonly MetricsCollector $collector,
                private readonly MetricsFlushService $flush,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    MetricsCollector::class => $this->collector,
                    MetricsFlushService::class => $this->flush,
                    default => throw new RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return $id === MetricsCollector::class
                    || $id === MetricsFlushService::class
                    || $id === RateLimitProfiles::CLIENT_MOUNT;
            }
        };
    }

    private static function loggerConfigPath(): string
    {
        /** @var string $value */
        $value = (new ReflectionProperty(LoggerFactory::class, 'configPath'))->getValue();

        return $value;
    }

    private static function setLoggerConfigPath(string $path): void
    {
        (new ReflectionProperty(LoggerFactory::class, 'configPath'))->setValue(null, $path);
    }

    /**
     * @return array<string, StructuredLogger>
     */
    private static function loggerCache(): array
    {
        /** @var array<string, StructuredLogger> $value */
        $value = (new ReflectionProperty(LoggerFactory::class, 'loggers'))->getValue();

        return $value;
    }

    /**
     * @param array<string, StructuredLogger> $loggers
     */
    private static function setLoggerCache(array $loggers): void
    {
        (new ReflectionProperty(LoggerFactory::class, 'loggers'))->setValue(null, $loggers);
    }

    /** Invoke a private/protected method on $object. */
    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
    }

    /** Read a private/protected property from $object. */
    private function readPrivate(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
