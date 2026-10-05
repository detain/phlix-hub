<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
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
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Timer;

/**
 * W5 `federation.enabled` gate tests for the wire-transport halves: the frame
 * handler (inbound) and the peer manager (outbound dial).
 *
 * Pins: disabled -> inbound text refused with an audit trail, inbound binary
 * dropped without touching session state, outbound dial returns BEFORE any
 * repository read or enabled-path state mutation.
 *
 * Reconnect-chain truth (BEHAVIOR lane — supersedes the docs-truth pass
 * @5a048a6 "chain dies after one refused tick" reading): the chain now
 * SURVIVES the disabled window. The reconnect tick tests the gate itself;
 * while it is closed the chain PARKS at the ≤60 s backoff cap — one gated
 * re-check per cap, zero TCP attempts, zero repository reads — so
 * re-enabling re-dials automatically within one capped tick, no process
 * restart or explicit trigger required. A refused explicit dial arms the
 * same parked probe (idempotent, never while a socket is live, never after
 * a deliberate disconnect — M-7). Links that survive the off-window still
 * re-admit frames instantly; the ENABLED-path backoff ladder (5→10→20→40→
 * 60→60) is byte-preserved and pinned here. Null resolver keeps the pre-W5
 * always-on path exactly — asserted by the unchanged answers.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationDisabledTransportTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private FederationConnectionManager $connMgr;

    private string $keyDir = '';

    private Ed25519KeyManager $keyManager;

    private FederationHubRepository&MockObject $hubRepo;

    private FederationSessionManager&MockObject $sessions;

    /** Temp file the RELAY log writes to — read back to pin log cadence. */
    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connMgr = new FederationConnectionManager();
        $this->keyDir  = sys_get_temp_dir() . '/phlix-hub-fedoff-keys-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $this->keyManager = new Ed25519KeyManager($this->keyDir . '/master-ed25519.pem');

        $this->hubRepo  = $this->createMock(FederationHubRepository::class);
        $this->sessions = $this->createMock(FederationSessionManager::class);

        // The parked-chain announcement logs through RELAY — point it at a
        // private temp file so cadence ("announced once per off-window") is
        // assertable without touching global streams.
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'phlix-hub-fedoff-log-');
        $configDir = $this->keyDir . '/logs';
        mkdir($configDir, 0700, true);
        file_put_contents(
            $configDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => '" . $this->logFile . "', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($configDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $this->logFile = '';
        if ($this->keyDir !== '' && is_dir($this->keyDir . '/logs')) {
            unlink($this->keyDir . '/logs/logger.php');
            @rmdir($this->keyDir . '/logs');
        }
        foreach (glob($this->keyDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->keyDir);

        parent::tearDown();
    }

    private function frameHandler(?callable $enabledResolver, ?AuditLogger $audit = null): FederationFrameHandler
    {
        return new FederationFrameHandler(
            $this->hubRepo,
            $this->sessions,
            $this->createMock(FederationLibraryShareRepository::class),
            $this->connMgr,
            $audit ?? $this->createMock(AuditLogger::class),
            $this->keyManager,
            $enabledResolver,
        );
    }

    private function peerManager(?callable $enabledResolver): FederationPeerManager
    {
        return new FederationPeerManager(
            $this->hubRepo,
            $this->sessions,
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(AuditLogger::class),
            $this->keyManager,
            $enabledResolver,
        );
    }

    // -------------------------------------------------------- frame handler

    public function testDisabledTextFrameIsRefusedWithAuditTrail(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logFailedAuth')
            ->with('FEDERATION_DISABLED', ['hub_id' => 'hub-9']);
        $this->hubRepo->expects(self::never())->method('getPeerByPublicKey');

        $handler = $this->frameHandler(static fn (): bool => false, $audit);

        $refused = $handler->handleTextFrame(
            'hub-9',
            '{"type":"hub_hello","public_key":"abc"}',
            $this->createMock(ConnectionInterface::class),
        );

        self::assertSame('federation_disabled', $refused);
    }

    public function testDisabledBinaryFrameDropsWithoutTouchingPeerState(): void
    {
        // Register a live connection so the ENABLED heartbeat path WOULD
        // reach the repository — the differential proves the gate, not an
        // empty connection table, is what drops the frame.
        $this->connMgr->addConnection('hub-9', $this->createMock(ConnectionInterface::class));
        $this->hubRepo->expects(self::never())->method('getPeerById');

        $handler = $this->frameHandler(static fn (): bool => false);

        // DATA(0x05)/HEARTBEAT(0x06)/DISCONNECTED(0x07) — all must no-op.
        $handler->handleBinaryFrame('hub-9', '{"shares":[]}', 0x05);
        $handler->handleBinaryFrame('hub-9', '', 0x06);
        $handler->handleBinaryFrame('hub-9', '', 0x07);
    }

    public function testControlEnabledHeartbeatDoesReachTheRepository(): void
    {
        $this->connMgr->addConnection('hub-9', $this->createMock(ConnectionInterface::class));
        $this->hubRepo->method('getPeerById')->willReturn(null);
        $this->hubRepo->expects(self::once())->method('getPeerById')->with('hub-9');

        // No resolver: always-on (pre-W5) — HEARTBEAT walks into the lookup.
        $this->frameHandler(null)->handleBinaryFrame('hub-9', '', 0x06);
    }

    public function testNullResolverKeepsPreW5Answers(): void
    {
        $handler = $this->frameHandler(null);

        $malformed = $handler->handleTextFrame('hub-9', '{bogus', $this->createMock(ConnectionInterface::class));

        self::assertSame('Invalid JSON payload', $malformed);
        // Binary with unknown frame type also no-ops exactly as before (no throw).
        $handler->handleBinaryFrame('hub-9', '', 0xFF);
    }

    public function testEnabledAnswerIsLivePerFrame(): void
    {
        $on    = true;
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logFailedAuth')->with('FEDERATION_DISABLED', ['hub_id' => 'hub-9']);

        $handler = $this->frameHandler(static function () use (&$on): bool {
            return $on;
        }, $audit);

        $conn = $this->createMock(ConnectionInterface::class);

        // Enabled: reaches frame parsing (unknown types ignored => null).
        self::assertNull($handler->handleTextFrame('hub-9', '{"type":"unknown_type"}', $conn));

        $on = false;
        self::assertSame('federation_disabled', $handler->handleTextFrame('hub-9', '{"type":"unknown_type"}', $conn));
    }

    // -------------------------------------------------------- peer manager

    /**
     * A disabled explicit dial still consults NO repository (gate placement
     * unchanged) — and now arms the single parked self-heal probe instead of
     * silently ending there, so a hub that booted with federation off
     * re-dials on its own within one capped tick of re-enabling. Repeated
     * refusals stay idempotent (single-in-flight-timer invariant).
     */
    public function testDisabledDialReturnsBeforeAnyRepositoryRead(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $manager = $this->peerManager(static fn (): bool => false);

        $manager->connectToMaster();
        self::assertSame(5.0, $this->soleArmedTimer()['interval'], 'gated explicit dial arms the park probe');

        $manager->connectToMaster();
        $manager->connectToMaster();
        self::assertCount(1, $this->armedTimers(), 'repeat gated dials must not stack timers');
    }

    public function testNullResolverDialRunsThePreW5GuardChain(): void
    {
        // Pre-W5 behavior: the hub-config role guard still governs. With no
        // config row the dial returns on `hubConfig === null` — meaning the
        // repository WAS consulted (opposite of the disabled pin above).
        $this->hubRepo->expects(self::once())->method('getHubConfig')->willReturn(null);

        $this->peerManager(null)->connectToMaster();
    }

    // ------------------------------------------------- disabled-window park

    /**
     * THE load-bearing pin of this lane: a reconnect tick that fires while
     * federation is disabled RE-ARMS the chain at the cap instead of dying
     * (pre-lane, this was the chain's last tick — pending count fell to 0).
     */
    public function testDisabledReconnectTickParksChainAtCapInsteadOfDying(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $manager = $this->peerManager(static fn (): bool => false);
        $this->armReconnectChain($manager);

        self::assertSame(5.0, $this->soleArmedTimer()['interval'], 'fresh chain arms at the initial delay');

        $this->fireArmedTicks();

        $timer = $this->soleArmedTimer();
        self::assertSame(60.0, $timer['interval'], 'disabled tick must re-arm AT the cap (one wake per cap)');
        self::assertTrue((bool) $this->readProp($manager, 'reconnectScheduled'));
        self::assertSame(60, $this->readProp($manager, 'reconnectDelaySeconds'));
        self::assertFalse($manager->isConnected(), 'parking must never touch the transport');
    }

    /**
     * Re-enabling mid-park: the NEXT armed tick proceeds past the gate into
     * the real dial guard chain (repository consulted) — no restart, no
     * explicit trigger. The park throttle resets on the enabled proceed.
     */
    public function testHeldTickProceedsPastGateOnceReEnabled(): void
    {
        $this->forceWorkermanRuntime();
        $on = false;
        $this->hubRepo->expects(self::once())->method('getHubConfig')->willReturn(null);
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $manager = $this->peerManager(static function () use (&$on): bool {
            return $on;
        });

        // Park the chain.
        $this->armReconnectChain($manager);
        $this->fireArmedTicks();
        self::assertSame(60.0, $this->soleArmedTimer()['interval']);

        // Re-enable, then let the held tick fire: it must DIAL (hub-config
        // read = past the gate), not park again.
        $on = true;
        $this->fireArmedTicks();

        self::assertCount(0, $this->armedTimers(), 'enabled proceed re-arms only via connection events');
        self::assertFalse((bool) $this->readProp($manager, 'reconnectScheduled'));
        self::assertFalse((bool) $this->readProp($manager, 'reconnectHoldAnnounced'), 'throttle resets once enabled');
    }

    /**
     * Across a long off-window: every parked tick re-checks the gate but
     * NOTHING reaches transport or repository, and the park is announced
     * exactly once (no 60s log spam).
     */
    public function testDisabledParkNeverTouchesTransportAndAnnouncesOnce(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $manager = $this->peerManager(static fn (): bool => false);
        $this->armReconnectChain($manager);

        for ($tick = 0; $tick < 5; $tick++) {
            $this->fireArmedTicks();
            self::assertSame(60.0, $this->soleArmedTimer()['interval'], "tick {$tick} keeps the chain parked");
            self::assertFalse($manager->isConnected());
        }

        $log = (string) file_get_contents($this->logFile);
        self::assertSame(
            1,
            substr_count($log, 'reconnect chain parked'),
            'park announcement is once per off-window, not once per capped re-check',
        );
        self::assertTrue((bool) $this->readProp($manager, 'reconnectHoldAnnounced'));
    }

    /**
     * ENABLED-path preservation pin: the backoff ladder 5→10→20→40→60→60 and
     * the "dial proceeds, chain re-arms only via connection events" law are
     * byte-preserved — a proceed tick arms nothing on its own and logs no
     * park announcement.
     */
    public function testEnabledPathBackoffLadderUnchanged(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->method('getHubConfig')->willReturn(null);
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $manager = $this->peerManager(static fn (): bool => true);

        $ladder = [5.0, 10.0, 20.0, 40.0, 60.0, 60.0];
        $after  = [10, 20, 40, 60, 60, 60];

        foreach ($ladder as $i => $interval) {
            $this->armReconnectChain($manager);
            self::assertSame($interval, $this->soleArmedTimer()['interval'], "ladder step {$i}");
            $this->fireArmedTicks();
            self::assertSame($after[$i], $this->readProp($manager, "reconnectDelaySeconds"), "step {$i}");
            self::assertCount(0, $this->armedTimers(), "step {$i}: proceed arms no new timer");
        }

        self::assertSame('', (string) file_get_contents($this->logFile), 'enabled path logs no park announcement');
    }

    /**
     * No double timer under churn: an already-parked chain + repeated
     * explicit gated dials keeps EXACTLY one armed timer; flipping enabled
     * mid-chain makes the next (sole) tick dial.
     */
    public function testNoDoubleArmedTimerUnderDisabledChurn(): void
    {
        $this->forceWorkermanRuntime();
        $on = false;
        $this->hubRepo->method('getHubConfig')->willReturn(null);

        $manager = $this->peerManager(static function () use (&$on): bool {
            return $on;
        });

        // Park via a tick, then churn explicit triggers while parked.
        $this->armReconnectChain($manager);
        $this->fireArmedTicks();
        $manager->connectToMaster();
        $on = true;
        $manager->connectToMaster(); // enabled proceed: config-null guard, arms nothing
        $on = false;
        $manager->connectToMaster(); // parked flag already set: no second timer

        self::assertCount(1, $this->armedTimers());

        $on = true;
        $this->fireArmedTicks();
        self::assertCount(0, $this->armedTimers(), 'the sole armed tick dials once enabled');
    }

    /**
     * M-7 law mirrored onto every NEW arming site: a deliberate disconnect
     * suppresses the park probe — both for fresh explicit dials and by
     * deleting an already-parked timer; the throttle resets so a later real
     * chain can announce again.
     */
    public function testDeliberateDisconnectSuppressesParkArmingEverywhere(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        // (1) Explicit dial while intentional + disabled: no arm, no repo.
        $dropped = $this->peerManager(static fn (): bool => false);
        $dropped->disconnectFromMaster();
        $dropped->connectToMaster();
        self::assertCount(0, $this->armedTimers(), 'gated dial must not conjure a chain across M-7');
        $this->armReconnectChain($dropped);
        self::assertCount(0, $this->armedTimers(), 'no arming while the intentional flag is set');

        // (2) A fresh parked chain tears down completely on deliberate
        // disconnect: timer deleted, flags and ladder reset.
        $parked = $this->peerManager(static fn (): bool => false);
        $this->armReconnectChain($parked);
        $this->fireArmedTicks();
        self::assertCount(1, $this->armedTimers());
        $this->armReconnectChain($parked); // no-op while parked (scheduled flag)
        self::assertCount(1, $this->armedTimers());

        $parked->disconnectFromMaster();
        self::assertCount(0, $this->armedTimers(), 'deliberate disconnect must delete the parked timer');
        self::assertFalse((bool) $this->readProp($parked, 'reconnectScheduled'));
        self::assertSame(5, $this->readProp($parked, 'reconnectDelaySeconds'));
        self::assertFalse((bool) $this->readProp($parked, 'reconnectHoldAnnounced'));

        // (3) A late-fired tick (dispatched by the loop just before the
        // delete landed) must die silently EVEN WHEN RE-ENABLED: the M-7
        // intentional check sits STRICTLY BEFORE the disabled-park branch
        // and before the dial, so a suppressed chain can neither re-park
        // nor resurrect itself as a live dial attempt.
        $on = true;
        $late = $this->peerManager(static function () use (&$on): bool {
            return $on;
        });
        $this->armReconnectChain($late);
        $tick = $this->soleArmedTimer()['callback'];
        $late->disconnectFromMaster();
        $on = false;
        $tick(); // suppressed + disabled: intentional guard wins, no re-park
        $on = true;
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        $tick(); // suppressed + enabled: must NOT dial past the M-7 guard
        self::assertCount(0, $this->armedTimers(), 'suppressed tick must not re-park the chain');
    }

    /**
     * Outside a Workerman timer runtime the gated arm must swallow cleanly
     * AND roll the in-flight latch back — a later arm attempt (once a
     * runtime exists) must still be able to park the chain. Without the
     * rollback in scheduleReconnect, reconnectScheduled would strand true
     * with no timer behind it and silence every future arming site.
     */
    public function testGatedArmOutsideRuntimeRollsLatchBack(): void
    {
        $this->forceNoWorkermanRuntime();
        $manager = $this->peerManager(static fn (): bool => false);

        $manager->connectToMaster(); // Timer::add throws inside — swallowed.
        self::assertFalse((bool) $this->readProp($manager, 'reconnectScheduled'));

        $this->forceWorkermanRuntime();
        $manager->connectToMaster();
        self::assertCount(1, $this->armedTimers(), 'post-rollback arm must succeed once a runtime exists');
    }

    /**
     * A LIVE socket is never disturbed by the gate: an explicit dial refused
     * while disabled neither closes it nor arms a competing probe (the
     * instant-re-admission law of surviving links).
     */
    public function testGatedDialWhileDisabledLeavesLiveSocketUntouched(): void
    {
        $this->forceWorkermanRuntime();
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->expects(self::never())->method('close');

        $manager = $this->peerManager(static fn (): bool => false);
        $property = new ReflectionProperty($manager, 'masterConnection');
        $property->setAccessible(true);
        $property->setValue($manager, $conn);

        $manager->connectToMaster();

        self::assertCount(0, $this->armedTimers());
        self::assertTrue($manager->isConnected());
        $this->hubRepo->expects(self::never())->method('getHubConfig');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Arm the reconnect chain through the private production entry point
     * (the onClose/onError sites all funnel here).
     */
    private function armReconnectChain(FederationPeerManager $manager): void
    {
        $method = new ReflectionMethod($manager, 'scheduleReconnect');
        $method->setAccessible(true);
        $method->invoke($manager);
    }

    /**
     * Flatten every armed Workerman timer task (pcntl task-table arm) into
     * locatable interval/callback records.
     *
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

    /**
     * The SOLE armed timer — asserts the single-in-flight-timer invariant
     * while doing it.
     *
     * @return array{runTime: int, timerId: int, interval: float, callback: callable}
     */
    private function soleArmedTimer(): array
    {
        $armed = $this->armedTimers();
        self::assertCount(1, $armed, 'single-in-flight-timer invariant violated');

        return $armed[0];
    }

    /**
     * Fire every currently-armed one-shot tick. Mirrors Workerman's
     * Timer::tick semantics for non-persistent tasks — the task-table entry
     * is REMOVED before the callback runs — so a callback that re-arms lands
     * as exactly one fresh task instead of stacking on its own corpse, and
     * ticks re-armed during this round are not double-fired.
     */
    private function fireArmedTicks(): void
    {
        $property = new ReflectionProperty(Timer::class, 'tasks');

        foreach ($this->armedTimers() as $timer) {
            /** @var array<int, array<int, mixed>> $tasks */
            $tasks = $property->getValue();
            unset($tasks[$timer['runTime']][$timer['timerId']]);
            if (($tasks[$timer['runTime']] ?? []) === []) {
                unset($tasks[$timer['runTime']]);
            }
            $property->setValue(null, $tasks);

            $callback = $timer['callback'];
            $callback();
        }
    }

    private function readProp(FederationPeerManager $manager, string $name): mixed
    {
        $property = new ReflectionProperty($manager, $name);
        $property->setAccessible(true);

        return $property->getValue($manager);
    }
}
