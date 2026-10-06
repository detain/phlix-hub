<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHandshake;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Tests\Support\FederationFrameAssertions;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Unit tests for {@see FederationPeerManager} leaf-side logic that can be
 * exercised without sockets: dial-URL construction (M-8), the H-4 signed
 * HELLO_ACK verification + HELLO_AUTH proof, incoming-offer rebasing (M-5),
 * the pre-verification DATA gate, and the deliberate-disconnect guard (M-7).
 *
 * H-4 ack tests run on REAL sodium keypairs: a master keypair signs the ack
 * (its public half is stored on the master peer row), and this leaf's real
 * Ed25519KeyManager produces the outbound proof.
 *
 * @phpstan-type DialPlan array{
 *     uri: string,
 *     transport: string,
 *     socket_context: array{
 *         ssl?: array{
 *             verify_peer: bool,
 *             verify_peer_name: bool,
 *             SNI_enabled: bool,
 *             peer_name: string,
 *             cafile?: string
 *         }
 *     }
 * }
 * @psalm-type DialPlan = array{
 *     uri: string,
 *     transport: string,
 *     socket_context: array{
 *         ssl?: array{
 *             verify_peer: bool,
 *             verify_peer_name: bool,
 *             SNI_enabled: bool,
 *             peer_name: string,
 *             cafile?: string
 *         }
 *     }
 * }
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationPeerManagerTest extends TestCase
{
    use FederationFrameAssertions;
    use WorkermanTimerRuntimeControl;

    /**
     * @var FederationHubRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private $hubRepo;

    /**
     * @var FederationSessionManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private $sessions;

    /**
     * @var FederationLibraryShareRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private $libraryShares;

    /**
     * @var FederationAdminDelegationRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private $adminDel;

    /**
     * @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject
     */
    private $audit;

    private FederationPeerManager $manager;

    private ?string $loggerTmp = null;

    private ?string $keyDir = null;

    private Ed25519KeyManager $leafKeyManager;

    /**
     * Test-side "master" keypair (sodium_crypto_sign_keypair output): the
     * private half signs the acks, the public half is stored on the master
     * peer row exactly as an operator would register it.
     *
     * @var non-empty-string
     */
    private string $masterKp;

    private string|false $origCaBundle = false;

    protected function setUp(): void
    {
        parent::setUp();

        // The dial plan reads PHLIX_FEDERATION_CA_BUNDLE at plan time; pin a
        // known-empty env so the strict-default pins are absolute regardless
        // of what the surrounding shell exports, and so a CA-bundle leak from
        // another test can never flip them.
        $this->origCaBundle = getenv('PHLIX_FEDERATION_CA_BUNDLE');
        putenv('PHLIX_FEDERATION_CA_BUNDLE');

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->sessions = $this->createMock(FederationSessionManager::class);
        $this->libraryShares = $this->createMock(FederationLibraryShareRepository::class);
        $this->adminDel = $this->createMock(FederationAdminDelegationRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);

        $this->keyDir = sys_get_temp_dir() . '/phlix-hub-fed-peer-keys-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $this->leafKeyManager = new Ed25519KeyManager($this->keyDir . '/leaf-ed25519.pem');
        $this->masterKp = sodium_crypto_sign_keypair();

        $this->manager = new FederationPeerManager(
            $this->hubRepo,
            $this->sessions,
            $this->libraryShares,
            $this->adminDel,
            $this->audit,
            $this->leafKeyManager,
        );

        // Ack/rebase paths log through LoggerFactory::get(RELAY).
        $this->loggerTmp = sys_get_temp_dir() . '/phlix-hub-fed-peer-' . bin2hex(random_bytes(6));
        mkdir($this->loggerTmp, 0700, true);
        file_put_contents(
            $this->loggerTmp . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->loggerTmp . '/logger.php');

        // Successful ack arms the 15s heartbeat timer; a refused ack arms the
        // reconnect timer. Seed the Workerman registry so Timer::add() takes
        // its succeeding task-table arm instead of throwing outside a real
        // runtime; the trait restores everything after each test.
        $this->forceWorkermanRuntime();
    }

    protected function tearDown(): void
    {
        if ($this->origCaBundle === false) {
            putenv('PHLIX_FEDERATION_CA_BUNDLE');
        } else {
            putenv('PHLIX_FEDERATION_CA_BUNDLE=' . $this->origCaBundle);
        }
        LoggerFactory::reset();
        if ($this->loggerTmp !== null && is_file($this->loggerTmp . '/logger.php')) {
            unlink($this->loggerTmp . '/logger.php');
            rmdir($this->loggerTmp);
        }
        $this->loggerTmp = null;
        if ($this->keyDir !== null) {
            foreach (glob($this->keyDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($this->keyDir);
        }
        $this->keyDir = null;
        parent::tearDown();
    }

    // ---------------------------------------------- buildMasterDialPlan (M-8)

    /**
     * The owner finding this lane closes: an `https://` peer used to map to
     * a `wss://` URI whose CONSTRUCTOR throws (vendored workerman has no
     * Protocols\Wss), so https masters could never connect. The shipped
     * idiom is `ws://` + transport `ssl` + a strict ssl stream context —
     * pinned here at the seam, and proven over a real TLS socket end to end
     * by FederationMasterTlsDialTest.
     */
    public function testHttpsMasterPlanIsWsUriWithSslTransportAndExplicitPort(): void
    {
        $plan = $this->buildPlan('https://master.example.com:8443', 'leaf id');

        self::assertSame('ws://master.example.com:8443/relay/federation/leaf%20id', $plan['uri']);
        self::assertSame('ssl', $plan['transport']);
        self::assertSame(
            [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                    'peer_name' => 'master.example.com',
                ],
            ],
            $plan['socket_context'],
            'The scheme is ws://, so peer_name/SNI must name the real host EXPLICITLY',
        );
    }

    /**
     * Portless https keeps the bare host in the URI — workerman's connect()
     * resolves transport==='ssl' && port 0 to :443 itself (pinned against
     * the vendor in FederationMasterDialConstructionTest).
     */
    public function testHttpsMasterPlanOmitsPortAndLetsSslDefaultTo443(): void
    {
        $plan = $this->buildPlan('https://master.example.com', 'l1');

        self::assertSame('ws://master.example.com/relay/federation/l1', $plan['uri']);
        self::assertSame('ssl', $plan['transport']);
        $ssl = $plan['socket_context']['ssl'] ?? null;
        self::assertIsArray($ssl, 'a TLS plan must carry an ssl stream context');
        self::assertTrue($ssl['verify_peer']);
    }

    /**
     * Plaintext masters are byte-preserved from the pre-fix path: plain
     * ws:// URI, no transport override, no stream context at all.
     */
    public function testHttpMasterPlanIsPlainWsBytePreserved(): void
    {
        $plan = $this->buildPlan('http://master.lan', 'l1');

        self::assertSame('ws://master.lan/relay/federation/l1', $plan['uri']);
        self::assertSame('', $plan['transport']);
        self::assertSame([], $plan['socket_context']);
    }

    /**
     * The legacy secure-by-default fallbacks — bare host AND an
     * operator-written wss:// (which used to be the constructor-throw
     * itself) — both map onto the TLS leg.
     *
     * @return array<array-key, array{0: string, 1: string}>
     */
    public static function tlsFallbackUrlProvider(): array
    {
        return [
            'bare host' => ['master.example.com', 'master.example.com'],
            'literal wss config' => ['wss://master.example.com:8805', 'master.example.com'],
            'foreign scheme' => ['https://198.51.100.7:8443', '198.51.100.7'],
        ];
    }

    /**
     * @param string $configured    configured URL (any non-http scheme)
     * @param string $expectedHost  host the plan must carry into peer_name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tlsFallbackUrlProvider')]
    public function testNonHttpSchemesMapToTlsLegNeverToWssUri(string $configured, string $expectedHost): void
    {
        $plan = $this->buildPlan($configured, 'l1');

        self::assertStringStartsNotWith('wss://', $plan['uri']);
        self::assertStringStartsWith('ws://' . $expectedHost, $plan['uri']);
        self::assertSame('ssl', $plan['transport']);
        $ssl = $plan['socket_context']['ssl'] ?? null;
        self::assertIsArray($ssl, 'a TLS plan must carry an ssl stream context');
        self::assertSame($expectedHost, $ssl['peer_name']);
    }

    /**
     * Trust-anchor seam, default posture: with no PHLIX_FEDERATION_CA_BUNDLE
     * the context carries NO cafile key at all — the wrapper falls back to
     * the php.ini/system store — and every strict flag stays pinned true.
     * The strict-default assertion is byte-exact on purpose: a future
     * "convenience" key (allow_self_signed, crypto_method relaxation,
     * peer_name override) would break this pin and force a deliberate talk.
     */
    public function testTlsContextDefaultsToStrictSystemStoreWithNoCafileKey(): void
    {
        $plan = $this->buildPlan('https://master.example.com', 'l1');

        self::assertSame(
            [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => 'master.example.com',
            ],
            $plan['socket_context']['ssl'] ?? null,
        );
    }

    /**
     * Private-CA operators point the bundle env at a PEM file; the plan
     * threads it through as ssl.cafile WITHOUT touching any strict flag —
     * selecting a trust anchor is not an escape hatch.
     */
    public function testCaBundleEnvSelectsTrustAnchorKeepingVerificationStrict(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'phlix-ca-pin-');
        self::assertIsString($bundle);
        file_put_contents($bundle, "-----BEGIN CERTIFICATE-----\nplaceholder\n");
        try {
            putenv('PHLIX_FEDERATION_CA_BUNDLE=' . $bundle);
            $plan = $this->buildPlan('https://master.example.com', 'l1');

            $ssl = $plan['socket_context']['ssl'] ?? null;
            self::assertIsArray($ssl, 'a TLS plan must carry an ssl stream context');
            self::assertSame($bundle, $ssl['cafile'] ?? null);
            self::assertTrue($ssl['verify_peer']);
            self::assertTrue($ssl['verify_peer_name']);
            self::assertTrue($ssl['SNI_enabled']);
        } finally {
            @unlink($bundle);
        }
    }

    /**
     * Fail-loud law: a configured-but-unreadable bundle aborts the plan at
     * dial time. Silently falling through to the system store would be the
     * dangerous mutation this pin kills — the dial would either confuse
     * later or verify against an unintended trust root.
     */
    public function testUnreadableCaBundleRefusesTheDialLoudly(): void
    {
        $bundle = sys_get_temp_dir() . '/phlix-no-such-ca-' . bin2hex(random_bytes(4)) . '.pem';
        putenv('PHLIX_FEDERATION_CA_BUNDLE=' . $bundle);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHLIX_FEDERATION_CA_BUNDLE');

        $this->buildPlan('https://master.example.com', 'l1');
    }

    /**
     * The exact dial-plan shape via the class-level DialPlan alias: the
     * strict inner ssl shape keeps every flag/peer_name assertion typed, so
     * a future src change that loosens the context reddens here at analysis
     * time, not just at assertion time.
     *
     * @phpstan-return DialPlan
     * @psalm-return DialPlan
     */
    private function buildPlan(string $configured, string $leafHubId): array
    {
        $method = new ReflectionMethod($this->manager, 'buildMasterDialPlan');
        $method->setAccessible(true);

        /**
         * @phpstan-var DialPlan
         * @psalm-var DialPlan
         */
        $plan = $method->invoke($this->manager, $configured, $leafHubId);

        return $plan;
    }

    // ---------------------------- HELLO_ACK verification (M-8 + H-4)

    public function testHelloAckWithoutDialedPeerAuditsFailure(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->audit->expects(self::once())
            ->method('logHubConnect')
            ->with(self::identicalTo('own-leaf-id'), self::identicalTo('master'), self::anything(), false);

        $ack = $this->signedAck(sodium_crypto_sign_secretkey($this->masterKp), 's1', 'master-uuid');
        $this->invokeHandleHelloAck($ack);

        self::assertNull($this->readProperty('sessionId'));
    }

    /**
     * H-4: an ack signed by a key the master peer row does NOT vouch for is
     * not the master's word — refused BEFORE any of its claims (identity
     * binding included) touch state, with audit-fail and backoff.
     */
    public function testHelloAckForgedByForeignKeyIsRefused(): void
    {
        $foreignKp = sodium_crypto_sign_keypair();
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->hubRepo->method('getPeerById')->with('peer-master-1')->willReturn($this->masterPeerRow(
            base64_encode(sodium_crypto_sign_publickey($this->masterKp)),
            '',
        ));
        $this->hubRepo->expects(self::never())->method('setPeerLeafHubId');
        $this->sessions->expects(self::never())->method('registerSession');
        $this->audit->expects(self::once())
            ->method('logHubConnect')
            ->with(self::anything(), self::anything(), self::anything(), false, 'hello_ack_signature_invalid');

        $this->invokeHandleHelloAck($this->signedAck(
            sodium_crypto_sign_secretkey($foreignKp),
            's1',
            'claimed-master-uuid',
        ));

        self::assertNull($this->readProperty('sessionId'));
        self::assertFalse((bool) $this->readProperty('channelVerified'));
    }

    /**
     * H-4: the ceremony is mandatory — a legacy UNSIGNED ack (no nonce /
     * signature fields) is refused outright, never grandfathered.
     */
    public function testHelloAckWithoutProofFieldsIsRefused(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->hubRepo->method('getPeerById')->with('peer-master-1')->willReturn($this->masterPeerRow(
            base64_encode(sodium_crypto_sign_publickey($this->masterKp)),
            'the-real-master-uuid',
        ));
        $this->sessions->expects(self::never())->method('registerSession');
        $this->audit->expects(self::once())
            ->method('logHubConnect')
            ->with(self::anything(), self::anything(), self::anything(), false, 'hello_ack_missing_proof_fields');

        $this->invokeHandleHelloAck(['session_id' => 's1', 'master_hub_id' => 'the-real-master-uuid']);

        self::assertNull($this->readProperty('sessionId'));
    }

    /**
     * M-8: the ack's master_hub_id must match the peer's BOUND identity.
     * A contradicting (but properly signed) ack is refused — never silently
     * adopted.
     */
    public function testHelloAckRejectsContradictingMasterIdentity(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->hubRepo->method('getPeerById')->with('peer-master-1')->willReturn($this->masterPeerRow(
            base64_encode(sodium_crypto_sign_publickey($this->masterKp)),
            'the-real-master-uuid',
        ));
        $this->hubRepo->expects(self::never())->method('setPeerLeafHubId');
        $this->sessions->expects(self::never())->method('registerSession');
        $this->audit->expects(self::once())
            ->method('logHubConnect')
            ->with(self::anything(), self::anything(), self::anything(), false, 'hello_ack_identity_mismatch');

        $this->invokeHandleHelloAck($this->signedAck(
            sodium_crypto_sign_secretkey($this->masterKp),
            's1',
            'IMPOSTOR-uuid',
        ));

        self::assertNull($this->readProperty('sessionId'), 'Refused ack must not adopt the session');
    }

    /**
     * M-8 + H-4: a VALID signed ack binds the reported master identity and
     * the leaf registers its local mirror session under the DIALED peer id —
     * not via the old getConnectedPeers()[0] probe that found nothing while
     * the master row was still 'pending' (C-1(1)/H-2 bootstrap breakage).
     */
    public function testHelloAckBindsIdentityAndRegistersLocalSession(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->hubRepo->method('getPeerById')->with('peer-master-1')->willReturn($this->masterPeerRow(
            base64_encode(sodium_crypto_sign_publickey($this->masterKp)),
            null,
        ));
        $this->hubRepo->expects(self::once())
            ->method('setPeerLeafHubId')
            ->with('peer-master-1', 'master-uuid-77');
        $this->sessions->expects(self::once())
            ->method('registerSession')
            ->with('peer-master-1')
            ->willReturn('local-sess-1');
        $this->audit->expects(self::once())
            ->method('logHubConnect')
            ->with(self::anything(), self::anything(), self::anything(), true);

        $this->invokeHandleHelloAck($this->signedAck(
            sodium_crypto_sign_secretkey($this->masterKp),
            'master-sess-9',
            'master-uuid-77',
        ));

        self::assertSame('master-sess-9', $this->readProperty('sessionId'));
        // Tear down the heartbeat timer the ack armed, so it cannot leak.
        $this->manager->disconnectFromMaster();
    }

    /**
     * H-4 leaf leg: after a verified ack the leaf must immediately answer
     * with HELLO_AUTH proving possession of ITS registered key over the
     * canonical {session_id, nonce, leaf_hub_id} string.
     */
    public function testVerifiedAckTriggersHelloAuthProofFromRegisteredKey(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->hubRepo->method('getPeerById')->with('peer-master-1')->willReturn($this->masterPeerRow(
            base64_encode(sodium_crypto_sign_publickey($this->masterKp)),
            'master-uuid-77',
        ));
        $this->sessions->method('registerSession')->willReturn('local-sess-1');

        // Fake socket that records what the leaf sends.
        $sent = [];
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$sent): bool {
                $sent[] = $data;
                return true;
            },
        );
        $property = new ReflectionProperty($this->manager, 'masterConnection');
        $property->setAccessible(true);
        $property->setValue($this->manager, $conn);

        $ack = $this->signedAck(sodium_crypto_sign_secretkey($this->masterKp), 'master-sess-9', 'master-uuid-77');
        $this->invokeHandleHelloAck($ack);

        self::assertCount(1, $sent, 'Exactly one HELLO_AUTH frame expected');
        /** @var array<string, mixed> $auth */
        $auth = json_decode($sent[0], true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('hub_hello_auth', $auth['type']);
        self::assertSame('own-leaf-id', $auth['leaf_hub_id']);
        self::assertSame('master-sess-9', $auth['session_id']);

        $leafPub = $this->leafKeyManager->getOrCreateKeyPair()['public'];
        self::assertTrue(FederationHandshake::verify(
            FederationHandshake::helloAuthCanonical(
                'master-sess-9',
                self::frameStringField($ack, 'nonce'),
                'own-leaf-id',
            ),
            self::frameStringField($auth, 'signature'),
            base64_encode($leafPub),
        ), 'The proof must verify against the registered public key of this leaf');

        self::assertTrue((bool) $this->readProperty('channelVerified'));
        $this->manager->disconnectFromMaster();
    }

    // -------------------------------------- pre-verification DATA gate (H-4)

    /**
     * H-4 (leaf side): offers/revocations/admin-delegation payloads arriving
     * before the channel is verified are dropped and audited — admin
     * delegation in particular must NEVER grant from an unauthenticated
     * source.
     */
    public function testDataFrameBeforeVerificationIsRefused(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->adminDel->expects(self::never())->method('grant');
        $this->adminDel->expects(self::never())->method('revoke');
        $this->libraryShares->expects(self::never())->method('handleIncomingOffer');
        $this->audit->expects(self::once())
            ->method('logFailedAuth')
            ->with('federation_data_frame_before_verification', self::anything());

        $this->invokeHandleDataFrame(json_encode([
            'user_id' => 'u-1',
            'peer_id' => 'peer-master-1',
            'action' => 'grant',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Gate-off control: with the channel verified, the same admin-delegation
     * payload is processed normally.
     */
    public function testDataFrameAfterVerificationIsProcessed(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $property = new ReflectionProperty($this->manager, 'channelVerified');
        $property->setAccessible(true);
        $property->setValue($this->manager, true);

        $this->adminDel->expects(self::once())->method('grant')
            ->with(self::anything(), 'peer-master-1', 'u-1');
        $this->audit->expects(self::never())->method('logFailedAuth');

        $this->invokeHandleDataFrame(json_encode([
            'user_id' => 'u-1',
            'peer_id' => 'peer-master-1',
            'action' => 'grant',
        ], JSON_THROW_ON_ERROR));
    }

    // --------------------------------------------------- offer rebasing (M-5)

    public function testOfferWithoutDialedPeerIsDropped(): void
    {
        self::assertNull($this->rebase(['id' => 'o1', 'peer_id' => 'x', 'library_id' => 'l1']));
    }

    public function testOfferRebasesToMasterPeerLocalRowId(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-master-1',
            'leaf_hub_id' => 'master-uuid',
        ]);

        $out = $this->rebase(['id' => 'o1', 'peer_id' => 'master-uuid', 'library_id' => 'l1']);

        self::assertNotNull($out);
        self::assertSame('peer-master-1', $out['peer_id']);
    }

    public function testOfferWithContradictingWirePeerIdIsDropped(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-master-1',
            'leaf_hub_id' => 'master-uuid',
        ]);

        self::assertNull($this->rebase(['id' => 'o1', 'peer_id' => 'OTHER-uuid', 'library_id' => 'l1']));
    }

    /**
     * @param array<string, mixed> $offer
     *
     * @return array<string, mixed>|null
     */
    private function rebase(array $offer): ?array
    {
        $method = new ReflectionMethod($this->manager, 'rebaseOfferIdentity');
        $method->setAccessible(true);

        /** @var array<string, mixed>|null $result */
        $result = $method->invoke($this->manager, $offer);
        return $result;
    }

    // ------------------------------------------------ reconnect guard (M-7)

    /**
     * M-7 regression: after a deliberate disconnect, scheduleReconnect must
     * refuse to arm — the close its own onClose handler sees may no longer
     * resurrect the link the operator dropped.
     */
    public function testDeliberateDisconnectSuppressesReconnect(): void
    {
        $this->manager->disconnectFromMaster();

        $schedule = new ReflectionMethod($this->manager, 'scheduleReconnect');
        $schedule->setAccessible(true);
        $schedule->invoke($this->manager);

        self::assertFalse((bool) $this->readProperty('reconnectScheduled'));
        self::assertTrue((bool) $this->readProperty('intentionalDisconnect'));
    }

    // ------------------------------------------- text/binary frame routing (E2E-found)

    /**
     * Regression pin from the real-TLS E2E: the leaf's ONLY text-frame entry
     * point is isTextFrame, and its pre-fix form (`json_decode` assoc=false +
     * array-or-scalar acceptance) classified EVERY JSON-object payload —
     * including the master's hub_hello_ack — as binary, because an object
     * decodes to a stdClass. The whole :8805 leaf handshake was dead on real
     * sockets and invisible to reflection-driven tests. These pins hold the
     * dispatch law itself, without needing a socket.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function frameClassificationProvider(): array
    {
        // Hand-built rather than json_encode: the payload's exact shape is
        // the fixture here, and the literal keeps the provider string-typed.
        $ack = '{"type":"hub_hello_ack","session_id":"sess-1","master_hub_id":"master-1",'
            . '"capabilities":["library_shares","relay"],"nonce":"n","signature":"s"}';

        return [
            'handshake ack object is TEXT' => [$ack, true],
            'bare object is TEXT' => ['{"type":"x"}', true],
            'array is TEXT' => ['[1,2,3]', true],
            'empty payload is BINARY' => ['', false],
            'scalar json is BINARY' => ['5', false],
            'seq-zero DATA frame is BINARY' => ["\x00\x00\x00\x00\x05payload", false],
            'random binary is BINARY' => ["\x01\x02\x7bnot json\x7b", false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameClassificationProvider')]
    public function testIsTextFrameRoutesObjectsToTheTextPath(string $payload, bool $expectedText): void
    {
        $method = new ReflectionMethod($this->manager, 'isTextFrame');
        $method->setAccessible(true);

        self::assertSame($expectedText, $method->invoke($this->manager, $payload));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function masterPeerRow(string $publicKeyB64, ?string $leafHubId): array
    {
        return [
            'id' => 'peer-master-1',
            'name' => 'Master',
            'leaf_hub_id' => $leafHubId,
            'public_key' => $publicKeyB64,
        ];
    }

    /**
     * Build a HELLO_ACK array whose signature is made over the canonical
     * string with $secret64 — pass a foreign secret to forge.
     *
     * @return array<string, mixed>
     */
    private function signedAck(string $secret64, string $sessionId, string $masterHubId): array
    {
        $nonce = FederationHandshake::newNonce();

        return [
            'type' => 'hub_hello_ack',
            'session_id' => $sessionId,
            'master_hub_id' => $masterHubId,
            'role' => 'master',
            'nonce' => $nonce,
            'signature' => FederationHandshake::sign(
                FederationHandshake::helloAckCanonical($sessionId, $masterHubId, $nonce),
                $secret64,
            ),
        ];
    }

    private function setMasterPeerId(string $peerId): void
    {
        $property = new ReflectionProperty($this->manager, 'masterPeerId');
        $property->setAccessible(true);
        $property->setValue($this->manager, $peerId);
    }

    private function readProperty(string $name): mixed
    {
        $property = new ReflectionProperty($this->manager, $name);
        $property->setAccessible(true);

        return $property->getValue($this->manager);
    }

    /**
     * @param array<string, mixed> $msg
     */
    private function invokeHandleHelloAck(array $msg): void
    {
        $method = new ReflectionMethod($this->manager, 'handleHelloAck');
        $method->setAccessible(true);
        $method->invoke($this->manager, $msg);
    }

    private function invokeHandleDataFrame(string $payload): void
    {
        $method = new ReflectionMethod($this->manager, 'handleDataFrame');
        $method->setAccessible(true);
        $method->invoke($this->manager, $payload);
    }
}
