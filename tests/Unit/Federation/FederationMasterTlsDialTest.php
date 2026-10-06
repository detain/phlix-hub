<?php

/**
 * REAL-TLS loopback E2E for the https-master federation dial.
 *
 * What this proves and why it is process-level: the seam under test is
 * FederationPeerManager's ws://-URI + `transport = 'ssl'` + strict-context
 * construction (buildMasterDialPlan → establishConnection). Trusting a fake
 * TLS peer would make the entire point — SNI, peer_name and verify_peer
 * actually biting through the seam — untestable. So the MASTER side runs as a
 * real workerman `websocket://` listener with `$worker->transport = 'ssl'`
 * and a throwaway-CA certificate (tests/Support/Federation/), launched as a
 * child process, exactly like the established Alexa TlsProbeServer pattern.
 * The venue demonstrably supports this (real openssl handshake + WS upgrade +
 * text/binary frames both ways on loopback with verify_peer TRUE).
 *
 * Positive leg: the REAL FederationPeerManager dials `https://localhost:<port>`,
 * the strict plan (cafile = pinned test CA, peer_name = localhost) completes
 * TLS + upgrade, the genuine H-4 Ed25519 ceremony runs over the encrypted
 * channel, a master-pushed DATA offer is rebased to the local peer FK (M-5)
 * through the real socket, and the leaf's share push rides back DOWN the TLS
 * channel and is captured verbatim by the child.
 *
 * Negative leg (the proof that verification is not decorative): pointing the
 * same manager at the same TLS listener while trusting only an UNRELATED CA
 * must die at the handshake — before any application byte flows — and the
 * backoff chain must survive it (reconnect re-armed, nothing half-verified).
 *
 * @copyright 2026 Phlix
 * @license MIT
 */

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
use Phlix\Hub\Tests\Support\Federation\FederationTlsMaterial;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

/**
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationMasterTlsDialTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private const LEAF_HUB_ID = 'leaf-hub-uuid';
    private const MASTER_HUB_ID = 'master-hub-uuid';
    private const MASTER_PEER_ID = 'peer-tls-master';

    private string $dir = '';

    /** @var array{ca: string, server: string, other_ca: string} Set in setUp. */
    private array $certs;

    private string $leafPubB64 = '';

    private string $masterPubB64 = '';

    private string $masterKeyPem = '';

    private ?string $origCaBundle = null;

    /** @var resource|null */
    private $childProcess = null;

    private string $origStdoutFile = '/dev/null';

    /** Mixed on purpose: `Worker::$outputStream` is an untyped static. */
    private mixed $origOutputStream = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/phlix-hub-fed-tls-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        mkdir($this->dir . '/keys', 0700, true);

        $this->certs = FederationTlsMaterial::generate($this->dir);

        // Master identity: the PARENT creates the keypair file; the child
        // loads the very same PEM — one key, two processes, real signatures.
        $this->masterKeyPem = $this->dir . '/keys/master-ed25519.pem';
        $masterPair = (new Ed25519KeyManager($this->masterKeyPem))->getOrCreateKeyPair();
        $this->masterPubB64 = base64_encode((string) $masterPair['public']);

        $leafPair = (new Ed25519KeyManager($this->dir . '/keys/leaf-ed25519.pem'))->getOrCreateKeyPair();
        $this->leafPubB64 = base64_encode((string) $leafPair['public']);

        // LoggerFactory → a file in the test dir: assertion diagnostics when
        // a leg fails include the log tail.
        LoggerFactory::reset();
        file_put_contents(
            $this->dir . '/logger.php',
            "<?php return ['default' => 'e2e', 'handlers' => ['e2e' => "
            . "['type' => 'stream', 'path' => " . var_export($this->dir . '/leaf.log', true)
            . ", 'level' => 'debug']]];",
        );
        LoggerFactory::init($this->dir . '/logger.php');

        $this->origCaBundle = getenv('PHLIX_FEDERATION_CA_BUNDLE') ?: null;

        // Workerman prints handshake/echo noise via Worker::safeEcho onto
        // STDOUT mid-test — route it into the test dir instead.
        $this->origStdoutFile = Worker::$stdoutFile;
        Worker::$stdoutFile = $this->dir . '/leaf-stdout.log';

        // Worker::$outputStream is initialised by Worker::runAll(), which an
        // in-process client loop never calls — and safeEcho's feof() guard
        // TypeErrors on null (it fired exactly here, on the untrusted-CA
        // handshake error). Provide the real stream the daemon would have.
        $stream = fopen($this->dir . '/leaf-stdout.log', 'a');
        if ($stream === false) {
            throw new RuntimeException('cannot open leaf-stdout.log for the client loop');
        }
        $this->origOutputStream = Worker::$outputStream;
        Worker::$outputStream = $stream;
    }

    protected function tearDown(): void
    {
        Worker::$stdoutFile = $this->origStdoutFile;

        if (is_resource(Worker::$outputStream)) {
            fclose(Worker::$outputStream);
        }
        // Reflection restore, not a plain assignment: the vendor static is
        // untyped and its PRE-test value is legitimately null (runAll was
        // never called in this process) — a typed assignment could not
        // represent "whatever it was".
        (new ReflectionProperty(Worker::class, 'outputStream'))->setValue(null, $this->origOutputStream);

        $this->stopChild();

        if ($this->origCaBundle === null) {
            putenv('PHLIX_FEDERATION_CA_BUNDLE');
        } else {
            putenv('PHLIX_FEDERATION_CA_BUNDLE=' . $this->origCaBundle);
        }

        LoggerFactory::reset();

        // The trait restores Timer::$event and the Worker registries; the
        // loop object itself and globalEvent are this test's responsibility.
        Worker::$globalEvent = null;

        self::removeDir($this->dir);

        parent::tearDown();
    }

    /**
     * Happy path: real https-shaped URL → real TLS listener → signed ceremony
     * → offer rebased over the wire → leaf push captured on the master side.
     */
    public function testHttpsMasterOverRealTlsCompletesCeremonyAndSwapsDataFrames(): void
    {
        $port = FederationTlsMaterial::reservePort();
        $this->spawnChild($port);

        $offers = [];
        $auditCalls = [];
        /** @var FederationPeerManager|null $managerRef */
        $managerRef = null;

        $peerRow = $this->masterPeerRow($port);

        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getHubConfig')->willReturn([
            'id' => self::LEAF_HUB_ID,
            'name' => 'Leaf Hub',
            'role' => 'leaf',
            'is_active' => 1,
            'public_key' => $this->leafPubB64,
        ]);
        $hubRepo->method('getDialablePeers')->willReturn([$peerRow]);
        $hubRepo->method('getPeerById')->willReturnCallback(
            static fn (string $id): ?array => $id === self::MASTER_PEER_ID ? $peerRow : null,
        );
        // The ack's master_hub_id already matches the bound row — a second
        // bind write here would mean the identity leg regressed.
        $hubRepo->expects(self::never())->method('setPeerLeafHubId');

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('registerSession')->willReturn('sess-e2e');

        $libraryShares = $this->createMock(FederationLibraryShareRepository::class);
        $libraryShares->method('handleIncomingOffer')->willReturnCallback(
            function (array $offer) use (&$offers, &$managerRef): void {
                $offers[] = $offer;
                // Answer over the SAME verified channel from inside the
                // inbound handler — a mid-callback send through TLS.
                if (!$managerRef instanceof FederationPeerManager) {
                    self::fail('precondition: manager wired before connect');
                }
                $managerRef->pushLibraryShare('share-out-1', 'lib-out', 'Out', 'read');
            },
        );

        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logHubConnect')->willReturnCallback(
            static function (...$args) use (&$auditCalls): void {
                $auditCalls[] = $args;
            },
        );

        $manager = new FederationPeerManager(
            $hubRepo,
            $sessions,
            $libraryShares,
            $this->createMock(FederationAdminDelegationRepository::class),
            $audit,
            new Ed25519KeyManager($this->dir . '/keys/leaf-ed25519.pem'),
            static fn (): bool => true,
        );
        $managerRef = $manager;

        putenv('PHLIX_FEDERATION_CA_BUNDLE=' . $this->certs['ca']);

        $loop = new Select();
        Worker::$globalEvent = $loop;
        Timer::init($loop);

        $manager->connectToMaster();

        $markerPath = $this->dir . '/leaf-data';
        Timer::add(0.05, static function () use ($markerPath, $loop): void {
            if (file_exists($markerPath)) {
                $loop->stop();
            }
        });
        $watchdogFired = false;
        Timer::add(20.0, static function () use (&$watchdogFired, $loop): void {
            $watchdogFired = true;
            $loop->stop();
        }, [], false);
        $loop->run();

        self::assertFalse(
            $watchdogFired,
            'the full https→TLS ceremony never completed through the real socket: ' . $this->diagnostics(),
        );

        // Offer crossed the wire and got rebased to the LOCAL FK (M-5): the
        // wire peer_id (master hub uuid) must be gone.
        self::assertCount(1, $offers, 'exactly one offer crossed: ' . $this->diagnostics());
        self::assertSame('tls-offer-1', $offers[0]['id'] ?? null);
        self::assertSame(self::MASTER_PEER_ID, $offers[0]['peer_id'] ?? null);
        self::assertSame('lib-tls', $offers[0]['library_id'] ?? null);

        // The leaf's own push rode back DOWN the TLS channel; the child saved
        // the payload frame verbatim.
        $written = json_decode((string) file_get_contents($this->dir . '/leaf-data.json'), true);
        self::assertIsArray($written);
        self::assertSame('share-out-1', $written['shares'][0]['id'] ?? null);
        // M-5 ORIGIN law: outbound offers carry THIS hub's id, not a row id —
        // proven with two independent keypairs and a foreign process.
        self::assertSame(self::LEAF_HUB_ID, $written['shares'][0]['peer_id'] ?? null);

        // The ceremony really flipped the leaf's verified/session state.
        self::assertTrue($manager->isConnected());
        self::assertNotNull($manager->getSessionId());
        self::assertTrue((bool) $this->readProp($manager, 'channelVerified'));

        // Audit trail for the success (logHubConnect(leafId, peerName, ackId, true)).
        self::assertNotEmpty($auditCalls);
        self::assertSame(
            [self::LEAF_HUB_ID, 'tls-master', self::MASTER_HUB_ID, true],
            array_slice($auditCalls[0], 0, 4),
        );

        // Child-side markers: the intended path, nothing on the refusal paths.
        self::assertFileExists($this->dir . '/hello-ok');
        self::assertFileExists($this->dir . '/verified');
        self::assertFileExists($this->dir . '/offer-pushed');
        self::assertFileDoesNotExist($this->dir . '/hello-ok-mismatch');
        self::assertFileDoesNotExist($this->dir . '/auth-rejected');
        self::assertFileDoesNotExist($this->dir . '/pre-verified-data');

        $manager->disconnectFromMaster();
    }

    /**
     * The verification-is-real pin: the identical dial against the identical
     * TLS listener, but trusting a DIFFERENT CA, must die inside the TLS
     * handshake — no hello marker on the master side, no session, no audit
     * success — and the reconnect chain must come up behind the wreckage.
     */
    public function testUntrustedCaKillsTheDialAtTheHandshakeAndReArmsTheChain(): void
    {
        $port = FederationTlsMaterial::reservePort();
        $this->spawnChild($port);

        $peerRow = $this->masterPeerRow($port);

        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getHubConfig')->willReturn([
            'id' => self::LEAF_HUB_ID,
            'name' => 'Leaf Hub',
            'role' => 'leaf',
            'is_active' => 1,
            'public_key' => $this->leafPubB64,
        ]);
        $hubRepo->method('getDialablePeers')->willReturn([$peerRow]);
        $hubRepo->method('getPeerById')->willReturn($peerRow);

        $sessions = $this->createMock(FederationSessionManager::class);
        $libraryShares = $this->createMock(FederationLibraryShareRepository::class);
        $libraryShares->expects(self::never())->method('handleIncomingOffer');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::never())->method('logHubConnect');

        $manager = new FederationPeerManager(
            $hubRepo,
            $sessions,
            $libraryShares,
            $this->createMock(FederationAdminDelegationRepository::class),
            $audit,
            new Ed25519KeyManager($this->dir . '/keys/leaf-ed25519.pem'),
            static fn (): bool => true,
        );

        putenv('PHLIX_FEDERATION_CA_BUNDLE=' . $this->certs['other_ca']);

        $loop = new Select();
        Worker::$globalEvent = $loop;
        Timer::init($loop);

        $manager->connectToMaster();

        // onClose → scheduleReconnect nulls the connection and raises the
        // latch — that pair is the observable "the socket died, the chain
        // lives" state.
        $closed = false;
        Timer::add(0.05, function () use (&$closed, $loop, $manager): void {
            if (!$manager->isConnected() && (bool) $this->readProp($manager, 'reconnectScheduled')) {
                $closed = true;
                $loop->stop();
            }
        });
        $watchdogFired = false;
        Timer::add(8.0, static function () use (&$watchdogFired, $loop): void {
            $watchdogFired = true;
            $loop->stop();
        }, [], false);
        $loop->run();

        self::assertFalse(
            $watchdogFired,
            'the untrusted-CA dial never reached the closed-and-rearmed state: ' . $this->diagnostics(),
        );

        self::assertFalse($manager->isConnected());
        self::assertNull($manager->getSessionId());
        self::assertFalse((bool) $this->readProp($manager, 'channelVerified'));
        self::assertTrue((bool) $this->readProp($manager, 'reconnectScheduled'));
        self::assertSame(5, $this->readProp($manager, 'reconnectDelaySeconds'));

        // Nothing above the TLS layer was ever reached on the master side:
        // the child accepted the TCP connection and saw the handshake die.
        self::assertFileDoesNotExist($this->dir . '/hello-ok');
        self::assertFileDoesNotExist($this->dir . '/verified');
        self::assertFileDoesNotExist($this->dir . '/offer-pushed');

        $manager->disconnectFromMaster();
    }

    // ------------------------------------------------------------------ harness

    /**
     * @return array<string, mixed>
     */
    private function masterPeerRow(int $port): array
    {
        return [
            'id' => self::MASTER_PEER_ID,
            'url' => 'https://localhost:' . $port,
            'name' => 'tls-master',
            'public_key' => $this->masterPubB64,
            'leaf_hub_id' => self::MASTER_HUB_ID,
            'status' => 'pending',
        ];
    }

    private function spawnChild(int $port): void
    {
        $script = dirname(__DIR__, 2) . '/Support/Federation/tls_federation_master_server.php';
        if (!is_file($script)) {
            throw new RuntimeException("TLS federation child script missing at $script");
        }

        $logFile = $this->dir . '/child.log';
        $proc = proc_open(
            [
                'setsid',
                PHP_BINARY,
                $script,
                (string) $port,
                $this->dir,
                $this->certs['server'],
                $this->masterKeyPem,
                $this->leafPubB64,
                self::MASTER_HUB_ID,
                'start',
            ],
            [
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
        );

        if (!is_resource($proc)) {
            throw new RuntimeException('could not spawn the TLS master child process');
        }

        $this->childProcess = $proc;

        $deadline = microtime(true) + 15.0;
        while (!file_exists($this->dir . '/ready')) {
            if (microtime(true) >= $deadline) {
                self::fail('TLS master child never became ready: ' . $this->diagnostics());
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                self::fail('TLS master child died during startup: ' . $this->diagnostics());
            }

            usleep(50_000);
        }
    }

    private function stopChild(): void
    {
        if (!is_resource($this->childProcess)) {
            return;
        }

        $status = proc_get_status($this->childProcess);
        if ($status['running']) {
            // The child was launched through setsid, so its pid doubles as
            // the process-group id: one signal covers the workerman master
            // AND its forked TLS worker.
            posix_kill(-(int) $status['pid'], SIGTERM);

            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                clearstatcache();
                $recheck = proc_get_status($this->childProcess);
                if (!$recheck['running']) {
                    break;
                }

                usleep(50_000);
            }

            $recheck = proc_get_status($this->childProcess);
            if ($recheck['running']) {
                posix_kill(-(int) $recheck['pid'], SIGKILL);
            }
        }

        proc_close($this->childProcess);
        $this->childProcess = null;
    }

    private function diagnostics(): string
    {
        $markers = array_map('basename', glob($this->dir . '/*') ?: []);
        $read = static fn (string $p): string => is_file($p)
            ? substr((string) file_get_contents($p), -1500)
            : '(absent)';

        return sprintf(
            "\nmarkers: %s\n--- child.log ---\n%s\n--- leaf-stdout.log ---\n%s\n--- leaf.log ---\n%s",
            json_encode($markers),
            $read($this->dir . '/child.log'),
            $read($this->dir . '/leaf-stdout.log'),
            $read($this->dir . '/leaf.log'),
        );
    }

    private function readProp(object $object, string $name): mixed
    {
        $prop = new ReflectionProperty($object, $name);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                self::removeDir($entry);
            } else {
                @unlink($entry);
            }
        }

        @rmdir($dir);
        @unlink(sys_get_temp_dir() . '/phlix-federation-tls-' . getmypid() . '.cnf');
    }
}
