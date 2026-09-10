<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\SyncPlay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Websocket;

use function hash;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * Unit tests for {@see SyncPlayRelayWorker} — the SyncPlay relay path (:8804).
 *
 * Covers the three HB-3.2 acceptance criteria:
 *   1. auth-required   — an unauthenticated/bad-token connect is CLOSED.
 *   2. ownership-scope  — two authenticated clients on DIFFERENT (server_id,
 *      owner) that pick the SAME friendly room name do NOT share a room; a
 *      playback broadcast from one is NEVER delivered to the other. (This is
 *      the security guard; it FAILS against the pre-fix raw-key code.)
 *   3. legitimate-flow — two authenticated clients on the SAME server/owner in
 *      the same room DO share it and receive each other's playback control.
 *
 * The worker authenticates in `onWebSocketConnect` from the relay token carried
 * on the upgrade request's `Authorization: Bearer` header or its
 * `Sec-WebSocket-Protocol: bearer, <token>` subprotocol — extracted by the
 * SHARED {@see ClientRelayWorker::extractClientToken()} — then validates it via
 * {@see ClientRelayTokenService} and re-confirms ownership via
 * {@see ServerInfoHandler}, mirroring {@see ClientRelayWorker}.
 *
 * ⚠ S237: these tests previously authenticated by writing `$_GET['token']` by
 * hand. That made them GREEN against production code that could never work —
 * Workerman 5 never populates `$_GET`, so the real `:8804` connect path read
 * `null` every time and rejected every client. The upgrade request is now built
 * from raw request text, so the carrier under test is the carrier in production.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class SyncPlayRelayWorkerTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

    /**
     * S355 — lane token, code-resident, and deliberately used AS the relay token
     * in the negotiation proof below: it is the leak canary. If the hub ever puts
     * the credential in its own 101 response, this exact string shows up in the
     * raw response bytes that test captures, and the test says so out loud.
     */
    private const S355_NEGOTIATION_PROBE_TOKEN = 'S355SUBPROTOX7S6';

    private string $tmpDir;

    /**
     * Map of token_hash (sha256 of the plaintext token) => bound
     * {user_id, server_id} the fake token-lookup query treats as ACTIVE.
     *
     * @var array<string, array{user_id: string, server_id: string}>
     */
    private array $validTokens = [];

    /**
     * Map of server_id => owning user_id used by the fake ServerInfoHandler.
     *
     * @var array<string, string>
     */
    private array $serverOwners = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Process-global static room/client maps: reset so a prior test cannot
        // leak state into this one.
        SyncPlayRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-syncplay-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        // Point the static LoggerFactory at an in-memory stream so tests do not
        // write real log files or emit output.
        $loggerConfig = $this->tmpDir . '/logger.php';
        file_put_contents(
            $loggerConfig,
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($loggerConfig);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SyncPlayRelayWorker::reset();
        LoggerFactory::reset();

        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ---- AC1: auth-required ----------------------------------------------

    public function testConnectWithNoTokenIsRejected(): void
    {
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $request = $this->makeUpgradeRequest('/syncplay/server-a');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('close')->with('', true);

        $worker->onWebSocketConnect($connection, $request);

        // No client should have been registered for an unauthenticated connect.
        self::assertSame(0, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    public function testConnectWithInvalidTokenIsRejected(): void
    {
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $request = $this->makeUpgradeRequest('/syncplay/server-a', 'never-minted');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('close')->with('', true);

        $worker->onWebSocketConnect($connection, $request);

        self::assertSame(0, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    /**
     * S237 — a token presented in the QUERY STRING must NOT authenticate.
     *
     * This is the behavioural half of the S237 pin (the source-level half is
     * `RelayWorkerQueryStringCredentialTest`, which holds the whole worker set).
     * The token used here is genuinely VALID: it is minted, bound to this server
     * and this owner, and the very same string authenticates in
     * `testTheSanctionedCarriersAuthenticate()` below. The ONLY difference is
     * where it rides. So a green here means "the query carrier is refused",
     * not "this token was bad" — the two stories are separated by construction.
     */
    public function testATokenInTheQueryStringDoesNotAuthenticate(): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $request = $this->makeUpgradeRequest('/syncplay/server-a', 'token-a', 'query');

        // Control: the query string really does carry the token — otherwise this
        // test would pass simply because nothing was presented at all.
        self::assertSame('token-a', $request->get('token'), 'control: token is in the query string');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('close')->with('', true);

        $worker->onWebSocketConnect($connection, $request);

        self::assertSame(
            0,
            SyncPlayRelayWorker::getActiveConnectionCount(),
            'S237: a relay token in the query string authenticated a SyncPlay client',
        );
    }

    /**
     * S237 — both sanctioned carriers authenticate, using the SAME token the
     * query-string case above is refused with.
     *
     * @dataProvider sanctionedCarriers
     */
    public function testTheSanctionedCarriersAuthenticate(string $carrier): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $sink = [];
        $connection = $this->makeRecordingConnection($sink);

        $this->connect($worker, $connection, '/syncplay/server-a', 'token-a', $carrier);

        self::assertSame(
            1,
            SyncPlayRelayWorker::getActiveConnectionCount(),
            "the {$carrier} carrier failed to authenticate a valid relay token",
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sanctionedCarriers(): iterable
    {
        yield 'Authorization: Bearer' => ['header'];
        yield 'Sec-WebSocket-Protocol: bearer' => ['subprotocol'];
    }

    // ---- S355: the 101 must echo the negotiated subprotocol ---------------

    /**
     * S355 — THE NEGOTIATION PROOF, against the REAL Workerman handshake path.
     *
     * The named blind spot this closes: S237 proved the hub could EXTRACT the
     * token from the `Sec-WebSocket-Protocol: bearer, <token>` carrier, and the
     * first S355 test proved the callback pokes a property — but nothing ever
     * proved what the CLIENT actually receives on the wire. RFC 6455 negotiation
     * is a wire behaviour, so it is pinned as one: this test feeds the raw
     * upgrade bytes through Workerman's own `Websocket::dealHandshake()` — the
     * exact function the live `websocket://` protocol calls — and asserts on the
     * raw 101 that dealHandshake composes and writes to the socket
     * (`send($handshakeMessage, true)`), after it has invoked the connect
     * callback and appended `$connection->headers` to the response
     * (vendor/workerman/workerman/src/Protocols/Websocket.php:437-456).
     *
     * Two wire facts must hold for the S298 ui carrier
     * `new WebSocket(url, ['bearer', token])` to open instead of aborting 1006:
     *
     *  1. the 101 carries `Sec-WebSocket-Protocol` naming ONE protocol-id the
     *     client offered (RFC 6455 §4.1/§4.2.2 — the WHATWG WebSocket API fails
     *     the connection outright when the client requested subprotocols and the
     *     server selected none); the hub's protocol is `bearer`, so the echo is
     *     verbatim `bearer`.
     *  2. the 101 NEVER carries the relay token. A credential is not a
     *     protocol-id: echoing it answers the negotiation with a secret, puts
     *     that secret into every response log / middlebox on the path, and is
     *     exactly what the S2b/S237 line of work removed from the QUERY string —
     *     it must not reappear in a RESPONSE header.
     *
     * This FAILS against the pre-fix code, which echoes the token itself
     * (`['Sec-WebSocket-Protocol: ' . $token]`): assertion 1 reddens because the
     * 101 names `S355SUBPROTOX7S6` and not `bearer`, and assertion 2 reddens
     * because the same string is the credential — the canary fires.
     */
    public function testRealHandshakeEchoesTheBearerProtocolIdAndNeverTheToken(): void
    {
        $this->grantToken(self::S355_NEGOTIATION_PROBE_TOKEN, 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $raw101 = $this->captureHandshakeBytes(
            $worker,
            "GET /syncplay/server-a HTTP/1.1\r\n"
            . "Host: hub.example.com\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . 'Sec-WebSocket-Protocol: bearer, ' . self::S355_NEGOTIATION_PROBE_TOKEN . "\r\n"
            . "\r\n",
        );

        // Control: the negotiation ran on a real handshake, i.e. this is the 101
        // a client would parse — not an empty capture passing by absence.
        self::assertStringStartsWith(
            "HTTP/1.1 101 Switching Protocol\r\n",
            $raw101,
            'S355: the Workerman handshake path did not write a 101 — the capture is not the wire.',
        );
        self::assertStringContainsString(
            'Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
            $raw101,
            'S355: control — the 101 must carry the standard accept for the fixed sample key.',
        );

        // (1) the selected protocol-id, exactly as offered, on its own header line.
        self::assertStringContainsString(
            "Sec-WebSocket-Protocol: bearer\r\n",
            $raw101,
            "S355: the 101 did not echo the `bearer` protocol-id the client offered; a strict"
            . " client aborts (1006). Raw 101 as sent to the socket:\n" . $raw101,
        );

        // (2) the credential never rides the response.
        self::assertStringNotContainsString(
            self::S355_NEGOTIATION_PROBE_TOKEN,
            $raw101,
            'S355: the 101 echoed the relay TOKEN as a protocol-id. A credential is not a'
            . ' subprotocol (RFC 6455 §4.2.2) and must never appear in a hub response.'
            . " Raw 101 as sent to the socket:\n" . $raw101,
        );

        // And the client still authenticated (the echo is not bought by rejecting).
        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    /**
     * S355 — a client that authenticated via `Authorization: Bearer` while also
     * offering the `bearer` protocol-id receives the echo: the client DID make
     * that negotiation (the offer is on the upgrade request), so selecting it is
     * legal and required — a WHATWG client that lists protocols and gets none
     * back fails the connection whatever carried the credential.
     */
    public function testRealHandshakeEchoesBearerWhenOfferedAlongsideHeaderAuth(): void
    {
        $this->grantToken(self::S355_NEGOTIATION_PROBE_TOKEN, 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $raw101 = $this->captureHandshakeBytes(
            $worker,
            "GET /syncplay/server-a HTTP/1.1\r\n"
            . "Host: hub.example.com\r\n"
            . "Authorization: Bearer " . self::S355_NEGOTIATION_PROBE_TOKEN . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Protocol: bearer\r\n"
            . "\r\n",
        );

        self::assertStringContainsString("HTTP/1.1 101 Switching Protocol\r\n", $raw101);
        self::assertStringContainsString(
            "Sec-WebSocket-Protocol: bearer\r\n",
            $raw101,
            "S355: `bearer` was offered, so the 101 must select it. Raw 101:\n" . $raw101,
        );
        self::assertStringNotContainsString(
            self::S355_NEGOTIATION_PROBE_TOKEN,
            $raw101,
            'S355: the credential must never ride the 101, whatever carrier authenticated it.'
            . " Raw 101:\n" . $raw101,
        );
    }

    /**
     * S355 — the hub supports exactly one subprotocol. A client offering only
     * protocols the hub does not speak must get NO echo: selecting a protocol-id
     * the client never offered is an RFC 6455 §4.1 violation, and the strict
     * client answers one with the very 1006 this step exists to remove. The
     * connection itself stays up for tolerant clients (relay semantics
     * unchanged); only the negotiation answer is withheld.
     */
    public function testRealHandshakeWithForeignSubprotocolOfferGetsNoEcho(): void
    {
        $this->grantToken(self::S355_NEGOTIATION_PROBE_TOKEN, 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $raw101 = $this->captureHandshakeBytes(
            $worker,
            "GET /syncplay/server-a HTTP/1.1\r\n"
            . "Host: hub.example.com\r\n"
            . "Authorization: Bearer " . self::S355_NEGOTIATION_PROBE_TOKEN . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Protocol: chat, superchat\r\n"
            . "\r\n",
        );

        self::assertStringContainsString("HTTP/1.1 101 Switching Protocol\r\n", $raw101);
        self::assertStringNotContainsString(
            'Sec-WebSocket-Protocol:',
            $raw101,
            'S355: the hub echoed a protocol the client never offered. Raw 101:\n' . $raw101,
        );
        self::assertStringNotContainsString(self::S355_NEGOTIATION_PROBE_TOKEN, $raw101);
    }

    /**
     * S355 — drive the RAW upgrade bytes through Workerman's own handshake
     * composer and return the exact bytes it writes to the socket.
     *
     * Nothing here re-implements the 101: `Websocket::dealHandshake()` is the
     * public static entry the live `websocket://` protocol uses, so the callback
     * fires with a real {@see WorkermanRequest} parsed from the same text, and
     * the returned string is the response a browser would parse. The mock exists
     * only to capture writes and to stand in for the socket the vendor path
     * already validated elsewhere.
     */
    private function captureHandshakeBytes(SyncPlayRelayWorker $worker, string $rawUpgrade): string
    {
        $connection = $this->createMock(TcpConnection::class);

        // What dealHandshake() requires of a live connection: the per-connection
        // frame state it initialises (context) and the callback slot it invokes
        // FIRST (the connection-level handler; the worker-level one is what
        // SyncPlayRelayWorker::start() wires in production, and Application code
        // paths here would only dereference $connection->worker, which the mock
        // has none of).
        $connection->context = new stdClass();
        $relayConnect = static function (TcpConnection $conn, WorkermanRequest $request) use ($worker): void {
            $worker->onWebSocketConnect($conn, $request);
        };
        $connection->onWebSocketConnect = $relayConnect;
        $connection->onWebSocketConnected = static function (): void {
        };

        $written = '';
        $connection->method('send')->willReturnCallback(
            static function (mixed $data, bool $raw = false) use (&$written): bool {
                if (is_string($data)) {
                    $written .= $data;
                }
                return true;
            },
        );

        Websocket::dealHandshake($rawUpgrade, $connection);

        return $written;
    }

    /**
     * S355 — the callback-level half of the negotiation: what the worker puts on
     * `$connection->headers` is exactly the single header line Workerman appends
     * to the 101. The wire-level proof is
     * {@see testRealHandshakeEchoesTheBearerProtocolIdAndNeverTheToken()}, which
     * drives `Websocket::dealHandshake()` itself; this one pins the value so a
     * regression names the property, not just the bytes.
     *
     * ⚠ CORRECTED FROM THE FIRST S355 FIX. The prior implementation echoed the
     * client's TOKEN (`Sec-WebSocket-Protocol: <token>`) and this test asserted
     * it. That is wrong on its own terms: a credential is not a protocol-id, the
     * hub implements no such protocol (§4.2.2 permits only one the server
     * supports), and echoing it republishes a live relay token on the response
     * wire — the exact exposure S2b/S237 removed from the query string. The AC's
     * "single `bearer` token form" is the single RFC 7230 protocol-id `bearer`,
     * not a bearer token. So the echo is `bearer`.
     */
    public function testAuthenticatedSubprotocolConnectEchoesTheNegotiatedSubprotocol(): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $connection = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect(
            $connection,
            $this->makeUpgradeRequest('/syncplay/server-a', 'token-a', 'subprotocol'),
        );

        // The client authenticated (control: the very same token/carrier
        // combination is asserted to authenticate by
        // testTheSanctionedCarriersAuthenticate).
        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount());

        // And the 101 must echo the ONE protocol-id the hub speaks and the
        // client offered — never the credential that rode the same header.
        self::assertSame(
            ['Sec-WebSocket-Protocol: bearer'],
            $connection->headers,
            'S355: the 101 must echo the offered `bearer` protocol-id, and only that.',
        );
        self::assertStringNotContainsString(
            'token-a',
            implode('|', $connection->headers),
            'S355: the relay token must never be echoed as a subprotocol.',
        );
    }

    /**
     * S355 — the echo is a NEGOTIATION answer: a client that authenticated via
     * `Authorization: Bearer` never offered a subprotocol, and echoing one
     * would answer a negotiation the client never made. Strict clients reject
     * a server-selected protocol they did not offer (RFC 6455 §4.1), so this
     * must stay empty or the header carrier (roku/mobile — the S298 wire-proof
     * carrier) would start failing the handshake.
     */
    public function testAuthorizationHeaderConnectDoesNotEchoASubprotocol(): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $connection = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect(
            $connection,
            $this->makeUpgradeRequest('/syncplay/server-a', 'token-a', 'header'),
        );

        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount());
        self::assertSame(
            [],
            $connection->headers,
            'S355: no subprotocol was offered, so none may be echoed',
        );
    }

    /**
     * S355 — a REJECTED connect must not echo anything: the handshake is
     * aborted (close), so no 101 and no subprotocol line may leave the hub.
     */
    public function testRejectedConnectDoesNotEchoASubprotocol(): void
    {
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('close')->with('', true);
        $worker->onWebSocketConnect(
            $connection,
            $this->makeUpgradeRequest('/syncplay/server-a', 'never-minted', 'subprotocol'),
        );

        self::assertSame(0, SyncPlayRelayWorker::getActiveConnectionCount());
        self::assertSame(
            [],
            $connection->headers,
            'S355: a rejected connect must not echo a subprotocol',
        );
    }

    /**
     * S355 — a client that authenticated via `Authorization: Bearer` while
     * offering only protocols the hub does not speak must receive NO echo:
     * RFC 6455 §4.1 forbids selecting a protocol-id the client did not offer,
     * and a strict client answers such an echo with the very 1006 this fix
     * removes.
     *
     * ⚠ CORRECTED FROM THE FIRST S355 FIX, which offered `bearer` here and
     * asserted silence because the authenticated TOKEN was not in the offer
     * list. The gate is not the token — it is whether the client offered the
     * hub's protocol. `bearer` offered alongside header auth DOES get the echo
     * (pinned at the wire level by
     * {@see testRealHandshakeEchoesBearerWhenOfferedAlongsideHeaderAuth()}); a
     * genuinely foreign list must not.
     */
    public function testAuthHeaderWithUnrelatedSubprotocolOfferGetsNoEcho(): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $connection = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect(
            $connection,
            $this->makeUpgradeRequest('/syncplay/server-a', 'token-a', 'header', 'chat, superchat'),
        );

        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount());
        self::assertSame(
            [],
            $connection->headers,
            'S355: the client offered no protocol the hub speaks, so none may be echoed',
        );
    }

    // ---- AC2: ownership scoping (the security guard) ---------------------

    /**
     * Two authenticated clients on DIFFERENT (server_id, owner) that pick the
     * SAME friendly room name must NOT share a room. A playback broadcast from
     * one must never reach the other.
     *
     * This FAILS against the pre-fix raw-key code (`self::$rooms['movie-night']`
     * shared across servers/owners), proving cross-user room join was real.
     */
    public function testDifferentServerOwnerSameRoomNameDoNotShareRoom(): void
    {
        // Client A: owns server-a.
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        // Client B: owns a DIFFERENT server-b (different owner too).
        $this->grantToken('token-b', 'user-b', 'server-b');
        $this->setServerOwner('server-b', 'user-b');

        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $sinkA = [];
        $sinkB = [];
        $connA = $this->makeRecordingConnection($sinkA);
        $connB = $this->makeRecordingConnection($sinkB);

        // Both authenticate + connect on their own server path.
        $this->connect($worker, $connA, '/syncplay/server-a', 'token-a');
        $this->connect($worker, $connB, '/syncplay/server-b', 'token-b');

        // Both join the SAME friendly room name.
        $worker->onMessage($connA, $this->joinFrame('movie-night', 'Alice'));
        $worker->onMessage($connB, $this->joinFrame('movie-night', 'Bob'));

        // They resolve to two DIFFERENT scoped rooms.
        self::assertSame(2, SyncPlayRelayWorker::getActiveRoomCount());

        // A issues a playback control; it must NEVER reach B.
        $worker->onMessage($connA, $this->playFrame());

        self::assertFalse(
            $this->sinkHasType($sinkB, 'playback_play'),
            'cross-(server_id,owner) playback control leaked into another user\'s room',
        );
    }

    // ---- AC3: legitimate flow --------------------------------------------

    /**
     * Two authenticated clients on the SAME server/owner joining the same room
     * DO share it and receive each other's playback control end-to-end.
     */
    public function testSameServerOwnerSameRoomShareAndReceivePlayback(): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->grantToken('token-c', 'user-a', 'server-a'); // same owner, same server
        $this->setServerOwner('server-a', 'user-a');

        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $sinkA = [];
        $sinkC = [];
        $connA = $this->makeRecordingConnection($sinkA);
        $connC = $this->makeRecordingConnection($sinkC);

        $this->connect($worker, $connA, '/syncplay/server-a', 'token-a');
        $this->connect($worker, $connC, '/syncplay/server-a', 'token-c');

        $worker->onMessage($connA, $this->joinFrame('movie-night', 'Alice'));
        $worker->onMessage($connC, $this->joinFrame('movie-night', 'Carol'));

        // Both share ONE scoped room.
        self::assertSame(1, SyncPlayRelayWorker::getActiveRoomCount());

        // C should have been notified of A already present via room_state.
        self::assertTrue($this->sinkHasType($sinkC, 'room_state'));

        // A issues a playback control; C (same room) must receive it.
        $worker->onMessage($connA, $this->playFrame());

        self::assertTrue(
            $this->sinkHasType($sinkC, 'playback_play'),
            'legitimate same-server/owner room did not deliver playback control',
        );
    }

    public function testGroupLeaveEmptiesTheScopedRoomAndCleansUp(): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');

        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $sinkA = [];
        $connA = $this->makeRecordingConnection($sinkA);
        $this->connect($worker, $connA, '/syncplay/server-a', 'token-a');

        $worker->onMessage($connA, $this->joinFrame('movie-night', 'Alice'));
        self::assertSame(1, SyncPlayRelayWorker::getActiveRoomCount());

        // Disconnect removes the client from its room (room becomes empty) and
        // drops the client from the live set (no unbounded static growth).
        $worker->onClose($connA);
        self::assertSame(0, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    // ---- Helpers ---------------------------------------------------------

    private function connect(
        SyncPlayRelayWorker $worker,
        TcpConnection $connection,
        string $path,
        string $token,
        string $carrier = 'header',
    ): void {
        $worker->onWebSocketConnect($connection, $this->makeUpgradeRequest($path, $token, $carrier));
    }

    private function joinFrame(string $room, string $displayName): string
    {
        return (string) json_encode([
            'type' => 'group_join',
            'room' => $room,
            'display_name' => $displayName,
        ]);
    }

    private function playFrame(): string
    {
        return (string) json_encode([
            'type' => 'playback_play',
            'position' => 12.5,
        ]);
    }

    /**
     * @param list<string> $sink
     */
    private function sinkHasType(array $sink, string $type): bool
    {
        foreach ($sink as $raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && ($decoded['type'] ?? null) === $type) {
                return true;
            }
        }
        return false;
    }

    private function grantToken(string $token, string $userId, string $serverId): void
    {
        $this->validTokens[hash('sha256', $token)] = ['user_id' => $userId, 'server_id' => $serverId];
    }

    private function setServerOwner(string $serverId, string $userId): void
    {
        $this->serverOwners[$serverId] = $userId;
    }

    /**
     * Build a mock TcpConnection whose send() appends every JSON frame it is
     * asked to write into $sink (by reference).
     *
     * @param list<string> $sink
     */
    private function makeRecordingConnection(array &$sink): TcpConnection
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('send')->willReturnCallback(
            function (mixed $data) use (&$sink): bool {
                if (is_string($data)) {
                    $sink[] = $data;
                }
                return true;
            },
        );
        return $connection;
    }

    private function buildContainer(): ContainerInterface
    {
        // ClientRelayTokenService is final and cannot be mocked; build a real
        // one over a mock Connection whose query() resolves the token lookup
        // against $validTokens (keyed by the sha256 token_hash param, exactly
        // as production filters).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'SELECT')) {
                    return [];
                }
                $hash = $params['token_hash'] ?? null;
                if (is_string($hash) && isset($this->validTokens[$hash])) {
                    return [$this->validTokens[$hash]];
                }
                return [];
            },
        );
        $tokenService = new ClientRelayTokenService($db);

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getOwnerAndStatus')->willReturnCallback(
            function (string $serverId): ?array {
                if (!isset($this->serverOwners[$serverId])) {
                    return null;
                }
                return [
                    'userId' => $this->serverOwners[$serverId],
                    'status' => 'online',
                    'relayActive' => true,
                ];
            },
        );

        return new class ($tokenService, $serverInfo) implements ContainerInterface {
            public function __construct(
                private readonly ClientRelayTokenService $tokenService,
                private readonly ServerInfoHandler $serverInfo,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ClientRelayTokenService::class => $this->tokenService,
                    ServerInfoHandler::class => $this->serverInfo,
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    ClientRelayTokenService::class,
                    ServerInfoHandler::class,
                ], true);
            }
        };
    }

    /**
     * Build a REAL {@see WorkermanRequest} by parsing a raw upgrade request, so
     * the token travels through the same header parsing production uses.
     *
     * @param string      $path              Request target, query string included.
     * @param string|null $token             Relay token to present, or null for none.
     * @param string      $carrier           `header` (`Authorization: Bearer`),
     *                                       `subprotocol` (`Sec-WebSocket-Protocol`), or
     *                                       `query` (S237 — must NOT authenticate).
     * @param string|null $extraSubprotocol  Extra `Sec-WebSocket-Protocol` value to
     *                                       present ALONGSIDE the chosen carrier (S355 —
     *                                       the both-carrier edge: auth via header while
     *                                       offering an unrelated subprotocol).
     */
    private function makeUpgradeRequest(
        string $path,
        ?string $token = null,
        string $carrier = 'header',
        ?string $extraSubprotocol = null,
    ): WorkermanRequest {
        $headers = [
            'Host' => 'hub.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ];

        if ($token !== null) {
            if ($carrier === 'header') {
                $headers['Authorization'] = 'Bearer ' . $token;
            } elseif ($carrier === 'subprotocol') {
                $headers['Sec-WebSocket-Protocol'] = 'bearer, ' . $token;
            } else {
                // 'query' — the S237 defect shape. Deliberately NOT a header.
                $path .= (str_contains($path, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
            }
        }

        if ($extraSubprotocol !== null) {
            $headers['Sec-WebSocket-Protocol'] = $extraSubprotocol;
        }

        $lines = ["GET {$path} HTTP/1.1"];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = implode("\r\n", $lines) . "\r\n\r\n";

        return new WorkermanRequest($raw);
    }
}
