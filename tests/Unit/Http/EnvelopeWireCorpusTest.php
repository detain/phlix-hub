<?php

/**
 * Phlix hub component: Http tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Http\CorsPolicy;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * W4 PREP (refactor-1119) — the hub-side half of the cross-repo response
 * wire corpus. The server mirror is
 * `Phlix\Tests\Unit\Server\Http\EnvelopeWireCorpusTest` (phlix-server,
 * `tests/Unit/Server/Http/`); both files capture TODAY's bytes as the spec
 * so the W3 shared-`Envelope` adoption (§ M-16 of SHARED-056-DESIGN.md) can
 * be proven a pure no-op on the wire.
 *
 * ## Claimed-identical families (pinned to the SERVER's shape vocabulary)
 *
 * - `{error, code}` + extras (Response::error): key ORDER `error, code,
 *   ...extras` and the left-biased-union law are identical to the server.
 *   The body BYTES diverge in whitespace only: the hub encodes COMPACT
 *   (`JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`, M-HUB-09 compact law)
 *   while the server pretty-prints — the ratified A-03/R-11 pair of
 *   intentional divergences, NOT a defect to harmonize here.
 * - 429 `rate_limited`: identical body, header order (`Retry-After` set
 *   BEFORE the JSON encode, so the wire order is
 *   `Retry-After, Content-Type, Cache-Control`), integer delta-seconds
 *   `Retry-After` including the clamped `'0'` arm, and the same
 *   no-charset `Content-Type` default. `testSeamReproducesTheHistoricalHandRolledFrame`
 *   pins the W4 dedupe as byte-identity against the pre-seam composition.
 *
 * ## Hub-only / divergent families (pinned as-IS, labeled — do not
 * ## "fix" them through this corpus)
 *
 * - Maintenance 503 (`provider_unavailable`): HUB-ONLY — the server has no
 *   maintenance gate; its `provider_unavailable` emitters are OAuth
 *   redirect/message arms with different shapes (A-04).
 * - 204: the hub has no `noContent()` helper; controllers write
 *   `->status(204)` (API-shape divergence; the W3 Envelope seam may adopt
 *   the server's clearer name — pinning current bytes either way).
 * - 304: the hub static layer NEVER answers 304 (grep-proven zero call
 *   sites in src/) — no arm is fabricated for it here.
 * - HEAD: the hub selects `BodylessResponse` via the explicit
 *   `headOnly(true)` setter and the CALLER's `Content-Length`; the server
 *   instead offers `asHeadReply()` which self-pins the length. A-19-era
 *   intentional divergence, pinned.
 * - CORS: the hub's `CorsPolicy` NEVER emits `Access-Control-Allow-Credentials`
 *   (bearer-auth REST; A-19 ratified) while the server's `CorsManager` is
 *   credentialed by design. Both hosts answer preflight with 204.
 * - `stream.limit_exceeded` and the subtitle-quota 429 are SERVER-only
 *   non-canonical families and out of scope on both sides.
 */
final class EnvelopeWireCorpusTest extends TestCase
{
    // ------------------------------------------------------------------
    // Family: {error, code} (+ extras) — claimed-identical shape,
    // hub-compact bytes (A-03/R-11 whitespace divergence vs server).
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{response: Response, body: string, status: int, headers: array<string, string>}>
     */
    public static function errorEnvelopeProvider(): array
    {
        return [
            'plain 404 not_found' => [
                'response' => (new Response())->error(404, 'not_found', 'Item not found'),
                'body' => '{"error":"Item not found","code":"not_found"}',
                'status' => 404,
                'headers' => ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            ],
            'canonical 429 pair (error envelope path)' => [
                'response' => (new Response())->error(429, 'rate_limited', 'Too Many Requests'),
                'body' => '{"error":"Too Many Requests","code":"rate_limited"}',
                'status' => 429,
                'headers' => ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            ],
            'extras merge additive after error/code' => [
                'response' => (new Response())->error(409, 'conflict', 'nope', [
                    'message' => 'nope',
                    'detail' => 'why',
                ]),
                'body' => '{"error":"nope","code":"conflict","message":"nope","detail":"why"}',
                'status' => 409,
                'headers' => ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            ],
        ];
    }

    /**
     * @param array<string, string> $headers exact header map expected on the wire
     */
    #[DataProvider('errorEnvelopeProvider')]
    public function testErrorFamilyWireBytesArePinned(
        Response $response,
        string $body,
        int $status,
        array $headers,
    ): void {
        self::assertSame($status, $response->statusCode);
        self::assertSame($body, $response->body);
        self::assertSame($headers, $response->headers);

        // Shape vocabulary shared with the server: key set + insertion order.
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($headers) === [] ? [] : array_slice(array_keys($decoded), 0, 2), ['error', 'code']);
    }

    // ------------------------------------------------------------------
    // Family: 429 rate_limited via the canonical dispatch path —
    // claimed-identical to the server (A-04 twin).
    // ------------------------------------------------------------------

    public function testCanonicalRateLimitedPastResetPinsTheZeroArm(): void
    {
        $response = Application::rateLimitResponse(new RateLimitException(resetAt: 1));

        self::assertSame(429, $response->statusCode);
        self::assertSame('0', $response->headers['Retry-After']);
        // Header ORDER on the wire: Retry-After precedes the JSON defaults
        // (the server's seam documents the same law; byte-ident here).
        self::assertSame(
            ['Retry-After', 'Content-Type', 'Cache-Control'],
            array_keys($response->headers),
        );
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertSame('{"error":"Too Many Requests","code":"rate_limited"}', $response->body);
    }

    public function testCanonicalRateLimitedFutureResetEmitsIntegerDeltaSeconds(): void
    {
        $exception = new RateLimitException(resetAt: time() + 300);
        $response = Application::rateLimitResponse($exception);

        $retryAfter = $response->headers['Retry-After'];
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        self::assertSame('{"error":"Too Many Requests","code":"rate_limited"}', $response->body);
    }

    /**
     * W4 dedupe proof (H-b): the `Response::rateLimited()` seam must reproduce
     * the pre-seam hand-rolled composition byte-for-byte — body, header order,
     * and full wire — or the corpus family above silently changes shape.
     * The historical arm is re-composed inline exactly as every site wrote it
     * before the seam existed.
     */
    public function testSeamReproducesTheHistoricalHandRolledFrame(): void
    {
        $historical = (new Response())
            ->status(429)
            ->header('Retry-After', (string) 0)
            ->json(['error' => 'Too Many Requests', 'code' => 'rate_limited']);

        $seam = Response::rateLimited(0);

        self::assertSame($historical->headers, $seam->headers);
        self::assertSame($historical->body, $seam->body);
        // Full-wire identity with the volatile Date stamp stripped (the two
        // renders straddle clock ticks; every other header line must match).
        $stripDate = static fn (string $wire): string => (string) preg_replace('/^Date: .*\r\n/m', '', $wire);
        self::assertSame(
            $stripDate((string) $historical->toWorkermanResponse()),
            $stripDate((string) $seam->toWorkermanResponse()),
        );
    }

    /**
     * The additive `message` arm the auth throttles send (HUB-19): merged
     * AFTER error/code, header order untouched. Mirrors the servers' shape
     * family: extras ride last (left-biased union).
     */
    public function testSeamMergesAdditiveMessageAfterCanonicalPair(): void
    {
        $response = Response::rateLimited(42, ['message' => 'Try again in 42 seconds.']);

        self::assertSame('42', $response->headers['Retry-After']);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['error', 'code', 'message'], array_keys($decoded));
        self::assertSame('rate_limited', $decoded['code']);
    }

    // ------------------------------------------------------------------
    // Family: maintenance 503 — HUB-ONLY (A-04; server has no gate).
    // ------------------------------------------------------------------

    public function testMaintenanceEnvelopeIsHubOnlyAndFullyPinned(): void
    {
        $response = Application::maintenanceResponse();

        self::assertSame(503, $response->statusCode);
        self::assertSame('60', $response->headers['Retry-After']);
        self::assertSame(
            ['Retry-After', 'Content-Type', 'Cache-Control'],
            array_keys($response->headers),
        );
        self::assertSame(
            '{"error":"This hub is under maintenance. Try again shortly.",'
            . '"code":"provider_unavailable",'
            . '"detail":"Hub maintenance mode (hub.maintenance_mode) is on;'
            . ' API traffic resumes when an admin switches it off."}',
            $response->body,
        );
    }

    // ------------------------------------------------------------------
    // Family: no-body statuses.
    // ------------------------------------------------------------------

    public function testTwentyFourWirePinsCurrentNoBodyLaw(): void
    {
        // Hub-only API shape: no noContent() helper — controllers write
        // status(204). Workerman's encoder still decorates Server/Date/
        // Connection lines and a Content-Length: 0; the security-header
        // pass adds nosniff. Body section must be empty.
        $response = (new Response())->status(204);
        $wire = (string) $response->toWorkermanResponse();

        self::assertSame([], $response->headers);
        self::assertSame('', $response->body);
        self::assertStringStartsWith("HTTP/1.1 204 No Content\r\n", $wire);
        self::assertStringNotContainsString("\r\n\r\n\r\n", $wire);
        $headerBlockEnd = strpos($wire, "\r\n\r\n");
        self::assertIsInt($headerBlockEnd);
        self::assertSame('', substr($wire, $headerBlockEnd + 4));
        self::assertStringContainsString("X-Content-Type-Options: nosniff\r\n", $wire);
    }

    // ------------------------------------------------------------------
    // Family: HEAD replies — hub renders via headOnly(true) + the
    // caller's Content-Length (A-19 divergence vs server asHeadReply()).
    // ------------------------------------------------------------------

    public function testHeadReplyRendersHeadersOnlyAndKeepsCallerLength(): void
    {
        $response = (new Response())
            ->status(200)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Length', '11')
            ->headOnly(true);

        $wire = (string) $response->toWorkermanResponse();

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $wire);
        self::assertStringContainsString("Content-Length: 11\r\n", $wire);
        self::assertSame(1, substr_count($wire, 'Content-Length:'));
        $headerBlockEnd = strpos($wire, "\r\n\r\n");
        self::assertIsInt($headerBlockEnd);
        self::assertSame('', substr($wire, $headerBlockEnd + 4));
    }

    // ------------------------------------------------------------------
    // Family: CORS — hub policy NEVER offers credentials (A-19 ratified).
    // ------------------------------------------------------------------

    public function testPreflightAnswersTwoOhFourWithSortedMethodsAndNeverCredentials(): void
    {
        $policy = new CorsPolicy(['https://hub.example.test']);

        $request = new Request();
        $request->method = 'OPTIONS';
        $request->headers = [
            'origin' => 'https://hub.example.test',
            'access-control-request-headers' => 'authorization, content-type',
        ];

        $response = $policy->preflightResponse($request, ['POST', 'GET']);

        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body);
        self::assertSame(
            [
                'Access-Control-Allow-Methods' => 'GET, OPTIONS, POST',
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin',
                'Access-Control-Allow-Origin' => 'https://hub.example.test',
                'Access-Control-Allow-Headers' => 'authorization, content-type',
            ],
            $response->headers,
        );
        // A-19 divergence pin: bearer-auth hub REST never pairs ACAO with
        // credentials; the credentialed `true` literal must be absent.
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
    }

    public function testUnlistedOriginPreflightOmitsAllowOriginAndEchoHeaders(): void
    {
        $policy = new CorsPolicy(['https://hub.example.test']);

        $request = new Request();
        $request->method = 'OPTIONS';
        $request->headers = ['origin' => 'https://evil.example.test'];

        $response = $policy->preflightResponse($request, ['POST']);

        self::assertSame(
            [
                'Access-Control-Allow-Methods' => 'OPTIONS, POST',
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin',
            ],
            $response->headers,
        );
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function testDecorateEchoesAllowedOriginButNeverCredentials(): void
    {
        $policy = new CorsPolicy(['https://hub.example.test']);

        $request = new Request();
        $request->method = 'GET';
        $request->headers = ['origin' => 'https://hub.example.test'];

        $base = Application::rateLimitResponse(new RateLimitException(resetAt: 1));
        $decorated = $policy->decorate($request, $base);

        self::assertSame(
            [
                'Retry-After' => '0',
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
                'Vary' => 'Origin',
                'Access-Control-Allow-Origin' => 'https://hub.example.test',
            ],
            $decorated->headers,
        );
        self::assertStringNotContainsString('Credentials', (string) json_encode($decorated->headers));
        // Decoration never touches the body — the envelope is shared law.
        self::assertSame('{"error":"Too Many Requests","code":"rate_limited"}', $decorated->body);
    }

    public function testDecorateWithoutOriginPassesThroughUntouched(): void
    {
        $policy = new CorsPolicy(['https://hub.example.test']);

        $request = new Request();
        $request->method = 'GET';
        $request->headers = [];

        $base = Application::rateLimitResponse(new RateLimitException(resetAt: 1));
        $decorated = $policy->decorate($request, $base);

        self::assertSame(
            ['Retry-After', 'Content-Type', 'Cache-Control'],
            array_keys($decorated->headers),
        );
    }

    public function testDecorateAppendsVaryForDisallowedOriginAndOmitsAcao(): void
    {
        $policy = new CorsPolicy(['https://hub.example.test']);

        $request = new Request();
        $request->method = 'GET';
        $request->headers = ['origin' => 'https://evil.example.test'];

        $base = Application::rateLimitResponse(new RateLimitException(resetAt: 1));
        $decorated = $policy->decorate($request, $base);

        self::assertSame(
            [
                'Retry-After' => '0',
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
                'Vary' => 'Origin',
            ],
            $decorated->headers,
        );
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $decorated->headers);
    }
}
