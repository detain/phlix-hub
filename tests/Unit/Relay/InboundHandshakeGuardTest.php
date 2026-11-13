<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\InboundHandshakeGuard;
use Phlix\Hub\Relay\InboundPackageCap;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Tests\Support\ScriptedEventDriver;
use Phlix\Hub\Tests\Support\TempLoggerCapture;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Timer;
use Workerman\Worker;

use function file_get_contents;
use function preg_match_all;
use function sort;
use function stream_socket_pair;
use function substr_count;

/**
 * HOST-3 (flag H3-3) — the hub must not keep silent inbound sockets.
 *
 * A TLS client that completes transport and never sends the HTTP `Upgrade`
 * held the hub's :8802 inbound ~indefinitely (10 min observed live; the
 * pre-fix "~2 min close" was the CLIENT destroying its socket, not any hub
 * policy — FIX-HUB-RELAY-OBS proved no repo-side timer existed on this
 * path). This suite pins the cure: a repo-owned, config-gated deadline armed
 * at TCP-accept and cancelled by the upgrade, answering silence with ONE
 * WARN line (`reason=handshake_timeout`) + a plain close whose destroy fires
 * the surface's existing F-3 lifecycle INFO line.
 *
 * Scripted-loop harness (no real sleeps): a fake {@see EventInterface} is
 * installed into `Timer::$event`, so `Timer::add(..., persistent:false)`
 * takes its real delegation branch and the test owns fire timing. The fake
 * allocates ids from 0 ON PURPOSE — Events\Event's first timer id really is
 * 0 while StreamSelect/Ev/Swoole start at 1, so the fake is STRICTER than
 * production (the estate's M-S1 lesson), and its offDelay deliberately
 * LEAVES the callback fireable (adversarial driver simulation), so the
 * guard's own map-identity gate must be what renders a cancelled deadline
 * inert — not vendor luck.
 *
 * Wire law: successful clients see byte-identical behavior — their deadline
 * is cancelled before the worker writes anything. No close codes, no
 * envelopes, and the WARN line's context key set is pinned closed (the
 * zero-payload redaction law: this path has no payload argument to leak, so
 * the field-set pin is what makes "leak nothing" measurable).
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class InboundHandshakeGuardTest extends TestCase
{
    use TempLoggerCapture;

    /** Peer identity the guard IS allowed to log (bare IP, F-4 law). */
    private const SAFE_IP = '198.51.100.23';

    /** Ported address — F-3's INFO line owns it; the WARN line must not. */
    private const PEELED_ADDRESS = 'tcp://198.51.100.23:43210';

    /** Tripwire shaped like a credential; must be nowhere in the capture. */
    private const CREDENTIAL_CANARY = 'CANARY.ENROLLMENT.JWT.MUST.NEVER.BE.LOGGED';

    private ScriptedEventDriver $loop;

    /** Snapshot of `Timer::$event` taken before the test. */
    private ?EventInterface $previousTimerEvent = null;

    /**
     * Snapshot of `Worker::$workers` taken before the test.
     *
     * @var array<array-key, Worker>
     */
    private array $previousWorkerRegistry = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->startLoggerCapture();

        $this->previousTimerEvent = self::readTimerEvent();
        $this->previousWorkerRegistry = self::readWorkerRegistry();

        $this->loop = new ScriptedEventDriver();
        self::writeTimerEvent($this->loop);

        InboundHandshakeGuard::resetForTests();
        RelayWorker::reset();
    }

    protected function tearDown(): void
    {
        self::writeTimerEvent($this->previousTimerEvent);
        self::writeWorkerRegistry($this->previousWorkerRegistry);

        InboundHandshakeGuard::resetForTests();
        RelayWorker::reset();

        $this->stopLoggerCapture();

        parent::tearDown();
    }

    // ---- deadline lifecycle ---------------------------------------------------

    public function testSilentInboundIsClosedAtDeadlineWithHandshakeTimeoutReason(): void
    {
        $connection = $this->makeSilentConnection();
        $connection->expects($this->once())->method('close');

        $this->armGuard(10.0)($connection);

        $this->assertSame([10.0], $this->loop->delays, 'the deadline must arm as a ONE-SHOT delay at the bound');
        $this->assertSame([], $this->loop->repeats, 'the deadline must never arm as a repeating timer');
        $this->assertSame(
            0,
            $this->loop->lastDelayId,
            'the driver hands out id 0 first — the guard must not treat it as "no timer"',
        );
        $this->assertSame(1, InboundHandshakeGuard::armedCount());

        $this->loop->fire(0);

        $log = $this->capturedLoggerOutput();
        $this->assertStringContainsString('"reason":"handshake_timeout"', $log);
        $this->assertStringContainsString('"listener":"relay-ws :8802"', $log);
        $this->assertStringContainsString('"remote_ip":"' . self::SAFE_IP . '"', $log);
        // F-3 fingerprint carried through: ClientHello-only stalls read ~1.5k.
        $this->assertStringContainsString('"bytes_read":1533', $log);
        $this->assertSame(0, InboundHandshakeGuard::armedCount(), 'the fired deadline releases its slot');
    }

    public function testWarnLineFieldSetIsPinnedClosedNoAddressPortOrPayload(): void
    {
        $connection = $this->makeSilentConnection();
        // Fields a careless implementation could reach for and must not: the
        // ported address and (by shape of the field-set pin) anything else.
        $connection->method('getRemoteAddress')->willReturn(self::PEELED_ADDRESS);
        $connection->method('getLocalAddress')->willReturn(self::CREDENTIAL_CANARY);

        $this->armGuard(10.0)($connection);
        $this->loop->fire(0);

        $log = $this->capturedLoggerOutput();
        $this->assertStringNotContainsString(self::PEELED_ADDRESS, $log);
        $this->assertStringNotContainsString(':43210', $log);
        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, $log);

        $contextKeys = $this->warnLineContextKeys($log);
        $expected = [
            'bytes_read',
            'bytes_written',
            'channel',
            'elapsed_seconds',
            'listener',
            'reason',
            'remote_ip',
            'timeout_seconds',
        ];
        sort($contextKeys);
        $this->assertSame(
            $expected,
            $contextKeys,
            'the handshake_timeout WARN line may log EXACTLY these fields — adding any other (payload, '
                . 'address-with-port, identity) is a redaction-law breach and must redden this pin',
        );
    }

    public function testTimelyUpgradeCancelsTheDeadlineAndNeverCloses(): void
    {
        $connection = $this->makeSilentConnection();
        $connection->expects($this->never())->method('close');

        $this->armGuard(10.0)($connection);
        InboundHandshakeGuard::cancel($connection);

        $this->assertSame([0], $this->loop->cancelled, 'cancel must del() the armed id (0 is a REAL id)');
        $this->assertSame(0, InboundHandshakeGuard::armedCount());

        // Adversarial driver: offDelay left the callback installed — the
        // guard's map-identity gate is what must make the late fire inert.
        $this->loop->fire(0);

        $this->assertSame(
            '',
            $this->capturedLoggerOutput(),
            'a cancelled deadline must log nothing when it fires late',
        );
    }

    public function testStaleCallbackIsInertWhenTheSlotWasReArmedByIdentity(): void
    {
        // REVIEW-HUB-GUARD F-C: the ONE scenario the fire-path identity clause
        // ($held !== $timerId) guards — the slot re-armed while the stale
        // callback is still fireable (the adversarial driver guarantees
        // fireability, so only the clause can make this inert). Weakening the
        // clause to a bare null check (mutation M2) reddens this test.
        $connection = $this->makeSilentConnection();
        $connection->expects($this->once())->method('close');

        $this->armGuard(10.0)($connection); // id 0 arms the slot
        InboundHandshakeGuard::cancel($connection);
        $this->armGuard(10.0)($connection); // id 1 re-arms the SAME object

        $this->assertSame([10.0, 10.0], $this->loop->delays, 'both arms must take the real delay branch');
        $this->assertSame([0], $this->loop->cancelled, 'cancel must del() the first id even though 0 is falsy');
        $this->assertSame(1, InboundHandshakeGuard::armedCount());

        // The departed deadline fires late: the live arm owns the slot now —
        // identity gate must refuse it with no act, no log, no slot loss.
        $this->loop->fire(0);
        $this->assertSame('', $this->capturedLoggerOutput(), 'a superseded deadline must be inert');
        $this->assertSame(1, InboundHandshakeGuard::armedCount(), 'the stale fire must not consume the live slot');

        // The live deadline (id 1) still owns the connection and acts once.
        $this->loop->fire(1);
        $this->assertStringContainsString('"reason":"handshake_timeout"', $this->capturedLoggerOutput());
        $this->assertSame(0, InboundHandshakeGuard::armedCount());
    }

    public function testDoubleFireClosesExactlyOnce(): void
    {
        $connection = $this->makeSilentConnection();
        $connection->expects($this->once())->method('close');

        $this->armGuard(10.0)($connection);

        $this->loop->fire(0);
        $this->loop->fire(0);

        $this->assertSame(0, InboundHandshakeGuard::armedCount());
    }

    public function testDeadlineOnAlreadyClosingConnectionActsSilentlyAndReleasesItsSlot(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('getRemoteIp')->willReturn(self::SAFE_IP);
        $connection->method('getStatus')->willReturn(TcpConnection::STATUS_CLOSING);
        $connection->expects($this->never())->method('close');

        $this->armGuard(10.0)($connection);
        $this->loop->fire(0);

        $this->assertSame(
            '',
            $this->capturedLoggerOutput(),
            'teardown already owns the close — no second act, no second line',
        );
        $this->assertSame(0, InboundHandshakeGuard::armedCount(), 'the armed slot must still be released');
    }

    public function testThrowingCloseIsContainedAndAttributed(): void
    {
        $connection = $this->makeSilentConnection();
        $connection->method('close')->willThrowException(new RuntimeException('dead socket'));

        $this->armGuard(10.0)($connection);
        $this->loop->fire(0); // must NOT escape (R3 survivor-sweep law)

        $log = $this->capturedLoggerOutput();
        $this->assertStringContainsString('handshake-timeout close threw', $log);
        $this->assertStringContainsString('dead socket', $log);
    }

    // ---- config gate ----------------------------------------------------------

    public function testZeroTimeoutDisablesTheGuardButStillRunsTheInnerHandler(): void
    {
        $innerRan = false;
        $handler = InboundHandshakeGuard::compose(
            static function () use (&$innerRan): void {
                $innerRan = true;
            },
            0.0,
            'relay-ws :8802',
        );

        $connection = $this->makeSilentConnection();
        $connection->expects($this->never())->method('close');

        $handler($connection);

        $this->assertTrue($innerRan, 'the declared inbound cap handler must always run, guard or no guard');
        $this->assertSame([], $this->loop->delays, 'disabled guard arms nothing');
        $this->assertSame(0, InboundHandshakeGuard::armedCount());
    }

    public function testResolveTimeoutSecondsParsesBoundaryValues(): void
    {
        // Missing / slop → default (fail to SAFE bound, not to off).
        $this->assertSame(30.0, InboundHandshakeGuard::resolveTimeoutSeconds(null));
        $this->assertSame(30.0, InboundHandshakeGuard::resolveTimeoutSeconds('abc'));
        $this->assertSame(30.0, InboundHandshakeGuard::resolveTimeoutSeconds(-1));
        // Negative is a typo, not a policy — it must not flip the guard OFF.
        $this->assertSame(5.0, InboundHandshakeGuard::resolveTimeoutSeconds('5'));
        $this->assertSame(5.5, InboundHandshakeGuard::resolveTimeoutSeconds(5.5));
        // 0 IS the documented off switch.
        $this->assertSame(0.0, InboundHandshakeGuard::resolveTimeoutSeconds(0));
        $this->assertSame(0.0, InboundHandshakeGuard::resolveTimeoutSeconds('0'));
    }

    public function testArmFailureOutsideRuntimeDegradesGracefullyWithLoudLog(): void
    {
        // No event driver AND no registered workers → Timer::add takes the
        // vendor THROWING arm. The accept path must survive it (resident
        // worker containment) with a loud line, never a silent hole.
        self::writeTimerEvent(null);
        self::writeWorkerRegistry([]);

        $connection = $this->makeSilentConnection();
        $connection->expects($this->never())->method('close');

        $this->armGuard(10.0)($connection); // must NOT throw

        $log = $this->capturedLoggerOutput();
        $this->assertStringContainsString('inbound handshake guard unavailable', $log);
        $this->assertSame(0, InboundHandshakeGuard::armedCount());
    }

    // ---- cap composition on a real connection -----------------------------------

    public function testComposedHandlerStillLandsTheDeclaredCapOnARealConnection(): void
    {
        /** @var array{0: resource, 1: resource} $pair */
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $connection = new TcpConnection(new Select(), $pair[0]);

        try {
            $this->armGuard(10.0)($connection);

            $this->assertSame(
                InboundPackageCap::MAX_INBOUND_PACKAGE_BYTES,
                $connection->maxPackageSize,
                'guard composition must keep the Minor 57 cap law intact on the surface',
            );
            $this->assertSame(1, InboundHandshakeGuard::armedCount());

            InboundHandshakeGuard::cancel($connection);
            $this->assertSame(0, InboundHandshakeGuard::armedCount());
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    // ---- worker wiring ----------------------------------------------------------

    public function testRelayWorkerUpgradeCancelsTheArmedDeadline(): void
    {
        $relay = new RelayWorker($this->makeThrowingContainer(), 0);

        $connection = $this->makeSilentConnection();
        $connection->expects($this->never())->method('close');
        $this->armGuard(10.0)($connection);

        $relay->onWebSocketConnect($connection, $this->makeUpgradeRequest());

        $this->assertSame([0], $this->loop->cancelled, 'the upgrade hook must cancel before any limiter/refuse branch');
        $this->assertSame(0, InboundHandshakeGuard::armedCount());
    }

    public function testClientRelayWorkerRefuseBranchCancelledBeforeTheRefusalClose(): void
    {
        $worker = new ClientRelayWorker($this->makeThrowingContainer(), 0);

        $connection = $this->makeSilentConnection();
        // Missing server_id path -> the worker's own refuse close runs, but the
        // deadline must already be gone (once = the refusal only; the guard
        // must never add a second act to a connection the worker owns).
        $connection->expects($this->once())->method('close')->with('', true);
        $this->armGuard(10.0)($connection);

        $request = new WorkermanRequest("GET /nope HTTP/1.1\r\nHost: hub.example.com\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n");

        $worker->onWebSocketConnect($connection, $request);

        $this->assertSame([0], $this->loop->cancelled);

        // Late fire of the adversarially-retained callback is inert: the
        // refused connection gets NO handshake_timeout line on top of the
        // worker's own refusal.
        $this->loop->fire(0);
        $this->assertStringNotContainsString('handshake_timeout', $this->capturedLoggerOutput());
    }

    public function testEveryGuardedWorkerComposesTheGuardAndCancelsTwice(): void
    {
        $root = dirname(__DIR__, 3);

        $guarded = [
            'src/Relay/RelayWorker.php',
            'src/Relay/ClientRelayWorker.php',
            'src/SyncPlay/SyncPlayRelayWorker.php',
        ];

        foreach ($guarded as $relative) {
            $source = file_get_contents($root . '/' . $relative);
            $this->assertIsString($source, $relative . ' must exist');
            $this->assertStringContainsString(
                'InboundHandshakeGuard::compose(',
                $source,
                $relative . ' must arm the deadline through the composed onConnect',
            );
            $this->assertStringContainsString(
                'InboundPackageCap::onConnect()',
                $source,
                $relative . ' must keep the declared cap visibly composed',
            );
            $this->assertSame(
                2,
                substr_count($source, 'InboundHandshakeGuard::cancel($connection);'),
                $relative . ' owes exactly two cancel sites: onWebSocketConnect + onClose',
            );
        }
    }

    public function testFederationSurfaceComposesTheGuardAndCancelsTwice(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/src/Relay/FederationWorker.php');
        $this->assertStringContainsString('InboundHandshakeGuard::compose(', $source);
        $this->assertStringContainsString('InboundPackageCap::onConnect()', $source);
        $this->assertSame(
            2,
            substr_count($source, 'InboundHandshakeGuard::cancel($connection);'),
            'owes exactly two cancel sites: onWebSocketConnect + onClose',
        );

        $provider = (string) file_get_contents($root . '/src/Common/Container/Providers/HubServicesProvider.php');
        $this->assertStringContainsString('$federationHandshakeTimeout', $provider);
        $this->assertStringContainsString('ws_handshake_timeout_seconds', $provider);
    }

    public function testFederationWorkerUpgradeCancelsTheArmedDeadline(): void
    {
        // Source-level wiring is pinned above; behaviour of cancel() itself
        // is covered by the RelayWorker/ClientRelayWorker cases. Here we pin
        // that the federation worker accepts the deadline as a ctor arg.
        $ref = new \ReflectionClass(\Phlix\Hub\Relay\FederationWorker::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = array_map(static fn ($p) => $p->getName(), $ctor->getParameters());
        $this->assertContains('handshakeTimeoutSeconds', $params);
    }

    public function testApplicationAndConfigCarryTheDeadlineKnob(): void
    {
        $root = dirname(__DIR__, 3);

        $appSource = (string) file_get_contents($root . '/src/Application.php');
        $this->assertStringContainsString('InboundHandshakeGuard::resolveTimeoutSeconds', $appSource);
        $this->assertStringContainsString('ws_handshake_timeout_seconds', $appSource);
        $this->assertStringContainsString('handshakeTimeoutSeconds: $wsHandshakeTimeoutSeconds', $appSource);
        $argumentSites = preg_match_all('/\$wsHandshakeTimeoutSeconds[,)]/', $appSource);
        $this->assertSame(
            3,
            $argumentSites,
            'the deadline must flow to all three governed workers (:8802/:8803/:8804)',
        );

        $configSource = (string) file_get_contents($root . '/config/server.php');
        $this->assertStringContainsString('HUB_RELAY_WS_HANDSHAKE_TIMEOUT_SECONDS', $configSource);
        $this->assertStringContainsString("'ws_handshake_timeout_seconds'", $configSource);
    }

    // ---- helpers ----------------------------------------------------------------

    private function armGuard(float $seconds): callable
    {
        return InboundHandshakeGuard::compose(
            InboundPackageCap::onConnect(),
            $seconds,
            'relay-ws :8802',
        );
    }

    /**
     * @return TcpConnection&MockObject
     */
    private function makeSilentConnection(): TcpConnection&MockObject
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('getRemoteIp')->willReturn(self::SAFE_IP);
        $connection->method('getStatus')->willReturn(TcpConnection::STATUS_ESTABLISHED);
        // F-3 fingerprint: ClientHello-only stalls read ~1.5k and write 0.
        $connection->bytesRead = 1533;
        $connection->bytesWritten = 0;

        return $connection;
    }

    private function makeUpgradeRequest(): WorkermanRequest
    {
        $raw = "GET / HTTP/1.1\r\nHost: hub.example.com\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        return new WorkermanRequest($raw);
    }

    private function makeThrowingContainer(): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('container absent in unit'));

        return $container;
    }

    /**
     * Quoted keys of the handshake_timeout WARN line's context object.
     *
     * Slices from the context opening (`{"reason":"handshake_timeout"`) to the
     * line end so message/level renderings outside the context never join the
     * compared set.
     *
     * @return list<string>
     */
    private function warnLineContextKeys(string $log): array
    {
        foreach (explode("\n", $log) as $line) {
            if (!str_contains($line, '{"reason":"handshake_timeout"')) {
                continue;
            }

            $slice = substr($line, (int) strpos($line, '{"reason":"handshake_timeout"'));
            preg_match_all('/"([a-z_]+)":/', $slice, $matches);

            /** @var list<string> $keys */
            $keys = $matches[1];

            return $keys;
        }

        $this->fail('no handshake_timeout context slice found in the capture');
    }


    private static function readTimerEvent(): ?EventInterface
    {
        /** @var EventInterface|null $value */
        $value = (new ReflectionProperty(Timer::class, 'event'))->getValue();

        return $value;
    }

    private static function writeTimerEvent(?EventInterface $event): void
    {
        (new ReflectionProperty(Timer::class, 'event'))->setValue(null, $event);
    }

    /**
     * @return array<array-key, Worker>
     */
    private static function readWorkerRegistry(): array
    {
        /** @var array<array-key, Worker> $value */
        $value = (new ReflectionProperty(Worker::class, 'workers'))->getValue();

        return $value;
    }

    /**
     * @param array<array-key, Worker> $workers
     */
    private static function writeWorkerRegistry(array $workers): void
    {
        (new ReflectionProperty(Worker::class, 'workers'))->setValue(null, $workers);
    }
}
