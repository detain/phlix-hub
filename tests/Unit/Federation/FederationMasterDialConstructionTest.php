<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Events\Select;
use Workerman\Worker;

/**
 * Guard pins for the https-master dial (2026-10-06 lane).
 *
 * The owner finding: federation to `https://` masters could never connect —
 * the scheme mapped to `wss://` and the vendored workerman v5.2.2 has no
 * `Protocols\Wss`, so the AsyncTcpConnection CONSTRUCTOR threw. The fix maps
 * TLS masters to `ws://` + `transport = 'ssl'` + a strict ssl stream
 * context (buildMasterDialPlan). These pins prove the mapping happens
 * UPSTREAM of construction — a `wss://` URI can no longer reach the
 * constructor from any configured-scheme shape — plus the vendor laws the
 * seam relies on:
 *
 *   1. VENDOR LAW (pinned, not assumed): `wss://` still throws at the
 *      constructor. If workerman ever ships Protocols\Wss this pin goes red
 *      and the seam gets a deliberate review instead of a silent drift.
 *   2. Every plan buildMasterDialPlan can produce CONSTRUCTS cleanly and
 *      carries the intended transport + context into the object.
 *   3. Portless TLS resolves to :443 via the real vendor connect() code,
 *      portless plaintext to :80 — the same numbers the old wss/ws URLs
 *      implied (pinned against the vendor, not re-derived from our prose).
 *   4. Source tripwires: exactly ONE AsyncTcpConnection construction site
 *      for dials in src/, fed from the plan; src contains no quoted
 *      'wss://' literal and no peer-verification weakening.
 *
 * The full behavior proof — SNI + peer_name + verify_peer actually working
 * through this seam over a live TLS socket — lives in
 * {@see FederationMasterTlsDialTest}.
 */
final class FederationMasterDialConstructionTest extends TestCase
{
    private string|false $origCaBundle = false;

    protected function setUp(): void
    {
        $this->origCaBundle = getenv('PHLIX_FEDERATION_CA_BUNDLE');
        putenv('PHLIX_FEDERATION_CA_BUNDLE');
    }

    protected function tearDown(): void
    {
        if ($this->origCaBundle === false) {
            putenv('PHLIX_FEDERATION_CA_BUNDLE');
        } else {
            putenv('PHLIX_FEDERATION_CA_BUNDLE=' . $this->origCaBundle);
        }
    }

    /**
     * Vendor law #1: the defect's mechanism is still live in the vendor —
     * a wss:// URI throws at the CONSTRUCTOR (outside any connect() catch).
     * This is exactly the poison our seam must never hand the constructor.
     */
    public function testVendorLawWssUriThrowsAtConstructor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('class \Protocols\Wss not exist');

        new AsyncTcpConnection('wss://master.invalid/relay/federation/l1');
    }

    /**
     * Vendor law #2 (the positive half): ws:// resolves the real
     * Workerman\Protocols\Ws client class — the protocol the seam leans on
     * to speak WebSocket while TLS rides underneath as a stream transport.
     */
    public function testVendorLawWsUriResolvesTheWsClientProtocol(): void
    {
        $con = new AsyncTcpConnection('ws://master.invalid/relay/federation/l1');

        $protocol = (new ReflectionProperty(AsyncTcpConnection::class, 'protocol'))->getValue($con);
        self::assertIsString($protocol);

        self::assertSame(\Workerman\Protocols\Ws::class, ltrim($protocol, '\\'));
    }

    /**
     * The seam proof proper: for every configured-scheme shape, run the
     * REAL mapping (FederationPeerManager::buildMasterDialPlan) and then the
     * REAL construction expression establishConnection uses. Nothing may
     * throw — the vendor-law poison shape is unreachable through the plan.
     * The constructed object must carry the plan's transport and context.
     */
    public function testEveryMappedPlanConstructsWithoutTouchingTheWssGap(): void
    {
        $configured = [
            'https explicit port' => 'https://master.example.com:8443',
            'https default'       => 'https://master.example.com',
            'http explicit port'  => 'http://master.lan:8080',
            'http default'        => 'http://master.lan',
            'bare host fallback'  => 'master.example.com',
            'operator wrote wss'  => 'wss://master.example.com:8805',
            'ipv4 literal'        => 'https://198.51.100.7:8443',
        ];

        foreach ($configured as $label => $url) {
            $plan = $this->buildPlan($url);

            self::assertStringStartsWith('ws://', $plan['uri'], "$label: uri scheme");
            self::assertStringNotContainsString('wss', $plan['uri'], "$label: no wss reaches the ctor");

            $con = new AsyncTcpConnection($plan['uri'], $plan['socket_context']);
            try {
                if ($plan['transport'] !== '') {
                    $con->transport = $plan['transport'];
                }

                $expectedTls = $plan['transport'] === 'ssl';
                self::assertSame(
                    $expectedTls ? 'ssl' : 'tcp',
                    $con->transport,
                    "$label: transport follows the plan (tcp = untouched default)",
                );

                $context = (new ReflectionProperty(AsyncTcpConnection::class, 'socketContext'))->getValue($con);
                self::assertIsArray($context);
                if ($expectedTls) {
                    $ssl = $context['ssl'] ?? null;
                    self::assertIsArray($ssl);
                    self::assertTrue($ssl['verify_peer'], "$label: verify_peer stays true");
                    self::assertTrue($ssl['verify_peer_name'], "$label: verify_peer_name stays true");
                    self::assertTrue($ssl['SNI_enabled'], "$label: SNI stays on");
                    self::assertSame(
                        parse_url($url, PHP_URL_HOST) ?: $url,
                        $ssl['peer_name'],
                        "$label: peer_name names the configured host, not the ws:// scheme",
                    );
                } else {
                    self::assertSame([], $context, "$label: plaintext carries no stream context");
                }
            } finally {
                $con->destroy();
            }
        }
    }

    /**
     * Vendor law #3: the portless-https URI we emit (`ws://host`) plus
     * transport=ssl resolves to :443 inside the REAL connect() — the same
     * port the old `wss://host` implied — and portless plaintext to :80.
     * Pinned against the vendor because the mapping deliberately does NOT
     * embed the default port itself.
     */
    public function testVendorResolvesSslDefaultPort443AndPlainDefault80(): void
    {
        $prevLoop = Worker::$globalEvent;
        Worker::$globalEvent = new Select();
        try {
            $tls = new AsyncTcpConnection('ws://master.invalid/relay/federation/l1');
            $tls->transport = 'ssl';
            // Swallow CONNECT_FAIL: without this the failed dial would THROW
            // out of emitError (no callback installed) — the port resolution
            // we pin happens before the dial either way.
            $tls->onError = static function (): void {
            };
            $tls->connect();
            self::assertSame(443, self::readCon($tls, 'remotePort'));
            self::assertSame('master.invalid:443', self::readCon($tls, 'remoteAddress'));
            $tls->destroy();

            $plain = new AsyncTcpConnection('ws://master.invalid/relay/federation/l1');
            $plain->onError = static function (): void {
            };
            $plain->connect();
            self::assertSame(80, self::readCon($plain, 'remotePort'));
            self::assertSame('master.invalid:80', self::readCon($plain, 'remoteAddress'));
            $plain->destroy();

            $explicit = new AsyncTcpConnection('ws://master.invalid:8443/relay/federation/l1');
            $explicit->transport = 'ssl';
            $explicit->onError = static function (): void {
            };
            $explicit->connect();
            self::assertSame(8443, self::readCon($explicit, 'remotePort'));
            $explicit->destroy();
        } finally {
            Worker::$globalEvent = $prevLoop;
        }
    }

    /**
     * Tripwire: master dials have EXACTLY ONE AsyncTcpConnection
     * construction site in src/, and it is fed from the dial plan — the
     * mandate "every dial site honors the transport" holds by funnel:
     * connectToMaster (explicit/boot/reconnect ticks) is the only path and
     * it always plans first.
     */
    public function testMasterDialHasExactlyOneConstructionSiteFedByThePlan(): void
    {
        $srcRoot = dirname(__DIR__, 3) . '/src';
        $sites = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcRoot));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            $count = substr_count($contents, 'new AsyncTcpConnection(');
            if ($count > 0) {
                $sites[$file->getPathname()] = $count;
            }
        }

        self::assertSame(
            [
                $srcRoot . '/Federation/FederationPeerManager.php' => 1,
            ],
            $sites,
            'src/ must contain exactly one AsyncTcpConnection construction — the federated master dial.',
        );

        $managerSrc = (string) file_get_contents($srcRoot . '/Federation/FederationPeerManager.php');
        self::assertMatchesRegularExpression(
            '/new AsyncTcpConnection\(\s*\$dialPlan\[.uri.\],\s*\$dialPlan\[.socket_context.\]\s*\)/',
            $managerSrc,
            'The single construction site MUST be fed by buildMasterDialPlan output — not a raw URL.',
        );
        self::assertMatchesRegularExpression(
            '/\$this->masterConnection->transport = \$dialPlan\[.transport.\]/',
            $managerSrc,
            'The transport override must sit at the construction seam.',
        );
    }

    /**
     * Tripwire (strict-verification law), TOKENIZED per the estate's
     * token_get_all law so docblock prose about the retired wss:// shape
     * cannot false-red the pin:
     *
     *   1. No quoted 'wss://' string literal anywhere in src/ — that URI is
     *      the constructor-throw shape; the seam maps upstream of it.
     *   2. In the CLIENT-DIAL file (per the construction-site tripwire,
     *      exactly one exists) no verify_peer=>false / allow_self_signed=>true
     *      / capture_peer_cert off. Scope is deliberate: src/Relay/
     *      RelayWorker.php's verify_peer=false + allow_self_signed=true sit
     *      in the SERVER-side :8802 listener context (we never request a
     *      client certificate; the option is meaningless server-side for
     *      trust) and are NOT a client verification weakening. A future
     *      second client file must extend $clientDialFiles here — and the
     *      construction-site test above goes red the moment one appears
     *      anywhere, forcing that review.
     */
    public function testSrcNeverWritesWssLiteralsOrWeakensVerification(): void
    {
        $srcRoot = dirname(__DIR__, 3) . '/src';
        $clientDialFiles = [
            'Federation/FederationPeerManager.php',
        ];

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcRoot));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($srcRoot) + 1);
            $code = self::codeOnlyContents($file->getPathname());

            if (preg_match("/(['\"])wss:\\/\\/\\1/", $code) === 1) {
                $violations[] = "$relative: quoted wss:// string literal (the constructor-throw shape)";
            }

            if (in_array($relative, $clientDialFiles, true)) {
                if (preg_match("/(['\"])verify_peer\\1\\s*=>\\s*false/", $code) === 1) {
                    $violations[] = "$relative: verify_peer disabled on a client dial";
                }
                if (preg_match("/(['\"])allow_self_signed\\1\\s*=>\\s*true/", $code) === 1) {
                    $violations[] = "$relative: allow_self_signed enabled on a client dial";
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'Strict TLS is law for hub CLIENT dials — see FederationPeerManager::buildMasterDialPlan.',
        );
    }

    /**
     * Source with comments (and inline HTML) replaced by whitespace, so the
     * regexes above can only ever match CODE — never the docblocks that
     * document the retired wss:// defect.
     */
    private static function codeOnlyContents(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * @return array{uri: string, transport: string, socket_context: array<string, mixed>}
     */
    private function buildPlan(string $configured): array
    {
        $manager = new FederationPeerManager(
            $this->createMock(FederationHubRepository::class),
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(AuditLogger::class),
            new Ed25519KeyManager(
                (string) (sys_get_temp_dir() . '/phlix-hub-dial-construction-' . bin2hex(random_bytes(4)) . '.pem'),
            ),
        );

        $method = new ReflectionMethod($manager, 'buildMasterDialPlan');
        $method->setAccessible(true);

        /** @var array{uri: string, transport: string, socket_context: array<string, mixed>} */
        return $method->invoke($manager, $configured, 'leaf-1');
    }

    private static function readCon(AsyncTcpConnection $con, string $name): mixed
    {
        return (new ReflectionProperty(AsyncTcpConnection::class, $name))->getValue($con);
    }
}
