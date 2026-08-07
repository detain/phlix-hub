<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Alexa\CertChainFetcherInterface;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Tests\Support\Alexa\AlexaCertificateFixture;
use Phlix\Hub\Tests\Support\Alexa\RecordingCertChainFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * S90 — Amazon request-signature verification.
 *
 * ## How to read this suite
 *
 * Every rejection test carries a **succeeding control in the same method**: the
 * request is first asserted to be ACCEPTED, then one thing is broken and the
 * request is asserted to be REJECTED with a named code. A second rejection would
 * not be a control — only a pass proves the test can tell the two apart, and a
 * middleware that rejected everything (or that threw before reaching the guard
 * under test) would fail the control half.
 *
 * Rejections are asserted by **code**, never merely by "not null". The codes are
 * what say *which* guard fired; without them
 * `testALookalikeSanIsRejected...` would pass just as happily if the SAN check
 * were deleted and the chain simply failed to anchor.
 *
 * ## The two fixtures, and what each is evidence of
 *
 * **1. A real, recorded Amazon chain** — `tests/fixtures/alexa/`,
 * `amazon-echo-api-cert-12.pem`. Downloaded on 2026-08-07 with
 * `curl https://s3.amazonaws.com/echo.api/echo-api-cert-12.pem`, HTTP 200, 6,909
 * bytes, sha256
 * `b0a2cb52212356a129ee0a6190b2980d2e7f5855aa9b75fb3175c1858a4d7ed7`. It is a
 * four-certificate chain — leaf `CN=echo-api.amazon.com` → `Amazon RSA 2048 M01`
 * → `Amazon Root CA 1` → `Starfield Services Root CA - G2` — carrying exactly
 * one SAN, `DNS:echo-api.amazon.com`. Nobody here made it or chose its contents.
 *
 * It is evidence for: the real chain shape (four blocks, not two), the real SAN
 * spelling, and — because its leaf genuinely expired on 2023-12-23, which is a
 * property of Amazon's rotation schedule and not of anything this repo did — the
 * expiry rejection. It is NOT evidence that a valid Amazon request is accepted:
 * producing one needs Amazon's private key, which is the whole point of the
 * scheme. No amount of test-writing can conjure that fixture, and a
 * hand-rolled substitute would prove nothing.
 *
 * **2. A generated PKI** — {@see AlexaCertificateFixture}, which can produce a
 * signable request and, crucially, can break each precondition in isolation. It
 * validates the ALGORITHM, not Amazon's actual chain; see that class for the
 * full statement of what it can and cannot show.
 *
 * ## Anti-vacuity
 *
 * {@see testTheSignatureCheckIsReachedWithEverythingElseValid()} exists solely to
 * prove that "rejected" tests are not all passing at some earlier guard: it holds
 * every other input correct and breaks only the signature, and asserts the code
 * is `ALEXA_SIGNATURE_INVALID`. {@see testAStaleTimestampIsRejectedAfterAValidSignature()}
 * is the same argument one step further on — the timestamp check is the LAST
 * thing the middleware does, so reaching it at all proves the URL check, the
 * fetch, the chain verification, the SAN check and `openssl_verify()` all ran and
 * all passed.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 */
final class AlexaSignatureMiddlewareTest extends TestCase
{
    private const VALID_URL = 'https://s3.amazonaws.com/echo.api/echo-api-cert-12.pem';

    private const REAL_CHAIN_FIXTURE = __DIR__ . '/../../../fixtures/alexa/amazon-echo-api-cert-12.pem';

    // ---------------------------------------------------------------------
    // Happy path + anti-vacuity
    // ---------------------------------------------------------------------

    public function testAValidAmazonShapedRequestIsAccepted(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();
        $fetcher = new RecordingCertChainFetcher($fixture->chain('valid'));

        $verdict = $this->middleware($fetcher)(self::request($body, $fixture->sign($body)));

        self::assertNull($verdict, 'a correctly signed, fresh, well-chained request must pass the gate');
        self::assertSame([self::VALID_URL], $fetcher->calls, 'the validated URL is the one fetched');
    }

    public function testTheSignatureCheckIsReachedWithEverythingElseValid(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();
        $fetcher = new RecordingCertChainFetcher($fixture->chain('valid'));
        $middleware = $this->middleware($fetcher);

        // Control: identical request, genuine signature.
        self::assertNull(
            $middleware(self::request($body, $fixture->sign($body))),
            'control: the genuine signature must be accepted',
        );

        // Only the signature differs — a structurally perfect RSA/SHA-256
        // signature over the same bytes, made by a key in no chain at all.
        $this->assertRejected(
            $middleware(self::request($body, $fixture->forge($body))),
            'ALEXA_SIGNATURE_INVALID',
        );
    }

    // ---------------------------------------------------------------------
    // Signature
    // ---------------------------------------------------------------------

    public function testASemanticallyEqualBodyWithDifferentBytesIsRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        // Deliberately NOT in json_encode()'s canonical spelling: there are
        // spaces after the colons and an unescaped "/" (json_encode writes
        // "\/"). That is what makes the CONTROL half of this test load-bearing —
        // a middleware that verified json_encode(json_decode($raw)) rather than
        // $raw would reject these bytes, which is exactly the substitution being
        // guarded against. Without it, mutating the verify call to re-encode the
        // body leaves this test green (measured: it did).
        $tail = '"type": "IntentRequest", "locale": "en-US", "url": "https://example.test/a/b"';
        $signedBytes = '{"version": "1.0", "request": {' . $tail . ', "timestamp": "' . $timestamp . '"}}';
        $reordered = '{"request": {"timestamp": "' . $timestamp . '", ' . $tail . '}, "version": "1.0"}';

        self::assertNotSame(
            (string) json_encode(json_decode($signedBytes, true)),
            $signedBytes,
            'the signed bytes must not already be json_encode\'s canonical spelling, '
            . 'or this test cannot tell raw-byte verification from re-encoded verification',
        );

        // assertEquals, not assertSame: array key ORDER is exactly the thing
        // that differs, and it is exactly the thing JSON semantics ignore.
        self::assertEquals(
            json_decode($signedBytes, true),
            json_decode($reordered, true),
            'the two payloads must be semantically identical for this test to mean anything',
        );
        self::assertNotSame($signedBytes, $reordered, 'and their bytes must differ');

        $signature = $fixture->sign($signedBytes);
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        // Control: the exact bytes that were signed.
        self::assertNull($middleware(self::request($signedBytes, $signature)));

        // The same request, re-spelled. Verifying a re-encoded body would accept it.
        $this->assertRejected($middleware(self::request($reordered, $signature)), 'ALEXA_SIGNATURE_INVALID');
    }

    public function testANonBase64SignatureHeaderIsRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        self::assertNull($middleware(self::request($body, $fixture->sign($body))));

        $this->assertRejected(
            $middleware(self::request($body, 'not~~base64~~at~~all')),
            'ALEXA_SIGNATURE_INVALID',
        );
    }

    public function testTheLegacySha1SignatureHeaderAloneDoesNotSatisfyTheGate(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();
        $signature = $fixture->sign($body);
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        // Control: the SHA-256 header is honoured.
        self::assertNull($middleware(self::request($body, $signature)));

        // The same value under the legacy SHA-1 header name is not a substitute:
        // accepting it would let a caller pick the weaker hash.
        $legacy = self::request($body, $signature);
        unset($legacy->headers['SIGNATURE-256']);
        $legacy->headers['SIGNATURE'] = $signature;

        $this->assertRejected($middleware($legacy), 'ALEXA_MISSING_SIGNATURE_HEADER');
    }

    // ---------------------------------------------------------------------
    // SignatureCertChainUrl — the SSRF gate
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedCertChainUrls(): array
    {
        return [
            'plain http' => ['http://s3.amazonaws.com/echo.api/cert.pem'],
            'file scheme' => ['file:///etc/passwd'],
            'no scheme' => ['//s3.amazonaws.com/echo.api/cert.pem'],
            'host is a prefix of the real one' => ['https://s3.amazonaws.co/echo.api/cert.pem'],
            'host has the real one as a prefix' => ['https://s3.amazonaws.com.evil.test/echo.api/cert.pem'],
            'host has the real one as a suffix' => ['https://evil-s3.amazonaws.com/echo.api/cert.pem'],
            'host is a subdomain of the real one' => ['https://x.s3.amazonaws.com/echo.api/cert.pem'],
            'internal metadata service' => ['https://169.254.169.254/echo.api/cert.pem'],
            'userinfo smuggling the real host' => ['https://s3.amazonaws.com@evil.test/echo.api/cert.pem'],
            'userinfo on the real host' => ['https://evil.test@s3.amazonaws.com/echo.api/cert.pem'],
            'non-443 port' => ['https://s3.amazonaws.com:8443/echo.api/cert.pem'],
            'dot-dot escape' => ['https://s3.amazonaws.com/echo.api/../evil/cert.pem'],
            'percent-encoded dot-dot' => ['https://s3.amazonaws.com/echo.api/%2e%2e/evil/cert.pem'],
            'double slash before the prefix' => ['https://s3.amazonaws.com//echo.api/cert.pem'],
            'double slash inside the path' => ['https://s3.amazonaws.com/echo.api//cert.pem'],
            'single-dot segment' => ['https://s3.amazonaws.com/echo.api/./cert.pem'],
            'traversal that re-enters the prefix' => ['https://s3.amazonaws.com/other/../echo.api/cert.pem'],
            'backslash separator' => ['https://s3.amazonaws.com/echo.api\\..\\evil/cert.pem'],
            'wrong prefix' => ['https://s3.amazonaws.com/echo.api2/cert.pem'],
            'prefix appears later' => ['https://s3.amazonaws.com/other/echo.api/cert.pem'],
            'prefix case altered' => ['https://s3.amazonaws.com/Echo.api/cert.pem'],
            'directory, not a file' => ['https://s3.amazonaws.com/echo.api/'],
            'no path at all' => ['https://s3.amazonaws.com'],
            'unparseable' => ['https://:443/echo.api/cert.pem'],
            'query string appended' => ['https://s3.amazonaws.com/echo.api/cert.pem?x=1'],
            'fragment appended' => ['https://s3.amazonaws.com/echo.api/cert.pem#x'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejectedCertChainUrls')]
    public function testARejectedCertChainUrlIsNeverFetched(string $url): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();
        $signature = $fixture->sign($body);
        $fetcher = new RecordingCertChainFetcher($fixture->chain('valid'));
        $middleware = $this->middleware($fetcher);

        // Control: with the documented URL this exact request is ACCEPTED, and
        // the fetch happens exactly once.
        self::assertNull(
            $middleware(self::request($body, $signature)),
            'control: the documented cert URL must be accepted',
        );
        self::assertSame(1, $fetcher->callCount(), 'control: the good URL is fetched');

        $verdict = $middleware(self::request($body, $signature, $url));

        // The URL is rejected...
        $expected = $url === '' ? 'ALEXA_MISSING_CERT_CHAIN_URL' : 'ALEXA_CERT_URL_REJECTED';
        $this->assertRejected($verdict, $expected);

        // ...and, the part that matters, the worker never went and got it.
        self::assertSame(1, $fetcher->callCount(), 'a rejected URL must never reach the fetcher');
        self::assertNotContains($url, $fetcher->calls);
    }

    public function testAnExplicit443AndAnImplicitOneAreBothAccepted(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();
        $signature = $fixture->sign($body);
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        self::assertNull($middleware(self::request($body, $signature)));
        self::assertNull($middleware(self::request(
            $body,
            $signature,
            'https://s3.amazonaws.com:443/echo.api/echo-api-cert-12.pem',
        )));
        self::assertNull($middleware(self::request(
            $body,
            $signature,
            'HTTPS://S3.AMAZONAWS.COM/echo.api/echo-api-cert-12.pem',
        )), 'Amazon documents scheme and host as case-insensitive');
    }

    // ---------------------------------------------------------------------
    // Certificate chain
    // ---------------------------------------------------------------------

    public function testAChainThatDoesNotAnchorToATrustedRootIsRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        // Control: same shape, but rooted in the configured trust store.
        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')))(
                self::request($body, $fixture->sign($body)),
            ),
        );

        // A well-formed chain with the right SAN and valid dates whose root is
        // simply not trusted — i.e. an attacker who ran their own CA.
        $this->assertRejected(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('untrusted')))(
                self::request($body, $fixture->sign($body, 'untrusted')),
            ),
            'ALEXA_CERT_CHAIN_UNTRUSTED',
        );
    }

    public function testALookalikeSanIsRejectedBesideTheExactSan(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        // Control: SAN is exactly echo-api.amazon.com.
        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')))(
                self::request($body, $fixture->sign($body)),
            ),
        );

        // SAN is echo-api.amazon.com.attacker.test — a SUPERSTRING of the real
        // name, correctly chained and in date. A str_contains()-shaped SAN check
        // accepts this; an exact per-entry compare does not.
        $this->assertRejected(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('wrongSan')))(
                self::request($body, $fixture->sign($body, 'wrongSan')),
            ),
            'ALEXA_CERT_SAN_MISMATCH',
        );
    }

    public function testASanListIsSearchedPastItsFirstEntry(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        // The echo name is the SECOND of three SAN entries. This is the control
        // that stops the exact-match rule above being over-tightened into
        // "the SAN extension must equal DNS:echo-api.amazon.com".
        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('multiSan')))(
                self::request($body, $fixture->sign($body, 'multiSan')),
            ),
        );
    }

    public function testTheRecordedAmazonChainIsRejectedAsExpired(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        // Control: an in-date leaf is accepted, so "rejected" below cannot be
        // the middleware simply refusing everything.
        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')))(
                self::request($body, $fixture->sign($body)),
            ),
        );

        // Amazon's own published chain, whose leaf expired 2023-12-23. The
        // signature is irrelevant here: expiry is checked before it.
        $this->assertRejected(
            $this->middleware(new RecordingCertChainFetcher(self::realAmazonChain()))(
                self::request($body, $fixture->sign($body)),
            ),
            'ALEXA_CERT_EXPIRED',
        );
    }

    /**
     * Pin the recorded fixture's own properties.
     *
     * Without this, a corrupted or swapped fixture would quietly turn
     * {@see testTheRecordedAmazonChainIsRejectedAsExpired()} into a test of
     * "unparseable input is rejected", which is a different and much weaker
     * claim. These assertions are made with ext/openssl directly rather than
     * through the middleware, so they cannot be satisfied by the middleware
     * being wrong in a matching way.
     */
    public function testTheRecordedAmazonChainIsWhatThisSuiteClaimsItIs(): void
    {
        $pem = self::realAmazonChain();

        self::assertSame(
            'b0a2cb52212356a129ee0a6190b2980d2e7f5855aa9b75fb3175c1858a4d7ed7',
            hash('sha256', $pem),
            'the recorded Amazon chain has been modified since it was downloaded',
        );

        $blocks = preg_match_all('/-----BEGIN CERTIFICATE-----/', $pem);
        self::assertSame(4, $blocks, 'Amazon publishes a four-certificate chain');

        $parsed = openssl_x509_parse($pem);
        self::assertIsArray($parsed);
        self::assertSame('DNS:echo-api.amazon.com', $parsed['extensions']['subjectAltName'] ?? null);
        self::assertLessThan(
            time(),
            $parsed['validTo_time_t'] ?? PHP_INT_MAX,
            'the fixture is only evidence for the expiry path while it is actually expired',
        );
    }

    public function testALeafWithNoEchoSanAtAllIsRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        // Control: a leaf that does carry the SAN.
        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')))(
                self::request($body, $fixture->sign($body)),
            ),
        );

        // Two distinct shapes of "no SAN": an extensions block without a
        // subjectAltName, and no extensions block at all. Both must reject; a
        // check that only handled one of them would let the other through.
        foreach (['noSan', 'noExtensions'] as $variant) {
            $this->assertRejected(
                $this->middleware(new RecordingCertChainFetcher($fixture->chain($variant)))(
                    self::request($body, $fixture->sign($body, $variant)),
                ),
                'ALEXA_CERT_SAN_MISMATCH',
            );
        }
    }

    public function testANonDnsSanEntryDoesNotHideTheEchoName(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        // SAN is `IP:127.0.0.1, email:ops@example.test, DNS:echo-api.amazon.com`.
        // The parser must skip the non-DNS entries rather than give up at the
        // first one — a real Amazon leaf could gain an extra SAN type without
        // that being a reason to reject every Alexa request.
        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('ipSanFirst')))(
                self::request($body, $fixture->sign($body, 'ipSanFirst')),
            ),
        );
    }

    public function testAPemBlockThatIsNotACertificateIsRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')))(
                self::request($body, $fixture->sign($body)),
            ),
        );

        // Well-formed PEM armour around base64 that is not DER at all. This gets
        // past the block splitter and has to be caught by the parse.
        $notACertificate = "-----BEGIN CERTIFICATE-----\nZmFrZQ==\n-----END CERTIFICATE-----\n";
        $this->assertRejected(
            $this->middleware(new RecordingCertChainFetcher($notACertificate))(
                self::request($body, $fixture->sign($body)),
            ),
            'ALEXA_CERT_CHAIN_MALFORMED',
        );
    }

    public function testASignedBodyThatIsNotAJsonObjectIsRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        $good = self::body();
        self::assertNull($middleware(self::request($good, $fixture->sign($good))));

        // Legitimately signed by the right key, but not an Alexa envelope: there
        // is nowhere for a timestamp to live, so the replay window cannot be
        // enforced and the request must not be allowed.
        foreach (['"just a string"', 'null', '42', 'not json at all'] as $payload) {
            $this->assertRejected(
                $middleware(self::request($payload, $fixture->sign($payload))),
                'ALEXA_TIMESTAMP_MALFORMED',
            );
        }

        // A JSON array decodes to a PHP array, so it gets one step further and
        // is rejected for having no `request` object. Asserting the exact code
        // rather than "some rejection" is what shows the two shapes take
        // different paths instead of one guard swallowing both.
        $this->assertRejected(
            $middleware(self::request('[1,2,3]', $fixture->sign('[1,2,3]'))),
            'ALEXA_TIMESTAMP_MISSING',
        );
    }

    public function testAGarbageChainBodyIsRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')))(
                self::request($body, $fixture->sign($body)),
            ),
        );

        $this->assertRejected(
            $this->middleware(new RecordingCertChainFetcher('<html>404 Not Found</html>'))(
                self::request($body, $fixture->sign($body)),
            ),
            'ALEXA_CERT_CHAIN_MALFORMED',
        );
    }

    public function testAFetchFailureIsRejectedNotIgnored(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        self::assertNull(
            $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')))(
                self::request($body, $fixture->sign($body)),
            ),
        );

        $this->assertRejected(
            $this->middleware(new RecordingCertChainFetcher(null))(
                self::request($body, $fixture->sign($body)),
            ),
            'ALEXA_CERT_FETCH_FAILED',
        );
    }

    public function testAThrowingFetcherFailsClosed(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();

        $exploding = new class implements CertChainFetcherInterface {
            public function fetch(string $url): ?string
            {
                throw new RuntimeException('the network is on fire');
            }
        };

        $middleware = new AlexaSignatureMiddleware(
            $exploding,
            new StructuredLogger('alexa-test', []),
            $fixture->trustedCaBundle(),
        );

        $this->assertRejected(
            $middleware(self::request($body, $fixture->sign($body))),
            'ALEXA_VERIFICATION_ERROR',
        );
    }

    // ---------------------------------------------------------------------
    // Timestamp / replay window
    // ---------------------------------------------------------------------

    public function testAStaleTimestampIsRejectedAfterAValidSignature(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        $fresh = self::body(gmdate('Y-m-d\TH:i:s\Z'));
        self::assertNull(
            $middleware(self::request($fresh, $fixture->sign($fresh))),
            'control: a fresh timestamp must be accepted',
        );

        // Genuinely signed, correctly chained, and 10 minutes old. Reaching this
        // verdict at all means the whole crypto path ran and passed.
        $stale = self::body(gmdate('Y-m-d\TH:i:s\Z', time() - 600));
        $this->assertRejected($middleware(self::request($stale, $fixture->sign($stale))), 'ALEXA_TIMESTAMP_STALE');
    }

    public function testAFutureTimestampIsRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        $fresh = self::body(gmdate('Y-m-d\TH:i:s\Z'));
        self::assertNull($middleware(self::request($fresh, $fixture->sign($fresh))));

        // A one-sided "older than 150s" check would let a captured request be
        // replayed forever by dating it forward.
        $future = self::body(gmdate('Y-m-d\TH:i:s\Z', time() + 600));
        $this->assertRejected($middleware(self::request($future, $fixture->sign($future))), 'ALEXA_TIMESTAMP_STALE');
    }

    public function testTheReplayWindowBoundaryIsWhereItIsDocumented(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        // 149s old: inside the window. (149 rather than exactly 150 so a
        // sub-second lag between building the body and evaluating it cannot
        // flip the verdict.)
        $inside = self::body(gmdate('Y-m-d\TH:i:s\Z', time() - (AlexaSignatureMiddleware::MAX_TIMESTAMP_SKEW_SECONDS - 1)));
        self::assertNull($middleware(self::request($inside, $fixture->sign($inside))));

        // 152s old: outside it.
        $outside = self::body(gmdate('Y-m-d\TH:i:s\Z', time() - (AlexaSignatureMiddleware::MAX_TIMESTAMP_SKEW_SECONDS + 2)));
        $this->assertRejected($middleware(self::request($outside, $fixture->sign($outside))), 'ALEXA_TIMESTAMP_STALE');
    }

    public function testAMissingTimestampIsRejectedRatherThanSkipped(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        $present = self::body();
        self::assertNull($middleware(self::request($present, $fixture->sign($present))));

        // A correctly signed body that simply omits request.timestamp. Skipping
        // the check here would remove the replay window entirely for any caller
        // who leaves the field out.
        $absent = (string) json_encode(['version' => '1.0', 'request' => ['type' => 'IntentRequest']]);
        $this->assertRejected($middleware(self::request($absent, $fixture->sign($absent))), 'ALEXA_TIMESTAMP_MISSING');

        $noEnvelope = (string) json_encode(['version' => '1.0']);
        $this->assertRejected(
            $middleware(self::request($noEnvelope, $fixture->sign($noEnvelope))),
            'ALEXA_TIMESTAMP_MISSING',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTimestamps(): array
    {
        return [
            'empty string' => [''],
            // new DateTimeImmutable('now') is the current time — an unvalidated
            // string is a free pass through the replay window.
            'the word now' => ['now'],
            'a relative offset' => ['+1 day'],
            'a bare date' => ['2026-08-07'],
            'unix seconds' => ['1786086218'],
            'no timezone' => ['2026-08-07T12:00:00'],
            'month 99' => ['2026-99-07T12:00:00Z'],
            'trailing junk' => ['2026-08-07T12:00:00Z ignore-me'],
        ];
    }

    #[DataProvider('malformedTimestamps')]
    public function testAMalformedTimestampIsRejected(string $timestamp): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        $good = self::body();
        self::assertNull(
            $middleware(self::request($good, $fixture->sign($good))),
            'control: a well-formed ISO-8601 timestamp must be accepted',
        );

        $bad = self::body($timestamp);
        $verdict = $middleware(self::request($bad, $fixture->sign($bad)));

        self::assertNotNull($verdict, 'a malformed timestamp must never be allowed through');
        $decoded = self::decode($verdict);
        self::assertContains(
            $decoded['code'] ?? null,
            ['ALEXA_TIMESTAMP_MALFORMED', 'ALEXA_TIMESTAMP_MISSING'],
            'rejection must come from the timestamp guard, not from somewhere earlier',
        );
    }

    public function testAnOffsetTimestampIsAcceptedAsWellAsAZuluOne(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        // Control for the strict ISO-8601 pattern: it must not be so tight that
        // it rejects a legal spelling of a perfectly fresh instant.
        foreach (['Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.v\Z'] as $format) {
            $body = self::body(gmdate($format));
            self::assertNull($middleware(self::request($body, $fixture->sign($body))), $format);
        }

        $offset = self::body((new \DateTimeImmutable('now', new \DateTimeZone('+02:00')))->format('Y-m-d\TH:i:sP'));
        self::assertNull($middleware(self::request($offset, $fixture->sign($offset))));
    }

    // ---------------------------------------------------------------------
    // Headers and body presence
    // ---------------------------------------------------------------------

    public function testMissingHeadersAndAnEmptyBodyAreRejected(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $body = self::body();
        $signature = $fixture->sign($body);
        $middleware = $this->middleware(new RecordingCertChainFetcher($fixture->chain('valid')));

        self::assertNull($middleware(self::request($body, $signature)));

        $noUrl = self::request($body, $signature);
        unset($noUrl->headers['SIGNATURECERTCHAINURL']);
        $this->assertRejected($middleware($noUrl), 'ALEXA_MISSING_CERT_CHAIN_URL');

        $noSignature = self::request($body, $signature);
        unset($noSignature->headers['SIGNATURE-256']);
        $this->assertRejected($middleware($noSignature), 'ALEXA_MISSING_SIGNATURE_HEADER');

        $emptySignature = self::request($body, '');
        $this->assertRejected($middleware($emptySignature), 'ALEXA_MISSING_SIGNATURE_HEADER');

        $this->assertRejected($middleware(self::request('', $signature)), 'ALEXA_EMPTY_BODY');
    }

    // ---------------------------------------------------------------------
    // The chain cache — the resident-worker constraint
    // ---------------------------------------------------------------------

    public function testAVerifiedChainIsCachedSoTheCommonPathDoesNoNetworkIo(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $fetcher = new RecordingCertChainFetcher($fixture->chain('valid'));
        $middleware = $this->middleware($fetcher);

        for ($i = 0; $i < 5; $i++) {
            $body = self::body(gmdate('Y-m-d\TH:i:s\Z', time() - $i));
            self::assertNull($middleware(self::request($body, $fixture->sign($body))));
        }

        self::assertSame(1, $fetcher->callCount(), 'five accepted requests, one fetch');

        // Control: a DIFFERENT cert URL is a genuine miss and does fetch, so the
        // count above is a cache hit and not a fetcher that stopped working.
        $body = self::body();
        self::assertNull($middleware(self::request(
            $body,
            $fixture->sign($body),
            'https://s3.amazonaws.com/echo.api/echo-api-cert-99.pem',
        )));
        self::assertSame(2, $fetcher->callCount());
    }

    public function testAFailedVerificationIsNotCached(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $fetcher = new RecordingCertChainFetcher(null);
        $middleware = $this->middleware($fetcher);
        $body = self::body();
        $signature = $fixture->sign($body);

        $this->assertRejected($middleware(self::request($body, $signature)), 'ALEXA_CERT_FETCH_FAILED');

        // S3 comes back. A cached failure would pin the rejection forever.
        $fetcher->pem = $fixture->chain('valid');
        self::assertNull($middleware(self::request($body, $signature)));
        self::assertSame(2, $fetcher->callCount());
    }

    public function testAnExpiredCacheEntryIsReVerifiedRatherThanExtended(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $fetcher = new RecordingCertChainFetcher($fixture->chain('valid'));

        // A zero-second TTL makes every entry stale the moment it is written, so
        // the "cached but past its deadline" branch is reached deterministically
        // instead of after an hour. A stale entry must be dropped and the chain
        // re-verified — otherwise a rotated or revoked Amazon leaf would keep
        // being trusted for as long as the worker stayed up.
        $middleware = new AlexaSignatureMiddleware(
            $fetcher,
            new StructuredLogger('alexa-test', []),
            $fixture->trustedCaBundle(),
            0,
        );

        $body = self::body();
        $signature = $fixture->sign($body);

        self::assertNull($middleware(self::request($body, $signature)));
        self::assertNull($middleware(self::request($body, $signature)));
        self::assertNull($middleware(self::request($body, $signature)));

        self::assertSame(3, $fetcher->callCount(), 'a stale cache entry must not be reused');

        // Control: the same three requests under the shipped TTL fetch once.
        $cachingFetcher = new RecordingCertChainFetcher($fixture->chain('valid'));
        $caching = $this->middleware($cachingFetcher);
        for ($i = 0; $i < 3; $i++) {
            self::assertNull($caching(self::request($body, $signature)));
        }
        self::assertSame(1, $cachingFetcher->callCount());
    }

    public function testTheChainCacheIsBoundedAgainstAttackerSuppliedUrls(): void
    {
        $fixture = AlexaCertificateFixture::shared();
        $fetcher = new RecordingCertChainFetcher($fixture->chain('valid'));
        $middleware = $this->middleware($fetcher);
        $body = self::body();
        $signature = $fixture->sign($body);

        $first = 'https://s3.amazonaws.com/echo.api/cert-0.pem';
        self::assertNull($middleware(self::request($body, $signature, $first)));

        // Fill the cache past its capacity with distinct, individually valid URLs.
        for ($i = 1; $i <= AlexaSignatureMiddleware::CACHE_CAPACITY; $i++) {
            $url = 'https://s3.amazonaws.com/echo.api/cert-' . $i . '.pem';
            self::assertNull($middleware(self::request($body, $signature, $url)));
        }

        $fetchesSoFar = $fetcher->callCount();
        self::assertSame(AlexaSignatureMiddleware::CACHE_CAPACITY + 1, $fetchesSoFar);

        // The first entry has been evicted, so it must be fetched again...
        self::assertNull($middleware(self::request($body, $signature, $first)));
        self::assertSame($fetchesSoFar + 1, $fetcher->callCount(), 'the oldest entry must have been evicted');

        // ...and the control: a recently-used entry is still cached.
        $recent = 'https://s3.amazonaws.com/echo.api/cert-' . AlexaSignatureMiddleware::CACHE_CAPACITY . '.pem';
        self::assertNull($middleware(self::request($body, $signature, $recent)));
        self::assertSame($fetchesSoFar + 1, $fetcher->callCount(), 'a live entry must still be served from cache');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function middleware(CertChainFetcherInterface $fetcher): AlexaSignatureMiddleware
    {
        return new AlexaSignatureMiddleware(
            $fetcher,
            // A StructuredLogger with no handlers configured: real class, real
            // calls, no file I/O and no LoggerFactory global state.
            new StructuredLogger('alexa-test', []),
            AlexaCertificateFixture::shared()->trustedCaBundle(),
        );
    }

    private static function request(string $body, string $signature, string $url = self::VALID_URL): Request
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

    /**
     * An Alexa-shaped request envelope. `$timestamp` defaults to now.
     */
    private static function body(?string $timestamp = null): string
    {
        return (string) json_encode([
            'version' => '1.0',
            'session' => ['sessionId' => 'amzn1.echo-api.session.s90-test'],
            'request' => [
                'type' => 'IntentRequest',
                'requestId' => 'amzn1.echo-api.request.s90-test',
                'timestamp' => $timestamp ?? gmdate('Y-m-d\TH:i:s\Z'),
                'locale' => 'en-US',
                'intent' => ['name' => 'PhlixTitleRuntimeIntent'],
            ],
        ]);
    }

    private static function realAmazonChain(): string
    {
        $pem = file_get_contents(self::REAL_CHAIN_FIXTURE);
        self::assertIsString($pem, 'the recorded Amazon chain fixture is missing');

        return $pem;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function assertRejected(?Response $response, string $expectedCode): void
    {
        self::assertNotNull($response, 'expected a rejection, got an allow');
        self::assertSame(400, $response->statusCode);

        $decoded = self::decode($response);
        self::assertSame($expectedCode, $decoded['code'] ?? null, 'a different guard fired than the one under test');
    }
}
