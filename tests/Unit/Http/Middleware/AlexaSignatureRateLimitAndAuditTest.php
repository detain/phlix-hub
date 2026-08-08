<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Alexa\AlexaAccountLink;
use Phlix\Hub\Alexa\AlexaPhrases;
use Phlix\Hub\Alexa\AuditLogAlexaRejectionAuditor;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Hub\AuditLogRepository;
use Phlix\Hub\Http\Controllers\AlexaSkillController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use Phlix\Hub\SyncPlay\PendingCommandPusherInterface;
use Phlix\Hub\Tests\Support\Alexa\AlexaCertificateFixture;
use Phlix\Hub\Tests\Support\Alexa\RecordingAlexaRejectionAuditor;
use Phlix\Hub\Tests\Support\Alexa\RecordingCertChainFetcher;
use Phlix\Hub\Tests\Unit\Http\RouteRegistration\RouteRegistrationContainer;
use Phlix\Hub\Tests\Unit\Http\RouteRegistration\RouteRegistrationTestCase;
use ReflectionClass;
use RuntimeException;

use function gmdate;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function str_repeat;

/**
 * S91 — the three deferrals S90 left behind, each with the control that makes
 * its failure attributable.
 *
 * ## 1. The limiter must answer 429, and must key on the TRUSTED client IP
 *
 * Two independent defects hide here and neither is visible from a status code
 * alone:
 *
 *  - **A 400 instead of a 429.** The limiter runs OUTSIDE the middleware's
 *    fail-closed `try` precisely because a `RateLimitException` thrown inside it
 *    would be swallowed and relabelled `ALEXA_VERIFICATION_ERROR` — a 400. The
 *    caller would then never learn to back off, and the endpoint would look
 *    protected while being a free DoS. Asserting **429**, not merely "rejected",
 *    is what separates the two.
 *  - **Keying on `remoteIp`.** Behind HAProxy the peer is always the proxy, so a
 *    `remoteIp` key collapses every caller on earth into one bucket: one noisy
 *    Echo would 429 everybody. That is invisible to any single-client test. So
 *    two DIFFERENT `X-Forwarded-For` clients arrive behind the SAME trusted peer
 *    and must get INDEPENDENT budgets — and the complementary case (peer NOT
 *    trusted, so the headers are ignored and they legitimately DO share) is
 *    asserted beside it, so "independent" cannot be an accident of the harness.
 *
 * ## 2. Exactly one audit record per rejection — and none for an acceptance
 *
 * "Rejections are audited" and "everything is audited" produce identical logs
 * when you only ever look at a rejection. The accepted request is therefore the
 * succeeding control: it must write NOTHING. The 429 path must also write
 * nothing, because the limiter runs FIRST specifically so a flood cannot amplify
 * into one `audit_logs` INSERT per malicious request.
 *
 * ## 3. Signature-256 only — end to end through the composed router
 *
 * Amazon's legacy `Signature` header is SHA-1. Honouring it would let a caller
 * downgrade the whole gate to a broken hash by choosing which header to send. The
 * check is driven through the REAL composed route table, with a genuinely valid
 * signature, and the two halves share one body and one signature so they cannot
 * drift:
 *
 *  - (i) sent as legacy `Signature` (and no `Signature-256`) → 400
 *        `ALEXA_MISSING_SIGNATURE_HEADER`;
 *  - (ii) the SAME bytes and the SAME signature sent as `Signature-256` →
 *        **accepted**, reaching the controller and returning a 200 Alexa
 *        envelope.
 *
 * (ii) is what makes (i) attributable to the header NAME. Without it, (i) would
 * pass just as happily against a middleware that rejected everything, or against
 * a route that was not wired at all.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 */
final class AlexaSignatureRateLimitAndAuditTest extends RouteRegistrationTestCase
{
    private const VALID_URL = 'https://s3.amazonaws.com/echo.api/echo-api-cert-12.pem';

    /** The HAProxy-shaped front the hub sits behind in these tests. */
    private const TRUSTED_PEER = '10.9.0.1';

    private const CLIENT_A = '198.51.100.7';

    private const CLIENT_B = '198.51.100.8';

    private const USER_AGENT = 'Apache-HttpClient/UNAVAILABLE (Java/1.8.0_112)';

    /** `TRUSTED_PROXIES` is process-global; snapshot it and put it back. */
    private string|false $savedTrustedProxies = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedTrustedProxies = getenv('TRUSTED_PROXIES');
    }

    protected function tearDown(): void
    {
        // Restore BEFORE the parent's teardown so no later test in this process
        // inherits a trusted-proxy list this suite invented.
        if ($this->savedTrustedProxies === false) {
            putenv('TRUSTED_PROXIES');
        } else {
            putenv('TRUSTED_PROXIES=' . $this->savedTrustedProxies);
        }
        parent::tearDown();
    }

    // ==================================================================
    // 1. The limiter
    // ==================================================================

    public function testTheLimiterAnswers429AndKeepsSeparateBudgetsPerTrustedClientIp(): void
    {
        putenv('TRUSTED_PROXIES=' . self::TRUSTED_PEER);

        $auditor = new RecordingAlexaRejectionAuditor();
        // Window 60s, three hits per window: the third hit is the limited one
        // (RateLimiter reports `limited` at count >= max).
        $gate = $this->gate(new RecordingCertChainFetcher(null), new RateLimiter(60, 3, 1000), $auditor);

        // Client A spends its budget. These are unsigned requests, so each one
        // is a 400 from the signature gate — which is what proves the limiter
        // let them THROUGH rather than short-circuiting from the first hit.
        self::assertSame(400, self::statusOf($gate($this->proxied(self::CLIENT_A))));
        self::assertSame(400, self::statusOf($gate($this->proxied(self::CLIENT_A))));

        $limited = $gate($this->proxied(self::CLIENT_A));
        self::assertNotNull($limited);
        self::assertSame(
            429,
            $limited->statusCode,
            'a spent window must answer 429. A 400 here means the RateLimitException was thrown '
            . 'INSIDE the fail-closed catch and got relabelled ALEXA_VERIFICATION_ERROR.',
        );
        self::assertSame(['error' => 'Too Many Requests', 'code' => 'rate_limited'], self::decode($limited));
        self::assertArrayHasKey('Retry-After', $limited->headers);
        self::assertGreaterThan(0, (int) $limited->headers['Retry-After']);

        // A DIFFERENT client, behind the SAME peer. This is the assertion that
        // fails if the bucket key reads `remoteIp`.
        self::assertSame(
            400,
            self::statusOf($gate($this->proxied(self::CLIENT_B))),
            'two Echo devices behind the same proxy must not share a rate-limit budget — '
            . 'keying on the peer address 429s the entire internet at once',
        );

        // The 429 wrote no audit row: the limiter runs before the auditor
        // exactly so a flood cannot amplify into one INSERT per request.
        self::assertSame(
            ['ALEXA_MISSING_CERT_CHAIN_URL', 'ALEXA_MISSING_CERT_CHAIN_URL', 'ALEXA_MISSING_CERT_CHAIN_URL'],
            $auditor->codes(),
            'exactly the three requests that reached verification may be audited — never the 429',
        );
    }

    /**
     * The complementary case, and the proof that the key really is the RESOLVED
     * address rather than "whatever X-Forwarded-For says".
     *
     * With the peer NOT in `TRUSTED_PROXIES` the forwarding headers are
     * attacker-controlled and must be IGNORED — so the two clients legitimately
     * DO share one bucket. A middleware that keyed on the raw leftmost XFF entry
     * would give them separate budgets here, which is the spoof the resolver
     * exists to close.
     */
    public function testAnUntrustedPeerCollapsesForgedForwardedForClientsIntoOneBudget(): void
    {
        putenv('TRUSTED_PROXIES=192.0.2.1');

        $gate = $this->gate(new RecordingCertChainFetcher(null), new RateLimiter(60, 3, 1000));

        self::assertSame(400, self::statusOf($gate($this->proxied(self::CLIENT_A))));
        self::assertSame(400, self::statusOf($gate($this->proxied(self::CLIENT_B))));

        self::assertSame(
            429,
            self::statusOf($gate($this->proxied('198.51.100.9'))),
            'when the peer is not a trusted proxy the forwarding headers must not be able to '
            . 'mint a fresh bucket per forged value',
        );
    }

    // ==================================================================
    // 2. The auditor
    // ==================================================================

    public function testARejectionIsAuditedExactlyOnceAndAnAcceptedRequestIsNotAuditedAtAll(): void
    {
        putenv('TRUSTED_PROXIES=' . self::TRUSTED_PEER);

        $fixture = AlexaCertificateFixture::shared();
        $auditor = new RecordingAlexaRejectionAuditor();
        $gate = $this->gate(
            new RecordingCertChainFetcher($fixture->chain('valid')),
            new RateLimiter(60, 100000, 1000),
            $auditor,
            $fixture->trustedCaBundle(),
        );

        $body = self::body();
        $signature = $fixture->sign($body);

        // CONTROL: a genuinely valid request is ACCEPTED and audits NOTHING.
        // Without this half, "audited on rejection" cannot be told apart from
        // "audited always".
        self::assertNull(
            $gate($this->signed($body, $signature)),
            'control: a correctly signed, fresh, well-chained request must pass the gate',
        );
        self::assertSame(0, $auditor->callCount(), 'an accepted request must write no audit row');

        // Break exactly one thing.
        $unsigned = $this->signed($body, $signature);
        unset($unsigned->headers['SIGNATURE-256']);

        $rejected = $gate($unsigned);
        self::assertSame(400, self::statusOf($rejected));

        self::assertCount(1, $auditor->records, 'one rejection must write exactly one audit row');
        self::assertSame('ALEXA_MISSING_SIGNATURE_HEADER', $auditor->records[0]['code']);
        self::assertSame(
            self::CLIENT_A,
            $auditor->records[0]['clientIp'],
            'the audited address must be the TRUSTED client IP, not the proxy peer',
        );
        self::assertSame(self::USER_AGENT, $auditor->records[0]['userAgent']);
        self::assertSame('amzn1.echo-api.request.s91', $auditor->records[0]['requestId']);

        // A second rejection adds exactly one more — "one per rejection", not
        // "one per process" and not "one per header".
        $gate($unsigned);
        self::assertCount(2, $auditor->records);
    }

    /**
     * The audited `requestId` comes out of a body whose signature has just been
     * REJECTED, so it is attacker-controlled. It is a bound parameter, not an
     * injection sink — the cap exists so a caller cannot write a megabyte per
     * rejection into `audit_logs.context_json`.
     */
    public function testTheAuditedRequestIdIsClampedAndAMissingOneIsNull(): void
    {
        $auditor = new RecordingAlexaRejectionAuditor();
        $gate = $this->gate(new RecordingCertChainFetcher(null), new RateLimiter(60, 100000, 1000), $auditor);

        $huge = $this->proxied(self::CLIENT_A);
        $huge->rawBody = (string) json_encode([
            'request' => ['type' => 'IntentRequest', 'requestId' => str_repeat('R', 5000)],
        ]);
        $gate($huge);

        $recorded = $auditor->records[0]['requestId'];
        self::assertIsString($recorded);
        self::assertSame(
            AlexaSignatureMiddleware::MAX_AUDITED_REQUEST_ID_CHARS,
            mb_strlen($recorded),
            'an unbounded attacker-supplied id would be written verbatim into every audit row',
        );

        // Control: a body carrying no id at all records null rather than an
        // empty string, so "absent" stays distinguishable from "empty".
        $none = $this->proxied(self::CLIENT_B);
        $none->rawBody = '{"request":{"type":"IntentRequest"}}';
        $gate($none);
        self::assertNull($auditor->records[1]['requestId']);
    }

    // ==================================================================
    // 2b. The PRODUCTION auditor, wired into the real gate
    // ==================================================================

    /**
     * The recording auditor above proves the gate CALLS an auditor. It cannot
     * prove the shipped one writes anything, so the production
     * {@see AuditLogAlexaRejectionAuditor} is driven here through the same real
     * gate, with the repository mocked at the SQL boundary.
     *
     * The `audit_logs` argument list is asserted whole, by name, because every
     * field is what an operator later filters on: `success: true` would file a
     * rejection as a success, and an absent `ipAddress` makes the row
     * unattributable.
     */
    public function testTheProductionAuditorWritesOneCorrectlyShapedAuditRow(): void
    {
        putenv('TRUSTED_PROXIES=' . self::TRUSTED_PEER);

        $repository = $this->createMock(AuditLogRepository::class);
        $repository->expects(self::once())
            ->method('log')
            ->with(
                self::equalTo(AuditLogAlexaRejectionAuditor::EVENT),
                self::isNull(),
                self::isNull(),
                self::isNull(),
                self::equalTo('/alexa/skill'),
                self::equalTo('alexa.signature_rejected'),
                self::isFalse(),
                self::equalTo('ALEXA_MISSING_CERT_CHAIN_URL'),
                self::equalTo(self::CLIENT_A),
                self::equalTo(self::USER_AGENT),
                self::equalTo([
                    'code' => 'ALEXA_MISSING_CERT_CHAIN_URL',
                    'detail' => '',
                    'alexa_request_id' => 'amzn1.echo-api.request.s91',
                ]),
            );

        $gate = new AlexaSignatureMiddleware(
            new RecordingCertChainFetcher(null),
            new StructuredLogger('alexa-audit-test', []),
            new RateLimiter(60, 100000, 1000),
            new AuditLogAlexaRejectionAuditor($repository, new StructuredLogger('alexa-audit-test', [])),
        );

        self::assertSame(400, self::statusOf($gate($this->proxied(self::CLIENT_A))));
    }

    /**
     * A database that is down must degrade the RECORD, not the VERDICT.
     *
     * If the auditor threw, the gate's fail-closed `catch (\Throwable)` would
     * swallow it and relabel a precise rejection code as the generic
     * `ALEXA_VERIFICATION_ERROR` — an audit sink corrupting the verdict it is
     * auditing. The control is the test above, where the same gate with a
     * working repository reports the same precise code.
     */
    public function testAFailingAuditSinkCannotChangeTheGatesVerdict(): void
    {
        putenv('TRUSTED_PROXIES=' . self::TRUSTED_PEER);

        $repository = $this->createMock(AuditLogRepository::class);
        $repository->method('log')->willThrowException(new RuntimeException('audit_logs is unreachable'));

        $logger = $this->createMock(StructuredLogger::class);
        // Even the WARNING sink throws: there must be nowhere left for an
        // exception to escape from.
        $logger->method('warning')->willThrowException(new RuntimeException('log handler exploded'));

        $gate = new AlexaSignatureMiddleware(
            new RecordingCertChainFetcher(null),
            new StructuredLogger('alexa-audit-test', []),
            new RateLimiter(60, 100000, 1000),
            new AuditLogAlexaRejectionAuditor($repository, $logger),
        );

        $response = $gate($this->proxied(self::CLIENT_A));

        self::assertSame(400, self::statusOf($response));
        self::assertSame(
            'ALEXA_MISSING_CERT_CHAIN_URL',
            self::decode($response)['code'] ?? null,
            'a broken audit sink must not demote a precise rejection code to ALEXA_VERIFICATION_ERROR',
        );
    }

    /**
     * With no `requestId` in the body the context carries only the two fields
     * that are always known — the key must be ABSENT rather than present-and-null,
     * so a query on `context_json` cannot mistake "not sent" for "sent as null".
     */
    public function testTheAuditContextOmitsTheRequestIdKeyWhenThereIsNoRequestId(): void
    {
        putenv('TRUSTED_PROXIES=' . self::TRUSTED_PEER);

        $repository = $this->createMock(AuditLogRepository::class);
        $repository->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::equalTo(['code' => 'ALEXA_MISSING_CERT_CHAIN_URL', 'detail' => '']),
            );

        $gate = new AlexaSignatureMiddleware(
            new RecordingCertChainFetcher(null),
            new StructuredLogger('alexa-audit-test', []),
            new RateLimiter(60, 100000, 1000),
            new AuditLogAlexaRejectionAuditor($repository, new StructuredLogger('alexa-audit-test', [])),
        );

        $request = $this->proxied(self::CLIENT_A);
        $request->rawBody = '{"request":{"type":"IntentRequest"}}';

        self::assertSame(400, self::statusOf($gate($request)));
    }

    /**
     * An empty client IP is recorded as SQL NULL rather than as `''`, so an
     * unattributable row is distinguishable from one attributed to the empty
     * address. Reached by a request with no peer address and no forwarding
     * headers at all.
     */
    public function testAnUnknownClientIpIsRecordedAsNullRatherThanAnEmptyString(): void
    {
        $repository = $this->createMock(AuditLogRepository::class);
        $captured = null;
        $repository->method('log')->willReturnCallback(
            static function (...$args) use (&$captured): void {
                $captured = $args;
            },
        );

        $auditor = new AuditLogAlexaRejectionAuditor($repository, new StructuredLogger('alexa-audit-test', []));
        $auditor->record('ALEXA_EMPTY_BODY', 'body was empty', '', null, null);

        self::assertIsArray($captured);
        self::assertNull($captured[8], 'ipAddress');
        self::assertNull($captured[9], 'userAgent');
    }

    // ==================================================================
    // 3. Signature-256 only, end to end through the composed router
    // ==================================================================

    /**
     * Both halves in ONE method, sharing one body and one signature, so the
     * rejection and its control can never drift apart.
     */
    public function testTheLegacySha1HeaderIsRejectedEndToEndWhileSignature256IsAccepted(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $router = $this->composedRouterWithARealSkillController($fixture);

        $body = self::launchBody();
        $signature = $fixture->sign($body);

        // (i) The signature is genuine — only the header NAME is the legacy one.
        $legacy = $this->signed($body, $signature);
        unset($legacy->headers['SIGNATURE-256']);
        $legacy->headers['SIGNATURE'] = $signature;

        $refused = $router->dispatch($legacy);

        self::assertSame(
            400,
            $refused->statusCode,
            'the legacy SHA-1 Signature header must not satisfy the gate: honouring it would let a '
            . 'caller downgrade the whole check to a broken hash by choosing which header to send',
        );
        self::assertSame(
            'ALEXA_MISSING_SIGNATURE_HEADER',
            self::decode($refused)['code'] ?? null,
            'a different guard fired than the one under test',
        );

        // (ii) SUCCEEDING CONTROL: the same bytes, the same signature, under the
        // SHA-256 header name — accepted, and reaching the real controller.
        $accepted = $router->dispatch($this->signed($body, $signature));

        self::assertSame(
            200,
            $accepted->statusCode,
            'the identical request under Signature-256 must be ACCEPTED — otherwise the rejection '
            . 'above is attributable to a second defect rather than to the header name',
        );

        $envelope = self::decode($accepted);
        self::assertSame('1.0', $envelope['version'] ?? null);
        self::assertSame(
            AlexaPhrases::CAPABILITY,
            $envelope['response']['outputSpeech']['text'] ?? null,
            'the accepted request must have reached AlexaSkillController, not merely passed the gate',
        );
    }

    // ==================================================================
    // Harness
    // ==================================================================

    /**
     * The gate under test, with real collaborators and no I/O.
     *
     * @param list<string> $caBundlePaths
     */
    private function gate(
        RecordingCertChainFetcher $fetcher,
        RateLimiter $limiter,
        ?RecordingAlexaRejectionAuditor $auditor = null,
        array $caBundlePaths = [],
    ): AlexaSignatureMiddleware {
        return new AlexaSignatureMiddleware(
            $fetcher,
            new StructuredLogger('alexa-limit-test', []),
            $limiter,
            $auditor ?? new RecordingAlexaRejectionAuditor(),
            $caBundlePaths,
        );
    }

    /**
     * The FULL production route table, with a fixture-trusting signature gate
     * and a REAL {@see AlexaSkillController} behind it.
     *
     * The controller's proxy collaborators are reflection-built: a
     * `LaunchRequest` is answered from {@see AlexaPhrases} alone and touches
     * neither of them, and giving them real constructors would drag a database
     * boundary into a test about a header name.
     */
    private function composedRouterWithARealSkillController(AlexaCertificateFixture $fixture): Router
    {
        $gate = $this->gate(
            new RecordingCertChainFetcher($fixture->chain('valid')),
            new RateLimiter(60, 100000, 1000),
            $this->alexaAuditor,
            $fixture->trustedCaBundle(),
        );

        $controller = new AlexaSkillController(
            new AlexaAccountLink($this->jwt, $this->users),
            (new ReflectionClass(ServerProxyController::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ServerListController::class))->newInstanceWithoutConstructor(),
            // S93. A `LaunchRequest` never reaches the pusher, so this stand-in
            // only has to satisfy the type; it reports 0 delivered, which is also
            // what production reports while no client consumes :8804.
            new class implements PendingCommandPusherInterface {
                public function pushPlayMedia(
                    string $userId,
                    string $serverId,
                    string $mediaId,
                    string $title,
                ): int {
                    return 0;
                }
            },
            new StructuredLogger('alexa-e2e-test', []),
            'https://hub.e2e.test',
        );

        $this->container = new RouteRegistrationContainer([
            AuthMiddleware::class => $this->authMiddleware,
            AdminMiddleware::class => $this->adminMiddleware,
            AlexaSignatureMiddleware::class => $gate,
            AlexaSkillController::class => $controller,
        ]);

        return $this->runRegistrar('registerRoutes');
    }

    /** A request arriving through the trusted HAProxy front from `$clientIp`. */
    private function proxied(string $clientIp): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/alexa/skill';
        $request->remoteIp = self::TRUSTED_PEER;
        $request->rawBody = self::body();
        $request->headers = [
            'CONTENT-TYPE' => 'application/json',
            'X-FORWARDED-FOR' => $clientIp,
            'USER-AGENT' => self::USER_AGENT,
        ];

        return $request;
    }

    /** A fully signed request, arriving through the trusted front as client A. */
    private function signed(string $body, string $signature): Request
    {
        $request = $this->proxied(self::CLIENT_A);
        $request->rawBody = $body;
        $request->headers['SIGNATURECERTCHAINURL'] = self::VALID_URL;
        $request->headers['SIGNATURE-256'] = $signature;

        return $request;
    }

    /** An Alexa-shaped `IntentRequest` body with a fresh timestamp. */
    private static function body(): string
    {
        return (string) json_encode([
            'version' => '1.0',
            'request' => [
                'type' => 'IntentRequest',
                'requestId' => 'amzn1.echo-api.request.s91',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'locale' => 'en-GB',
                'intent' => ['name' => 'PhlixTitleRuntimeIntent'],
            ],
        ]);
    }

    /**
     * A `LaunchRequest` — the one shape the end-to-end controller can answer
     * with no database, no relay and no linked account.
     */
    private static function launchBody(): string
    {
        return (string) json_encode([
            'version' => '1.0',
            'request' => [
                'type' => 'LaunchRequest',
                'requestId' => 'amzn1.echo-api.request.s91-launch',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'locale' => 'en-GB',
            ],
        ]);
    }

    private static function statusOf(?Response $response): int
    {
        self::assertNotNull($response, 'expected a response, got an allow');

        return $response->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?Response $response): array
    {
        self::assertNotNull($response);
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
