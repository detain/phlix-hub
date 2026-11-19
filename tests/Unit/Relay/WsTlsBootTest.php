<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Relay\TlsMaterialWatch;
use Phlix\Hub\Relay\WsTlsBoot;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Workerman\Worker;

/**
 * F-11 + F-12 (dupe-scan-1119): the hub's four inbound WebSocket listeners
 * (:8802 relay, :8803 client relay, :8804 SyncPlay, :8805 federation) once
 * built their TLS bind context three ways and ran the ~18-line boot material
 * decision as four near-verbatim copies. This suite pins the collapsed seams:
 *
 *   1. WsTlsBoot::forListener() is the ONE boot decision — non-TLS returns
 *      the empty Workerman context and no watch; TLS with null material
 *      throws the exact per-worker message the four copies threw at the base
 *      tree (byte-pinned below); MISSING material fails fast with the
 *      TlsMaterialWatch missing-message law; exists-but-unreadable degrades
 *      to one loud RELAY-channel warning and still binds (audit §3.2 —
 *      identical policy at all four sites, collapse is pure, never unified
 *      across the missing/unreadable arms);
 *   2. the hardened listen context is byte-identical to the value captured
 *      from the pristine base tree at every site — relayTlsContext(), the
 *      FederationWorker inline literal, and WsTlsContext::forListener() all
 *      produced the same 8-key array, so the SSOT pin below IS the pre-
 *      refactor behavior at all four listeners (no cipher-floor drift);
 *   3. all four workers, constructed through their real start() paths, bind
 *      with exactly that pinned context (adoption proof for F-11/F-12).
 *
 * Policy kept distinct by construction: the required-TLS refusal/posture and
 * the outer catch semantics (Application boot dies vs the federation skip)
 * live OUTSIDE this helper, at each worker's own call site, and are pinned
 * by each surface's posture suite — this file pins only the shared block.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class WsTlsBootTest extends TestCase
{
    use WorkermanTimerRuntimeControl;
    use LoggerFactoryIsolation;

    /** Ports above the ephemeral floor and unique per surface: no bind happens (start() only
     *  constructs), but the listen strings must stay distinguishable in failure output. */
    private const PORT_RELAY = 18802;
    private const PORT_CLIENT = 18803;
    private const PORT_SYNCPLAY = 18804;
    private const PORT_FEDERATION = 18805;

    /** @var array<int, string> Scratch paths removed in tearDown(). */
    private array $scratchFiles = [];

    /** @var array<int, string> Warnings recorded by the seeded relay logger. */
    private array $recordedWarnings = [];

    protected function setUp(): void
    {
        parent::setUp();
        RelayWorker::reset();
        FederationWorker::reset();
        $this->recordedWarnings = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RelayWorker::reset();
        FederationWorker::reset();
        LoggerFactory::reset();
        foreach ($this->scratchFiles as $path) {
            @chmod($path, 0600);
            @unlink($path);
        }
        $this->scratchFiles = [];
    }

    private function scratchPem(string $role): string
    {
        $path = sys_get_temp_dir() . '/phlix-wstlsboot-' . $role . '-' . bin2hex(random_bytes(6)) . '.pem';
        if (file_put_contents($path, "-----BEGIN SCRATCH $role-----\n") === false) {
            self::fail("could not write scratch $role pem to $path");
        }
        $this->scratchFiles[] = $path;

        return $path;
    }

    private function container(): ContainerInterface
    {
        return $this->createMock(ContainerInterface::class);
    }

    /**
     * Pre-memoize a warning-recording StructuredLogger into LoggerFactory on
     * the RELAY channel (same seam as the worker posture suites).
     */
    private function seedRecordingLogger(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('warning')->willReturnCallback(
            /**
             * @param string               $message
             * @param array<string, mixed> $context
             */
            function (string $message, array $context = []): void {
                $this->recordedWarnings[] = $message;
            },
        );

        $cache = new ReflectionProperty(LoggerFactory::class, 'loggers');
        /** @var array<string, StructuredLogger> $current */
        $current = $cache->getValue(null);
        $current[LogChannels::RELAY] = $logger;
        $cache->setValue(null, $current);
    }

    /**
     * The hardened 8-key listen context captured byte-for-byte from the
     * pristine base tree (all three pre-refactor builders — relayTlsContext,
     * the FederationWorker inline literal, WsTlsContext::forListener —
     * produced exactly this; see HUBTLS-before-*.txt lane evidence).
     *
     * @return array<string, mixed>
     */
    private function canonicalHardenedSsl(string $cert, string $key): array
    {
        return [
            'ciphers' => 'ECDHE+AESGCM:ECDHE+CHACHA20:DHE+AESGCM:DHE+CHACHA20:!aNULL:!MD5:!DSS',
            'ecdh_curve' => 'secp384r1',
            'disable_compression' => true,
            'security_level' => 2,
            'local_cert' => $cert,
            'local_pk' => $key,
            'allow_self_signed' => true,
            'verify_peer' => false,
        ];
    }

    /** @return array<string, mixed> the ssl section a constructed Worker binds with */
    private function sslOf(Worker $worker): array
    {
        $prop = new ReflectionProperty(Worker::class, 'socketContext');
        $resource = $prop->getValue($worker);
        self::assertIsResource($resource, 'a TLS bind must carry a real stream context resource');
        $options = stream_context_get_options($resource);
        self::assertIsArray($options['ssl'] ?? null, 'TLS bind must carry an ssl context section');

        /** @var array<string, mixed> $ssl */
        $ssl = $options['ssl'];

        return $ssl;
    }

    // ---- 1. non-TLS path -----------------------------------------------------

    public function testNonTlsReturnsTheEmptyWorkermanContextAndNoWatch(): void
    {
        [$sslContext, $watch] = WsTlsBoot::forListener(
            false,
            null,
            null,
            'relay worker',
            'HUB_RELAY_TLS',
            'relay-ws',
            self::PORT_RELAY,
        );

        // Workerman's Worker::__construct() types $socketContext as a
        // non-nullable array — the plaintext bind must get [], never null.
        self::assertSame([], $sslContext);
        self::assertNull($watch);
    }

    // ---- 2. null material: the four byte-pinned historical messages ----------

    public function testNullMaterialThrowsTheRelayWorkerMessageByteIdent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'TLS enabled for relay worker but HUB_RELAY_TLS_CERT and/or HUB_RELAY_TLS_KEY env vars are not set',
        );

        WsTlsBoot::forListener(
            true,
            null,
            $this->scratchPem('key'),
            'relay worker',
            'HUB_RELAY_TLS',
            'relay-ws',
            self::PORT_RELAY,
        );
    }

    public function testNullMaterialThrowsTheClientRelayWorkerMessageByteIdent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'TLS enabled for client relay worker but HUB_CLIENT_RELAY_TLS_CERT '
                . 'and/or HUB_CLIENT_RELAY_TLS_KEY env vars are not set',
        );

        WsTlsBoot::forListener(
            true,
            null,
            $this->scratchPem('key'),
            'client relay worker',
            'HUB_CLIENT_RELAY_TLS',
            'client-relay-ws',
            self::PORT_CLIENT,
        );
    }

    public function testNullMaterialThrowsTheFederationWorkerMessageByteIdent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'TLS enabled for federation worker but HUB_FEDERATION_TLS_CERT and/or '
                . 'HUB_FEDERATION_TLS_KEY env vars are not set',
        );

        WsTlsBoot::forListener(
            true,
            $this->scratchPem('cert'),
            null,
            'federation worker',
            'HUB_FEDERATION_TLS',
            'federation-ws',
            self::PORT_FEDERATION,
        );
    }

    public function testNullMaterialThrowsTheSyncPlayWorkerMessageByteIdent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'TLS enabled for SyncPlay relay worker but HUB_SYNCPLAY_TLS_CERT '
                . 'and/or HUB_SYNCPLAY_TLS_KEY env vars are not set',
        );

        WsTlsBoot::forListener(
            true,
            $this->scratchPem('cert'),
            null,
            'SyncPlay relay worker',
            'HUB_SYNCPLAY_TLS',
            'syncplay-relay-ws',
            self::PORT_SYNCPLAY,
        );
    }

    // ---- 3. readable material: the canonical hardened context + armed watch --

    public function testReadableMaterialBuildsTheBytePinnedHardenedContext(): void
    {
        $cert = $this->scratchPem('cert');
        $key = $this->scratchPem('key');

        [$sslContext, $watch] = WsTlsBoot::forListener(
            true,
            $cert,
            $key,
            'relay worker',
            'HUB_RELAY_TLS',
            'relay-ws',
            self::PORT_RELAY,
        );

        self::assertSame(['ssl'], array_keys($sslContext));
        // Whole-array equality against the BASE-TREE capture — key order,
        // types, and every literal included (the F-11 drift-risk cure).
        self::assertSame($this->canonicalHardenedSsl($cert, $key), $sslContext['ssl']);
        self::assertInstanceOf(TlsMaterialWatch::class, $watch);
    }

    public function testMissingMaterialFailsFastWithTheWatchMissingMessageLaw(): void
    {
        $missing = '/tmp/phlix-wstlsboot-absent-' . bin2hex(random_bytes(6)) . '.pem';
        $cert = $this->scratchPem('cert');

        try {
            WsTlsBoot::forListener(
                true,
                $missing,
                $cert,
                'federation worker',
                'HUB_FEDERATION_TLS',
                'federation-ws',
                self::PORT_FEDERATION,
            );
            self::fail('MISSING material must fail fast before any bind');
        } catch (InvalidArgumentException $e) {
            // Same builder the four copies delegated to — equality against
            // the SSOT message is the byte-identity proof for this leg.
            self::assertSame(
                TlsMaterialWatch::missingMessage(
                    'HUB_FEDERATION_TLS_CERT',
                    $missing,
                    'federation worker',
                    'HUB_FEDERATION_TLS_CERT',
                    'HUB_FEDERATION_TLS_KEY',
                    'HUB_FEDERATION_TLS',
                ),
                $e->getMessage(),
            );
        }
    }

    // ---- 4. exists-but-unreadable: degrade-and-shout, never throw ------------

    public function testUnreadableMaterialDegradesToLoudBootWarningNotThrow(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped(
                'root reads 0000 files; the unreadable arm is proven probe-side in TlsMaterialWatchTest',
            );
        }
        $this->seedRecordingLogger();

        $cert = $this->scratchPem('cert');
        $key = $this->scratchPem('key');
        if (!chmod($key, 0000)) {
            self::markTestSkipped('venue cannot chmod 0000 the scratch key');
        }

        [$sslContext, $watch] = WsTlsBoot::forListener(
            true,
            $cert,
            $key,
            'client relay worker',
            'HUB_CLIENT_RELAY_TLS',
            'client-relay-ws',
            self::PORT_CLIENT,
        );

        // Audit §3.2 law preserved verbatim through the collapse: the bind
        // proceeds, loudly, and the runtime watch is armed.
        self::assertSame($this->canonicalHardenedSsl($cert, $key), $sslContext['ssl']);
        self::assertInstanceOf(TlsMaterialWatch::class, $watch);
        self::assertCount(1, $this->recordedWarnings);
        self::assertStringContainsString('exists and is not readable', $this->recordedWarnings[0]);
        self::assertStringContainsString('HUB_CLIENT_RELAY_TLS_KEY', $this->recordedWarnings[0]);
    }

    // ---- 5. adoption proof: all four listeners bind the pinned context -------

    public function testRelayListenerBindsThePinnedHardenedContext(): void
    {
        $cert = $this->scratchPem('cert');
        $key = $this->scratchPem('key');

        $worker = (new RelayWorker(
            container: $this->container(),
            port: self::PORT_RELAY,
            useTls: true,
            tlsCert: $cert,
            tlsKey: $key,
        ))->start();

        self::assertSame('ssl', $worker->transport);
        self::assertSame($this->canonicalHardenedSsl($cert, $key), $this->sslOf($worker));
    }

    public function testClientRelayListenerBindsThePinnedHardenedContext(): void
    {
        $cert = $this->scratchPem('cert');
        $key = $this->scratchPem('key');

        $worker = (new ClientRelayWorker(
            container: $this->container(),
            port: self::PORT_CLIENT,
            useTls: true,
            tlsCert: $cert,
            tlsKey: $key,
        ))->start();

        self::assertSame('ssl', $worker->transport);
        self::assertSame($this->canonicalHardenedSsl($cert, $key), $this->sslOf($worker));
    }

    public function testSyncPlayListenerBindsThePinnedHardenedContext(): void
    {
        $cert = $this->scratchPem('cert');
        $key = $this->scratchPem('key');

        $worker = (new SyncPlayRelayWorker(
            port: self::PORT_SYNCPLAY,
            count: 1,
            container: $this->container(),
            useTls: true,
            tlsCert: $cert,
            tlsKey: $key,
        ))->start();

        self::assertSame('ssl', $worker->transport);
        self::assertSame($this->canonicalHardenedSsl($cert, $key), $this->sslOf($worker));
    }

    public function testFederationListenerBindsThePinnedHardenedContext(): void
    {
        $cert = $this->scratchPem('cert');
        $key = $this->scratchPem('key');

        $worker = (new FederationWorker(
            container: $this->container(),
            port: self::PORT_FEDERATION,
            useTls: true,
            tlsCert: $cert,
            tlsKey: $key,
        ))->start();

        self::assertSame('ssl', $worker->transport);
        // The inline 8-key literal this site used to hand-roll had NO test
        // pin at the base tree — this assert is the one captured from it.
        self::assertSame($this->canonicalHardenedSsl($cert, $key), $this->sslOf($worker));
    }

    // ---- 6. F-11 retirement: the duplicate builders are gone -----------------

    public function testThePerWorkerDuplicateContextBuildersAreRetired(): void
    {
        self::assertFalse(
            method_exists(RelayWorker::class, 'relayTlsContext'),
            'relayTlsContext() must not come back — the SSOT is WsTlsContext::forListener()',
        );
    }

    public function testNoWorkerFileCarriesAnInlineHardenedLiteral(): void
    {
        $hubRoot = dirname(__DIR__, 3);
        foreach (
            [
                '/src/Relay/RelayWorker.php',
                '/src/Relay/FederationWorker.php',
                '/src/Relay/ClientRelayWorker.php',
                '/src/SyncPlay/SyncPlayRelayWorker.php',
            ] as $relative
        ) {
            $source = file_get_contents($hubRoot . $relative);
            self::assertIsString($source, $relative . ' must be readable for the literal-scan law');
            self::assertStringNotContainsString(
                'ECDHE+AESGCM',
                $source,
                $relative . ' must speak the TLS cipher floor only through the SSOT builder',
            );
        }
    }
}
