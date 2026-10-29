<?php

/**
 * Phlix hub test: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * M-HUB-14 (review hub__exploiter M3, leaf role): the outbound master link's
 * inbound frames must pass the shared :8805 frame budget BEFORE any parse or
 * dispatch, and the over-budget reaction must be the self-healing one — drop
 * the link, let the capped reconnect ladder re-dial after the window.
 *
 * ## Why the two-part proof shape
 *
 * The guard's semantics are fully observable calling `guardIncomingFrame()`
 * directly — the public `@internal` visible-for-tests door the file already
 * keeps for {@see FederationPeerManager::failHelloTimeout()}. What direct
 * calls cannot prove is the one thing r1 was REWORKed over: the guard being
 * WIRED into the live onMessage closure. Unit-venue `connect()` throws
 * synchronously (no event loop), so the constructed socket — and the closure
 * assigned to it — is gone by the time establishConnection() returns; no
 * in-process behaviour test can reach that closure. This suite therefore
 * pins the wiring as a SOURCE-SHAPE law over establishConnection's own text:
 * the onMessage closure must guard-then-handle, in that order. Both halves
 * carry mutation teeth: unwire the call site (the exact r1 defect) and the
 * shape pin reddens; break the guard logic and the behaviour pins redden.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationPeerManagerFrameBudgetTest extends TestCase
{
    use WorkermanTimerRuntimeControl;
    use LoggerFactoryIsolation;

    private FederationHubRepository&MockObject $hubRepo;

    private string $keyDir = '';

    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->keyDir = sys_get_temp_dir() . '/phlix-hub-fedbudget-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);

        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'phlix-hub-fedbudget-log-');
        file_put_contents(
            $this->keyDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => '" . $this->logFile . "', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->keyDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        if (is_file($this->keyDir . '/logger.php')) {
            unlink($this->keyDir . '/logger.php');
        }
        foreach (glob($this->keyDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if ($this->keyDir !== '' && is_dir($this->keyDir)) {
            @rmdir($this->keyDir);
        }
        $this->keyDir = '';
        $this->logFile = '';
        parent::tearDown();
    }

    // --------------------------------------------------- wiring (r1 defect)

    /**
     * The live leaf onMessage closure must run the frame guard BEFORE
     * handleMessage — drop-before-parse. r1's REWORK-1 verdict existed
     * because a lane shipped the limiter plumbing with no call site; this pin
     * is the red-on-that-regression guard.
     */
    public function testEstablishConnectionMessageClosureGuardsBeforeHandle(): void
    {
        $method = new ReflectionMethod(FederationPeerManager::class, 'establishConnection');
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        $slice = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $block = [];
        // Isolate the onMessage closure (between its assignment and the next
        // callback assignment) — the guard must gate the dispatch INSIDE it.
        self::assertSame(
            1,
            preg_match(
                '/\$this->masterConnection->onMessage\s*=\s*function.*?\/\/\s*On close/s',
                $slice,
                $block,
            ),
            'establishConnection() must keep exactly one onMessage closure block',
        );

        $body = (string) ($block[0] ?? '');
        $guardAt = strpos($body, '$this->guardIncomingFrame($data)');
        $handleAt = strpos($body, '$this->handleMessage($data)');

        self::assertIsInt(
            $guardAt,
            'the live onMessage closure must call guardIncomingFrame() — the r1 defect was its absence',
        );
        self::assertIsInt($handleAt, 'the live onMessage closure must still dispatch via handleMessage()');
        self::assertLessThan(
            $handleAt,
            $guardAt,
            'guard runs BEFORE parse/dispatch (drop-before-parse law)',
        );
        self::assertStringContainsString(
            'if ($this->guardIncomingFrame($data)) {',
            $body,
            'the guard result must GATE the dispatch, not merely precede it',
        );
    }

    // ---------------------------------------------------------- behaviour

    /**
     * Under budget: the guard passes the frame and consumes exactly one hit,
     * keyed on the REMOTE peer hub id (`federation_frame:<masterPeerId>` —
     * the same key the master side uses for THIS hub, i.e. one per-link
     * budget seen from either end).
     */
    public function testUnderBudgetPassesWithPerPeerKey(): void
    {
        $hits = [];
        $limiter = new class ($hits) implements \Phlix\Hub\Common\RateLimit\RateLimiterInterface {
            /** @param list<string> $hits */
            public function __construct(public array $hits)
            {
            }

            public function hit(string $key): \Phlix\Hub\Common\RateLimit\RateLimitState
            {
                $this->hits[] = $key;

                return new \Phlix\Hub\Common\RateLimit\RateLimitState(1, 5, time() + 60, false, 6);
            }

            public function reset(string $key): void
            {
            }

            public function peek(string $key): \Phlix\Hub\Common\RateLimit\RateLimitState
            {
                return new \Phlix\Hub\Common\RateLimit\RateLimitState(0, 6, 0, false, 6);
            }
        };

        $manager = $this->peerManager();
        $manager->setReceiveFrameLimiter($limiter);
        $this->setMasterPeerId($manager, 'peer-master-1');

        self::assertTrue($manager->guardIncomingFrame('{"type":"hub_hello_ack"}'));
        self::assertSame(['federation_frame:peer-master-1'], $limiter->hits);
    }

    /**
     * Over budget: frame refused (false), ONE warning carrying the peer id
     * and the frame's byte size, the link dropped via close(), the slot
     * cleared — and the drop SELF-HEALS: the vendor destroy → onClose →
     * scheduleReconnect chain arms exactly one capped-ladder timer (initial
     * 5 s), the same ladder every other link loss re-uses. No proliferation,
     * no dead chain.
     */
    public function testOverBudgetDropsLinkOnceAndReusesTheCappedLadder(): void
    {
        $this->forceWorkermanRuntime();

        // max=1 → the FIRST hit is the limited one (semantics pinned by the
        // sibling client-mount suite).
        $limiter = new RateLimiter(60, 1, 10000, static fn (): int => 3_000_000);

        $manager = $this->peerManager();
        $manager->setReceiveFrameLimiter($limiter);
        $this->setMasterPeerId($manager, 'peer-master-2');

        $closeCount = 0;
        $connection = new AsyncTcpConnection('ws://127.0.0.1:1');
        $connection->onClose = static function () use (&$closeCount, $manager): void {
            $closeCount++;
            // Reproduce the production wiring the venue cannot install (the
            // real closure is created inside establishConnection, which needs
            // a socket that unit-venue connect() refuses to leave alive).
            $schedule = new ReflectionMethod($manager, 'scheduleReconnect');
            $schedule->setAccessible(true);
            $schedule->invoke($manager);
        };
        $this->setMasterConnection($manager, $connection);

        $payload = str_repeat('x', 77);
        self::assertFalse($manager->guardIncomingFrame($payload), 'over-budget frame must be refused');

        self::assertSame(1, $closeCount, 'exactly one link drop per refused frame');
        self::assertNull(
            $this->readProp($manager, 'masterConnection'),
            'the guard must clear the slot before closing (no double-teardown race)',
        );

        $armed = $this->armedTimers();
        self::assertCount(1, $armed, 'the drop must hand the link to exactly one reconnect timer');
        self::assertSame(5.0, $armed[0]['interval'], 'reconnect rides the standard ladder head (5 s)');

        $log = (string) file_get_contents($this->logFile);
        self::assertSame(1, substr_count($log, 'frame budget exceeded'), 'ONE warning line, no amp');
        self::assertStringContainsString('peer-master-2', $log);
        self::assertStringContainsString('"frame_bytes":77', $log);
    }

    /**
     * A second over-budget frame cannot arrive after the drop in production
     * (the socket is gone) — but the guard must still be idempotent-safe:
     * slot already null → refusal is recorded, no close is attempted on a
     * null connection, no extra timers appear.
     */
    public function testRefusalAfterTeardownIsSideEffectFree(): void
    {
        $this->forceWorkermanRuntime();

        $limiter = new RateLimiter(60, 1, 10000, static fn (): int => 4_000_000);
        $manager = $this->peerManager();
        $manager->setReceiveFrameLimiter($limiter);
        $this->setMasterPeerId($manager, 'peer-master-3');
        // Slot never populated (post-teardown shape): null masterConnection.

        $armedBefore = count($this->armedTimers());
        self::assertFalse($manager->guardIncomingFrame('anything'));
        self::assertCount($armedBefore, $this->armedTimers(), 'no timer may be armed by a drop attempt with no socket');

        $log = (string) file_get_contents($this->logFile);
        self::assertSame(1, substr_count($log, 'frame budget exceeded'), 'the refusal is still logged');
    }

    /**
     * Vacuity arm 1 — limiter null (fail-open family law, the shape a hub
     * with an unwireable limiter runs): frames pass, NOTHING is hit.
     */
    public function testNoLimiterInstalledPassesFramesUnlimited(): void
    {
        $manager = $this->peerManager();
        // No setReceiveFrameLimiter() call at all.
        $this->setMasterPeerId($manager, 'peer-master-4');

        self::assertTrue($manager->guardIncomingFrame(str_repeat('y', 40)));
        self::assertSame('', (string) file_get_contents($this->logFile));
    }

    /**
     * Vacuity arm 2 — explicit null install is legal and means unlimited (the
     * worker's fail-open resolver hands exactly this value in).
     */
    public function testNullInstallMeansUnlimited(): void
    {
        $manager = $this->peerManager();
        $manager->setReceiveFrameLimiter(null);
        $this->setMasterPeerId($manager, 'peer-master-5');

        self::assertTrue($manager->guardIncomingFrame('frame'));
    }

    /**
     * Vacuity arm 3 — no dialed peer identity: the guard cannot key a bucket
     * meaningfully, so it passes the frame (belt for pre-ceremony/hand-built
     * states) and never touches a limiter.
     */
    public function testUnsetMasterPeerIdPassesFramesWithoutHits(): void
    {
        $hits = [];
        $limiter = new class ($hits) implements \Phlix\Hub\Common\RateLimit\RateLimiterInterface {
            /** @param list<string> $hits */
            public function __construct(public array $hits)
            {
            }

            public function hit(string $key): \Phlix\Hub\Common\RateLimit\RateLimitState
            {
                $this->hits[] = $key;

                return new \Phlix\Hub\Common\RateLimit\RateLimitState(1, 1, time() + 60, true, 1);
            }

            public function reset(string $key): void
            {
            }

            public function peek(string $key): \Phlix\Hub\Common\RateLimit\RateLimitState
            {
                return new \Phlix\Hub\Common\RateLimit\RateLimitState(0, 1, 0, true, 1);
            }
        };

        $manager = $this->peerManager();
        $manager->setReceiveFrameLimiter($limiter);
        // masterPeerId left '' (never dialed).

        self::assertTrue($manager->guardIncomingFrame('frame'));
        self::assertSame([], $limiter->hits);
    }

    // ------------------------------------------------------------- helpers

    /**
     * The setter stores the SAME limiter object (identity, not copy) — the
     * shared-instance law of the :8805 surface: a per-role `new RateLimiter`
     * would double the effective budget with two counters over one key space.
     */
    public function testInstalledLimiterIsRetainedByIdentity(): void
    {
        $limiter = new RateLimiter(60, 120);
        $manager = $this->peerManager();
        $manager->setReceiveFrameLimiter($limiter);

        $stored = (new ReflectionProperty(FederationPeerManager::class, 'receiveFrameLimiter'))
            ->getValue($manager);

        self::assertSame($limiter, $stored);
    }

    private function peerManager(): FederationPeerManager
    {
        return new FederationPeerManager(
            $this->hubRepo,
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(AuditLogger::class),
            new Ed25519KeyManager($this->keyDir . '/ed25519.pem'),
            static fn (): bool => true,
        );
    }

    private function setMasterPeerId(FederationPeerManager $manager, string $peerId): void
    {
        (new ReflectionProperty(FederationPeerManager::class, 'masterPeerId'))
            ->setValue($manager, $peerId);
    }

    private function setMasterConnection(FederationPeerManager $manager, AsyncTcpConnection $conn): void
    {
        (new ReflectionProperty(FederationPeerManager::class, 'masterConnection'))
            ->setValue($manager, $conn);
    }

    private function readProp(FederationPeerManager $manager, string $name): mixed
    {
        return (new ReflectionProperty(FederationPeerManager::class, $name))->getValue($manager);
    }

    /** @return list<array{interval: float, callback: callable}> */
    private function armedTimers(): array
    {
        /** @var array<int, array<int, array{0: callable, 1: array<array-key, mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = (new ReflectionProperty(Timer::class, 'tasks'))->getValue();

        $armed = [];
        foreach ($tasks as $bucket) {
            foreach ($bucket as $task) {
                $armed[] = ['interval' => (float) $task[3], 'callback' => $task[0]];
            }
        }

        return $armed;
    }
}
