<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Alexa;

use Phlix\Hub\Alexa\AlexaMediaGateway;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Shared\Hub\ServerInfoDto;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function parse_str;
use function sort;
use function str_starts_with;

/**
 * S91 — the defects this suite catches in {@see AlexaMediaGateway}.
 *
 * ## Where this suite observes, and why there
 *
 * The thing worth proving is what a PRODUCTION controller is actually handed —
 * not what the gateway thinks it built. {@see ServerProxyController} is `final`,
 * so it cannot be subclassed into a spy, and mocking it would delete exactly the
 * code (ownership gate, traversal gate, browse-scope allowlist) whose inheritance
 * is the entire justification for this class existing.
 *
 * So the observation point is **the collaborators on the far side of the real
 * controller**: a real `ServerProxyController` is built with a real
 * {@see RelayProxyBridge} whose publisher callable records every forwarded
 * request, and with a {@see RelaySessionManager} double whose `checkUserQuota()`
 * receives the user id the controller PROVED. That is a stronger assertion than
 * capturing the `Request` object would be: it shows the values survived the whole
 * production gate chain rather than merely being set on a struct. Where a
 * question is better answered by the returned status (the 403/404 cases), the
 * status is what is asserted.
 *
 * ## The four claims
 *
 * **(a) The user id is the gateway's, never the caller's.** Driven by giving the
 * gateway a user who does NOT own the server: the production ownership gate must
 * answer 403 and nothing must reach the bridge. A gateway that took its identity
 * from anywhere else would have to be handed it, and there is no such path.
 *
 * **(b) Only two server paths are ever reached.** The exact forwarded path is
 * pinned with `assertSame`, so `/api/v1/media/search2` or a widened prefix fails.
 *
 * **(c) No inbound header is forwarded.** This one needs a control, because
 * `ServerProxyController::buildForwardHeaders()` PASSES unstripped inbound
 * headers through — `Signature-256` and `SignatureCertChainUrl` are not on its
 * strip list. So the suite first shows that a request carrying those headers
 * really would forward them, then shows that a gateway call forwards an exact,
 * tiny header set that contains neither. Without the control, "the headers are
 * absent" would be indistinguishable from "the proxy strips them anyway".
 *
 * **(d) A non-200 is surfaced, not swallowed.** 404, 403 and a non-JSON body all
 * come back with their real status rather than being flattened into a cheerful
 * empty success.
 *
 * @package Phlix\Hub\Tests\Unit\Alexa
 */
final class AlexaMediaGatewayTest extends TestCase
{
    use DecodedJsonAssertions;

    private const SERVER_ID = 'srv-alexa-1';

    private const OWNER = 'user-ctor';

    private const CLIENT_IP = '203.0.113.9';

    // ------------------------------------------------------------------
    // (b) the two allowlisted paths, and nothing else
    // ------------------------------------------------------------------

    public function testSearchReachesExactlyTheAllowlistedSearchPath(): void
    {
        $forwarded = [];
        $gateway = $this->gateway($forwarded, self::OWNER, '{"items":[]}');

        $result = $gateway->search(self::SERVER_ID, 'Inception', 5);

        self::assertSame(200, $result['status']);
        self::assertSame(['items' => []], $result['payload']);

        self::assertCount(1, $forwarded, 'exactly one relay request per search');
        self::assertSame('GET', $forwarded[0]['method'], 'the skill reads; it never writes');
        self::assertSame(
            AlexaMediaGateway::SEARCH_PATH,
            $forwarded[0]['path'],
            'the forwarded path must be the allowlisted search route, byte for byte',
        );
        self::assertSame('/api/v1/media/search', $forwarded[0]['path']);
        self::assertSame(self::SERVER_ID, $forwarded[0]['server_id']);
    }

    public function testMediaReachesExactlyTheAllowlistedDetailPath(): void
    {
        $forwarded = [];
        $gateway = $this->gateway($forwarded, self::OWNER, '{"item":{"runtime":148}}');

        $result = $gateway->media(self::SERVER_ID, 'media-42');

        self::assertSame(200, $result['status']);
        self::assertSame(['item' => ['runtime' => 148]], $result['payload']);

        self::assertCount(1, $forwarded);
        self::assertSame('GET', $forwarded[0]['method']);
        self::assertSame('/api/v1/media/media-42', $forwarded[0]['path']);
        self::assertSame('', $forwarded[0]['query'], 'the detail read carries no query string');
    }

    /**
     * A spoken title is free text. `http_build_query()` is what stops
     * `Dune&limit=999` from inventing a second parameter — string concatenation
     * would let a slot value rewrite the request.
     */
    public function testTheSearchTermCannotInventASecondQueryParameter(): void
    {
        $forwarded = [];
        $gateway = $this->gateway($forwarded, self::OWNER, '{"items":[]}');

        $gateway->search(self::SERVER_ID, 'Dune&limit=999&admin=1', 5);

        $parsed = [];
        parse_str(self::stringNode($forwarded[0]['query']), $parsed);

        self::assertSame(
            ['q' => 'Dune&limit=999&admin=1', 'limit' => '5'],
            $parsed,
            'the whole spoken phrase must be ONE q value; the limit must stay the gateway\'s',
        );
    }

    public function testServersReadsTheOwnersServerListAsTheGatewaysUser(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->expects(self::once())
            ->method('getServersForUser')
            ->with(self::OWNER)
            ->willReturn([$this->dto(self::OWNER, true)]);

        $forwarded = [];
        $gateway = new AlexaMediaGateway(
            $this->proxyController($info, $forwarded, '{}'),
            new ServerListController($info),
            self::OWNER,
            self::CLIENT_IP,
        );

        $result = $gateway->servers();

        self::assertSame(200, $result['status']);
        /** @var list<array<string, mixed>> $rows */
        $rows = $result['payload']['servers'];
        self::assertSame([self::SERVER_ID], array_column($rows, 'serverId'));
        self::assertTrue($rows[0]['relayActive']);
        self::assertSame([], $forwarded, 'the server list is not a relay call');
    }

    // ------------------------------------------------------------------
    // (a) identity comes from the constructor, and is load-bearing
    // ------------------------------------------------------------------

    /**
     * The production ownership gate is reached with the CONSTRUCTOR's user id.
     *
     * The succeeding control is the search test above, which runs the identical
     * call with an owner who matches and gets a 200 — so this 403 is
     * attributable to the identity and not to the harness being broken.
     */
    public function testTheGatewaysUserIsTheOneTheOwnershipGateJudges(): void
    {
        $forwarded = [];
        // The server belongs to somebody else; the gateway was built for OWNER.
        $gateway = $this->gateway($forwarded, 'a-different-owner', '{"items":[]}');

        $result = $gateway->search(self::SERVER_ID, 'Inception', 5);

        self::assertSame(403, $result['status']);
        self::assertSame('server.not_owned', $result['payload']['code'] ?? null);
        self::assertSame([], $forwarded, 'nothing may reach the relay for a server the user does not own');
    }

    /**
     * The quota gate — production code, deep inside the controller — sees the
     * constructor's user id. That is the positive form of the same claim.
     */
    public function testTheProvenUserIdReachesTheProductionQuotaGate(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => self::OWNER, 'status' => ServerInfoDto::STATUS_ONLINE, 'relayActive' => true],
        );

        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->expects(self::once())
            ->method('checkUserQuota')
            ->with(self::OWNER, 0)
            ->willReturn(['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0]);

        $forwarded = [];
        $proxy = $this->proxyController($info, $forwarded, '{"items":[]}', $sessions);

        $gateway = new AlexaMediaGateway($proxy, new ServerListController($info), self::OWNER, self::CLIENT_IP);
        $gateway->search(self::SERVER_ID, 'Inception', 5);

        $relayHeaders = self::arrayNode($forwarded[0]['headers']);
        self::assertSame(
            self::OWNER,
            $relayHeaders['X-Phlix-Relay-User'] ?? null,
            'the relayed identity header must carry the gateway\'s own user id',
        );
    }

    // ------------------------------------------------------------------
    // (c) no inbound header travels onward — with its control
    // ------------------------------------------------------------------

    /**
     * CONTROL: the production proxy does NOT strip Amazon's signature headers.
     *
     * This is the assertion that makes the next test mean something. If the
     * proxy stripped them anyway, "the gateway drops them" would be unfalsifiable
     * — the headers would be absent no matter what the gateway did.
     */
    public function testTheProxyItselfWouldHappilyForwardAmazonsSignatureHeaders(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => self::OWNER, 'status' => ServerInfoDto::STATUS_ONLINE, 'relayActive' => true],
        );

        $forwarded = [];
        $proxy = $this->proxyController($info, $forwarded, '{"items":[]}');

        // A request shaped like the INBOUND /alexa/skill one, passed straight
        // through — the thing AlexaMediaGateway deliberately does not do.
        $passthrough = new Request();
        $passthrough->method = 'GET';
        $passthrough->path = '/api/v1/servers/' . self::SERVER_ID . '/proxy/api/v1/media/search';
        $passthrough->queryString = 'q=Inception';
        $passthrough->userId = self::OWNER;
        $passthrough->headers = [
            'ACCEPT' => 'application/json',
            'SIGNATURE-256' => 'a-real-amazon-signature',
            'SIGNATURECERTCHAINURL' => 'https://s3.amazonaws.com/echo.api/echo-api-cert-12.pem',
        ];

        $proxy->proxy($passthrough, ['id' => self::SERVER_ID, 'path' => 'api/v1/media/search']);

        $passthroughHeaders = self::arrayNode($forwarded[0]['headers']);
        self::assertArrayHasKey(
            'SIGNATURE-256',
            $passthroughHeaders,
            'the proxy forwards unstripped inbound headers, so dropping them is the GATEWAY\'s job',
        );
        self::assertArrayHasKey('SIGNATURECERTCHAINURL', $passthroughHeaders);
    }

    public function testTheGatewayForwardsAnExactMinimalHeaderSetAndNoInboundHeader(): void
    {
        $forwarded = [];
        $gateway = $this->gateway($forwarded, self::OWNER, '{"items":[]}');

        $gateway->search(self::SERVER_ID, 'Inception', 5);

        $keys = array_keys(self::arrayNode($forwarded[0]['headers']));
        sort($keys);

        self::assertSame(
            ['ACCEPT', 'X-Forwarded-For', 'X-Phlix-Relay', 'X-Phlix-Relay-User'],
            $keys,
            'the gateway mints its own request: only Accept survives, plus the proxy\'s own trust markers',
        );
        $minimalHeaders = self::arrayNode($forwarded[0]['headers']);
        self::assertSame(self::CLIENT_IP, $minimalHeaders['X-Forwarded-For']);
    }

    // ------------------------------------------------------------------
    // (d) a non-200 is surfaced
    // ------------------------------------------------------------------

    public function testAnUnknownServerSurfacesItsOwn404(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(null);

        $forwarded = [];
        $gateway = new AlexaMediaGateway(
            $this->proxyController($info, $forwarded, '{}'),
            new ServerListController($info),
            self::OWNER,
            self::CLIENT_IP,
        );

        $result = $gateway->media(self::SERVER_ID, 'media-1');

        self::assertSame(404, $result['status']);
        self::assertSame('server.not_found', $result['payload']['code'] ?? null);
    }

    public function testADisconnectedTunnelSurfacesItsOwn503(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => self::OWNER, 'status' => ServerInfoDto::STATUS_ONLINE, 'relayActive' => false],
        );

        $forwarded = [];
        $gateway = new AlexaMediaGateway(
            $this->proxyController($info, $forwarded, '{}'),
            new ServerListController($info),
            self::OWNER,
            self::CLIENT_IP,
        );

        $result = $gateway->search(self::SERVER_ID, 'Inception', 5);

        self::assertSame(503, $result['status']);
        self::assertSame('server.relay_unavailable', $result['payload']['code'] ?? null);
    }

    /**
     * A body that is not JSON keeps its status AND its bytes, under `raw`.
     * Dropping it would hide the server's own error text behind a generic
     * apology, which is the difference between a diagnosable failure and a
     * mystery.
     */
    public function testANonJsonBodyKeepsItsStatusAndItsBytes(): void
    {
        $forwarded = [];
        $gateway = $this->gateway($forwarded, self::OWNER, '<html>gateway exploded</html>', 502);

        $result = $gateway->search(self::SERVER_ID, 'Inception', 5);

        self::assertSame(502, $result['status']);
        self::assertSame(['raw' => '<html>gateway exploded</html>'], $result['payload']);
    }

    /**
     * The streaming backstop in `relay()` is UNREACHABLE from this class's
     * public surface — proved, not asserted in prose.
     *
     * `ServerProxyController::isStreamingPath()` streams only a GET whose path
     * equals, or is a `/`-sub-path of, one of `STREAMING_BODY_PREFIXES`
     * (`/hls`, `/dash`, `/media`). Both gateway paths live under
     * `/api/v1/media/…`, which is not a sub-path of `/media` — the near-miss
     * worth stating out loud, since the two strings share the word. The
     * production `501 alexa.streaming_unsupported` arm therefore cannot be
     * exercised through `search()` or `media()`, and this test says exactly that
     * rather than leaving a silent hole in the coverage report.
     *
     * "Unreachable" is a hypothesis, so it is checked against the production
     * constants by reflection instead of being restated here.
     */
    public function testNeitherGatewayPathCanEverReachTheProxysStreamingFamily(): void
    {
        $prefixes = (new \ReflectionClass(ServerProxyController::class))
            ->getConstant('STREAMING_BODY_PREFIXES');

        self::assertIsArray($prefixes);
        self::assertNotEmpty($prefixes, 'no streaming prefixes were read, so this test measures nothing');

        $gatewayPaths = [
            AlexaMediaGateway::SEARCH_PATH,
            AlexaMediaGateway::MEDIA_PATH_PREFIX . 'media-42',
        ];

        foreach ($gatewayPaths as $path) {
            foreach ($prefixes as $prefix) {
                self::assertIsString($prefix);
                self::assertNotSame($prefix, $path);
                self::assertFalse(
                    str_starts_with($path, $prefix . '/'),
                    'the gateway path ' . $path . ' fell inside the streaming family ' . $prefix
                    . ' — the 501 backstop is now reachable and needs a real test',
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * A gateway wired to a REAL {@see ServerProxyController} whose relay replies
     * with `$replyBody`.
     *
     * @param list<array<string, mixed>> $forwarded  Receives every relayed request.
     * @param string                     $serverOwner Who the server row says owns it.
     */
    private function gateway(
        array &$forwarded,
        string $serverOwner,
        string $replyBody,
        int $replyStatus = 200,
    ): AlexaMediaGateway {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => $serverOwner, 'status' => ServerInfoDto::STATUS_ONLINE, 'relayActive' => true],
        );
        $info->method('getServersForUser')->willReturn([$this->dto($serverOwner, true)]);

        return new AlexaMediaGateway(
            $this->proxyController($info, $forwarded, $replyBody, null, $replyStatus),
            new ServerListController($info),
            self::OWNER,
            self::CLIENT_IP,
        );
    }

    /**
     * The production controller, with a real bridge whose publisher records the
     * forwarded request and answers it synchronously.
     *
     * @param list<array<string, mixed>> $forwarded
     */
    private function proxyController(
        ServerInfoHandler $info,
        array &$forwarded,
        string $replyBody,
        ?RelaySessionManager $sessions = null,
        int $replyStatus = 200,
    ): ServerProxyController {
        if ($sessions === null) {
            $sessions = $this->createMock(RelaySessionManager::class);
            $sessions->method('checkUserQuota')->willReturn(
                ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0],
            );
        }

        $bridge = null;
        $publisher = static function (
            string $event,
            array $data,
        ) use (
            &$bridge,
            &$forwarded,
            $replyBody,
            $replyStatus,
        ): void {
            /** @var array<string, mixed> $data */
            $forwarded[] = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => $replyStatus,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $replyBody,
            ]);
        };
        $bridge = new RelayProxyBridge(new StructuredLogger('alexa-gateway-test', []), $publisher);

        return new ServerProxyController(
            $info,
            $bridge,
            new StructuredLogger('alexa-gateway-test', []),
            $sessions,
            // Generous: this suite asserts routing, not the proxy's own limiter.
            new RateLimiter(60, 100000, 1000),
        );
    }

    private function dto(string $userId, bool $relayActive): ServerInfoDto
    {
        return new ServerInfoDto(
            self::SERVER_ID,
            $userId,
            'Alexa Test Server',
            '1.0.0',
            null,
            ServerInfoDto::STATUS_ONLINE,
            [],
            $relayActive,
        );
    }
}
