<?php

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
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use TypeError;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * S312/M-HUB-03 barrier on the LEAF legs of the federation link (review
 * hub__asphp, R1 Major — the fourth arm of the M-A finding).
 *
 * The :8805 federation worker is count=1 and owns EVERY federation socket.
 * Commit 69560af7 armed the master-side surfaces (WS dispatch, close,
 * COMMAND_EVENT); the leaf link's own callbacks inside
 * {@see FederationPeerManager::establishConnection()} were still bare at
 * that tip: a throwing DB read under `hub_hello_ack` fan-out, a hello-send
 * failure, a heartbeat tick or an ack-timeout trip that exploded would run
 * Worker::stopAll(250) through the resident loop — the exact crash-loop the
 * repo doctrine forbids.
 *
 * The cure mirrors the worker idiom: every loop-adjacent callback either
 * routes through the guarded inbound seam or carries a Throwable catch, and
 * containment is LINK-scoped — one attributed error line (message AND
 * exception class) plus {@see FederationPeerManager::closeLinkAndReschedule
 * ()} (identity-safe teardown, capped ladder). The worker survives; only the
 * poisoned link recycles.
 *
 * The close/error arms' own catches are deliberately a BACKSTOP: their callee
 * (scheduleReconnect) is self-contained under the current wiring, so their
 * teeth are the source-shape pin below, honestly disclosed.
 */
final class FederationLeafLinkBarrierTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private string $dir = '';

    private string $logFile = '';

    private FederationHubRepository&MockObject $hubRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/phlix-hub-leafbarrier-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);

        // Containment lines must be READABLE (php://memory cannot be
        // re-read) — file sink, same shape as FederationPeerManagerTest.
        $this->logFile = (string) tempnam($this->dir, 'log-');
        file_put_contents(
            $this->dir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => '" . $this->logFile . "', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->dir . '/logger.php');

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        $this->rrmdir($this->dir);

        parent::tearDown();
    }

    // ------------------------------------------------------ inbound seam

    /**
     * The load-bearing behavior pin: `hub_hello_ack` whose hub-config read
     * throws must NOT escape the seam — containment closes the link ONCE,
     * drops the verified latch, nulls the socket and hands recovery to the
     * capped ladder (5.0s head delay), with ONE attributed error line.
     * Pre-cure this ERRORS with the raw RuntimeException.
     */
    public function testLeafInboundSeamContainsThrowAndTeardownToLadder(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo
            ->method('getHubConfig')
            ->willThrowException(new RuntimeException('hub config store down mid-frame'));

        $manager = $this->peerManager();
        $manager->claimLeafLinkOwnership();
        $closed = 0;
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->method('close')->willReturnCallback(
            /** @param mixed ...$args */
            static function (...$args) use (&$closed): void {
                ++$closed;
            },
        );
        $this->writeProp($manager, 'masterConnection', $conn);
        $this->writeProp($manager, 'channelVerified', true);
        $this->writeProp($manager, 'masterPeerId', 'peer-1');

        $manager->handleInboundLeafFrame('{"type":"hub_hello_ack"}');

        self::assertSame(1, $closed, 'the throwing link was closed once');
        self::assertNull($this->readProp($manager, 'masterConnection'));
        self::assertFalse((bool) $this->readProp($manager, 'channelVerified'));
        self::assertSame(
            5.0,
            $this->soleArmedTimer()['interval'],
            'containment handed the recovery to the capped ladder, at the head delay',
        );
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('inbound frame handler threw', $log);
        self::assertStringContainsString('containing leaf link', $log);
        self::assertStringContainsString('hub config store down mid-frame', $log);
    }

    /**
     * The barrier answers EVERY Throwable shape (the loop killer is an
     * uncaught Error just as much as an Exception) and the containment line
     * names the CLASS — review Info: message-only logging hid which store
     * blew up.
     */
    public function testSeamContainsErrorVectorAndLogsExceptionClass(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo
            ->method('getHubConfig')
            ->willThrowException(new TypeError('decoder fed a non-string row'));

        $manager = $this->peerManager();
        $manager->claimLeafLinkOwnership();
        $conn = $this->createMock(AsyncTcpConnection::class);
        $this->writeProp($manager, 'masterConnection', $conn);
        $this->writeProp($manager, 'masterPeerId', 'peer-1');

        $manager->handleInboundLeafFrame('{"type":"hub_hello_ack"}');

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('TypeError', $log, 'the class rides the containment line');
        self::assertStringContainsString('decoder fed a non-string row', $log);
        self::assertNull($this->readProp($manager, 'masterConnection'), 'the link was recycled');
    }

    /**
     * Liveness under the seam: containment of frame N must not deafen the
     * dispatch path — frame N+1 (config back) processes to its normal early
     * return with NO second containment line.
     */
    public function testSeamStaysServedAfterOneContainment(): void
    {
        $this->forceWorkermanRuntime();
        $calls = 0;
        $this->hubRepo
            ->method('getHubConfig')
            ->willReturnCallback(static function () use (&$calls) {
                ++$calls;
                if ($calls === 1) {
                    throw new RuntimeException('transient store death');
                }

                return null; // config missing: ack returns quietly, no throw
            });

        $manager = $this->peerManager();
        $manager->claimLeafLinkOwnership();
        $conn = $this->createMock(AsyncTcpConnection::class);
        $this->writeProp($manager, 'masterConnection', $conn);
        $this->writeProp($manager, 'masterPeerId', 'peer-1');

        $manager->handleInboundLeafFrame('{"type":"hub_hello_ack"}');
        $manager->handleInboundLeafFrame('{"type":"hub_hello_ack"}');

        self::assertSame(2, $calls, 'the SECOND frame reached the handler — the seam survived');
        $log = (string) file_get_contents($this->logFile);
        self::assertSame(
            1,
            substr_count($log, 'containing leaf link'),
            'exactly one containment line — the healthy second frame logged nothing',
        );
    }

    // ------------------------------------------------------- source pins

    /**
     * Every event-loop callback the leaf link installs carries a Throwable
     * barrier INSIDE its block (hello send, close and error arms contain into
     * the link teardown or a survivor log); the inbound leg routes through
     * the guarded seam. The close/error catches are a backstop under the
     * current self-contained scheduleReconnect() — this pin is their tooth
     * against a future throwing callee, and it is why no behavioral throw
     * vector exists for them.
     */
    public function testEstablishConnectionClosuresCarryBarriers(): void
    {
        $body = self::methodText(new ReflectionMethod(FederationPeerManager::class, 'establishConnection'));

        $blocks = [
            'onConnect' => ['->onConnect = ', '->onMessage = '],
            'onMessage' => ['->onMessage = ', '// On close'],
            'onClose' => ['->onClose = ', '->onError = '],
            'onError' => ['->onError = ', '->connect();'],
        ];
        foreach ($blocks as $name => [$from, $to]) {
            $start = strpos($body, $from);
            self::assertIsInt($start, "the {$name} closure block must exist");
            $end = strpos($body, $to, $start);
            self::assertIsInt($end, "the {$name} closure block must be delimited");
            $block = substr($body, $start, $end - $start);

            if ($name === 'onMessage') {
                self::assertStringContainsString(
                    'handleInboundLeafFrame(',
                    $block,
                    'the inbound leg must route through the guarded seam',
                );
                $seam = self::methodText(
                    new ReflectionMethod(FederationPeerManager::class, 'handleInboundLeafFrame'),
                );
                self::assertStringContainsString('catch (Throwable', $seam, 'the seam must carry the barrier');
                self::assertStringContainsString('containLeafCallback(', $seam);

                continue;
            }

            self::assertStringContainsString('catch (Throwable', $block, "the {$name} callback must be contained");
        }

        // The ack-timeout tick lives in a Timer::add block after the marker.
        $ackAt = strpos($body, 'HELLO_ACK_TIMEOUT_SECONDS,');
        self::assertIsInt($ackAt);
        self::assertStringContainsString('catch (Throwable', substr($body, (int) $ackAt));

        $heartbeat = self::methodText(new ReflectionMethod(FederationPeerManager::class, 'startHeartbeatTimer'));
        self::assertStringContainsString('catch (Throwable', $heartbeat, 'the heartbeat tick must be contained');
    }

    /**
     * The worker-side dispatch barrier lines attribute by CLASS too (review
     * Info, twin of the seam pin above, across the 69560af7 surface).
     */
    public function testContainmentLinesCarryTheExceptionClass(): void
    {
        $worker = self::methodText(new ReflectionMethod(\Phlix\Hub\Relay\FederationWorker::class, 'onMessage'));
        $worker .= self::methodText(new ReflectionMethod(\Phlix\Hub\Relay\FederationWorker::class, 'onClose'));
        $worker .= self::methodText(new ReflectionMethod(\Phlix\Hub\Relay\FederationWorker::class, 'onPushCommand'));

        self::assertGreaterThanOrEqual(
            3,
            preg_match_all("/'class'\s*=>\s*get_class\(/", $worker),
        );
    }

    // ------------------------------------------------------------- harness

    private function peerManager(): FederationPeerManager
    {
        return new FederationPeerManager(
            $this->hubRepo,
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(AuditLogger::class),
            new Ed25519KeyManager($this->dir . '/leaf-ed25519.pem'),
            static fn (): bool => true,
        );
    }

    private static function methodText(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    private function readProp(object $object, string $name): mixed
    {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    private function writeProp(object $object, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * @return array{runTime: int, timerId: int, interval: float, callback: callable}
     */
    private function soleArmedTimer(): array
    {
        $armed = $this->armedTimers();
        self::assertCount(1, $armed, 'single-in-flight-timer invariant violated');

        return $armed[0];
    }

    /**
     * @return list<array{runTime: int, timerId: int, interval: float, callback: callable}>
     */
    private function armedTimers(): array
    {
        /** @var array<int, array<int, array{0: callable, 1: array<array-key, mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = (new ReflectionProperty(Timer::class, 'tasks'))->getValue();

        $armed = [];
        foreach ($tasks as $runTime => $bucket) {
            foreach ($bucket as $timerId => $task) {
                $armed[] = [
                    'runTime'  => (int) $runTime,
                    'timerId'  => (int) $timerId,
                    'interval' => (float) $task[3],
                    'callback' => $task[0],
                ];
            }
        }

        return $armed;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
