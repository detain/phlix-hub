<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Alexa\CertChainFetcherInterface;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Tests\Support\Alexa\AlexaCertificateFixture;
use Phlix\Hub\Tests\Support\Alexa\RecordingAlexaRejectionAuditor;
use Phlix\Hub\Tests\Support\Alexa\RecordingCertChainFetcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_keys;
use function json_decode;
use function json_encode;
use function preg_match_all;
use function sort;
use function str_replace;
use function token_get_all;

use const JSON_THROW_ON_ERROR;

/**
 * The law over {@see AlexaSignatureMiddleware::REJECTION_CODE_MAP} — the
 * channel the wire-law scan CANNOT see.
 *
 * {@see \Phlix\Hub\Tests\Unit\Contracts\ErrorCodesContractTest} statically
 * recognises only literal code channels; `reject()` receives its code through a
 * variable (including `ChainVerification::errorCode()`), so the 14 SCREAMING→
 * dotted flips live on an invisible channel by design. This file closes that
 * gap with three laws and a frame-shape sample:
 *
 *  1. The map is EXACTLY the 14 pairs the registry sanctions (book-keeping
 *     pin — a silent renegade pair goes red here, not on the wire).
 *  2. Every dotted twin is a registered code in the vendored contracts fixture.
 *  3. Anti-drift: every `'ALEXA_*'` string literal in the two source files
 *     that can feed `reject()` is a map KEY, and every key is still produced
 *     by those files — add a new rejection without registering its twin and
 *     this test names it.
 *  4. Representative whole-frame pins (status + exact decoded key order +
 *     byte-exact serialized body) proving the dual-placement frame shape:
 *     legacy literal in `error` TEXT, dotted twin in `code`. The full guard
 *     coverage is {@see AlexaSignatureMiddlewareTest}, whose `assertRejected`
 *     checks BOTH channels for every one of the 14 arms.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 */
final class AlexaRejectionCodeMapLawTest extends TestCase
{
    private const MAP = AlexaSignatureMiddleware::REJECTION_CODE_MAP;

    private const REAL_CHAIN_FIXTURE = __DIR__ . '/../../../fixtures/alexa/amazon-echo-api-cert-12.pem';

    private const VALID_URL = 'https://s3.amazonaws.com/echo.api/echo-api-cert-12.pem';

    // ------------------------------------------------------------------
    // Law 1 — book-keeping pin of the sanctioned pair set
    // ------------------------------------------------------------------

    public function testTheMapIsExactlyTheFourteenRegistrySanctionedPairs(): void
    {
        self::assertSame([
            'ALEXA_VERIFICATION_ERROR' => 'alexa.verification_error',
            'ALEXA_MISSING_CERT_CHAIN_URL' => 'alexa.missing_cert_chain_url',
            'ALEXA_MISSING_SIGNATURE_HEADER' => 'alexa.missing_signature_header',
            'ALEXA_EMPTY_BODY' => 'alexa.empty_body',
            'ALEXA_CERT_URL_REJECTED' => 'alexa.cert_url_rejected',
            'ALEXA_CERT_FETCH_FAILED' => 'alexa.cert_fetch_failed',
            'ALEXA_CERT_CHAIN_MALFORMED' => 'alexa.cert_chain_malformed',
            'ALEXA_SIGNATURE_INVALID' => 'alexa.signature_invalid',
            'ALEXA_CERT_EXPIRED' => 'alexa.cert_expired',
            'ALEXA_CERT_SAN_MISMATCH' => 'alexa.cert_san_mismatch',
            'ALEXA_CERT_CHAIN_UNTRUSTED' => 'alexa.cert_chain_untrusted',
            'ALEXA_TIMESTAMP_MALFORMED' => 'alexa.timestamp_malformed',
            'ALEXA_TIMESTAMP_MISSING' => 'alexa.timestamp_missing',
            'ALEXA_TIMESTAMP_STALE' => 'alexa.timestamp_stale',
        ], self::MAP, 'the reject() mapping table drifted from the registry-sanctioned pair set');
    }

    // ------------------------------------------------------------------
    // Law 2 — every dotted twin is a registered code
    // ------------------------------------------------------------------

    public function testEveryDottedTwinIsRegisteredInTheContractsFixture(): void
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../fixtures/contracts/error-codes.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        $registered = $decoded['codes'] ?? null;
        self::assertIsArray($registered, 'the contracts fixture must expose a codes list');

        foreach (self::MAP as $legacy => $dotted) {
            self::assertStringStartsWith('alexa.', $dotted, $legacy . ' maps outside the alexa domain');
            self::assertContains(
                $dotted,
                $registered,
                'reject() emits ' . $dotted . ', which is not registered in @phlix/contracts v0.5.1',
            );
        }
    }

    // ------------------------------------------------------------------
    // Law 3 — anti-drift: source literals ⇔ map keys
    // ------------------------------------------------------------------

    public function testEveryAlexaLiteralFeedingRejectIsAMapKeyAndEveryKeyIsStillReacheable(): void
    {
        $sources = [
            __DIR__ . '/../../../../src/Http/Middleware/AlexaSignatureMiddleware.php',
            __DIR__ . '/../../../../src/Alexa/ChainVerification.php',
        ];

        $literals = [];
        foreach ($sources as $source) {
            foreach (token_get_all((string) file_get_contents($source)) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $value = str_replace("'", '', $token[1]);
                if (preg_match_all('/^ALEXA_[A-Z_]+$/', $value) === 1) {
                    $literals[$value] = true;
                }
            }
        }

        $found = array_keys($literals);
        sort($found);
        $keys = array_keys(self::MAP);
        sort($keys);

        self::assertSame(
            $keys,
            $found,
            'the ALEXA_* literals in AlexaSignatureMiddleware.php + ChainVerification.php must be'
                . ' exactly the REJECTION_CODE_MAP keys: a new rejection needs a registered dotted twin'
                . ' (added side), and a key nothing produces is stale (removed side)',
        );
    }

    // ------------------------------------------------------------------
    // Law 4 — whole-frame pins of the dual-placement shape (sample)
    // ------------------------------------------------------------------

    public function testMissingCertChainUrlFrameIsDualPlaced(): void
    {
        $request = $this->request(self::body());
        unset($request->headers['SIGNATURECERTCHAINURL']);

        self::assertDualPlacementFrame(
            $this->middleware(new RecordingCertChainFetcher(''))($request),
            'ALEXA_MISSING_CERT_CHAIN_URL',
            'alexa.missing_cert_chain_url',
        );
    }

    public function testCertUrlRejectedFrameIsDualPlaced(): void
    {
        $request = $this->request(self::body(), 'c2ln', 'https://s3.amazonaws.com.evil.test/echo.api/c.pem');

        self::assertDualPlacementFrame(
            $this->middleware(new RecordingCertChainFetcher(''))($request),
            'ALEXA_CERT_URL_REJECTED',
            'alexa.cert_url_rejected',
        );
    }

    public function testEmptyBodyFrameIsDualPlaced(): void
    {
        $request = $this->request('', 'c2ln');

        self::assertDualPlacementFrame(
            $this->middleware(new RecordingCertChainFetcher(''))($request),
            'ALEXA_EMPTY_BODY',
            'alexa.empty_body',
        );
    }

    public function testRecordedAmazonChainExpiryFrameIsDualPlaced(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();
        $middleware = $this->middleware(new RecordingCertChainFetcher(
            (string) file_get_contents(self::REAL_CHAIN_FIXTURE),
        ));

        // Control first: an in-date leaf must pass, so "rejected" is a verdict.
        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')))(
                $this->request($body, $fixture->sign($body)),
            ),
        );

        self::assertDualPlacementFrame(
            $middleware($this->request($body, $fixture->sign($body))),
            'ALEXA_CERT_EXPIRED',
            'alexa.cert_expired',
        );
    }

    public function testStaleTimestampFrameIsDualPlaced(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));
        $stale = self::body(gmdate('Y-m-d\TH:i:s\Z', time() - 600));

        self::assertDualPlacementFrame(
            $middleware($this->request($stale, $fixture->sign($stale))),
            'ALEXA_TIMESTAMP_STALE',
            'alexa.timestamp_stale',
        );
    }

    public function testFailClosedCatchArmFrameIsDualPlaced(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        $exploding = new class implements CertChainFetcherInterface {
            public function fetch(string $url): ?string
            {
                throw new RuntimeException('the network is on fire');
            }
        };

        // The exception detail ("the network is on fire") must NOT reach the
        // wire — the frame is exactly error+code, proving reject() kept the
        // detail log/audit-only through the flip.
        self::assertDualPlacementFrame(
            $this->middleware($exploding)($this->request($body, $fixture->sign($body))),
            'ALEXA_VERIFICATION_ERROR',
            'alexa.verification_error',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Exact WHOLE-FRAME assertion: status, exact decoded key set AND order,
     * and byte-exact serialized body (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).
     */
    private static function assertDualPlacementFrame(?Response $response, string $legacy, string $dotted): void
    {
        self::assertNotNull($response, 'expected a rejection, got an allow');
        self::assertSame(400, $response->statusCode, 'every mapped rejection is a 400');
        self::assertSame(
            ['error' => $legacy, 'code' => $dotted],
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR),
            'the dual-placement frame drifted from {error: legacy, code: dotted}',
        );
        self::assertSame(
            "{\n    \"error\": \"" . $legacy . "\",\n    \"code\": \"" . $dotted . "\"\n}",
            $response->body,
            'the rejection body drifted from contracted serialization',
        );
    }

    private function middleware(CertChainFetcherInterface $fetcher): AlexaSignatureMiddleware
    {
        return new AlexaSignatureMiddleware(
            $fetcher,
            new StructuredLogger('alexa-law-test', []),
            new RateLimiter(60, 100000, 1000),
            new RecordingAlexaRejectionAuditor(),
            AlexaCertificateFixture::shared()->trustedCaBundle(),
        );
    }

    private function request(string $body, string $signature = '', string $url = self::VALID_URL): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/alexa/skill';
        $request->rawBody = $body;
        $request->headers = [
            'CONTENT-TYPE' => 'application/json',
            'SIGNATURECERTCHAINURL' => $url,
            'SIGNATURE-256' => $signature,
        ];

        return $request;
    }

    private static function body(?string $timestamp = null): string
    {
        return (string) json_encode([
            'version' => '1.0',
            'session' => ['sessionId' => 'amzn1.echo-api.session.law-test'],
            'request' => [
                'type' => 'IntentRequest',
                'requestId' => 'amzn1.echo-api.request.law-test',
                'timestamp' => $timestamp ?? gmdate('Y-m-d\TH:i:s\Z'),
                'locale' => 'en-US',
                'intent' => ['name' => 'PhlixTitleRuntimeIntent'],
            ],
        ]);
    }
}
