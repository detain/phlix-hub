<?php

/**
 * HUB-3.1 — port of the server's S435: route parameters must be percent-decoded
 * ONCE at the routing boundary.
 *
 * Before this step the hub Router extracted the RAW encoded path segment and
 * handed it to handlers verbatim, so `GET /api/v1/servers/Abbey%20Road` reached
 * the controller with the segment still percent-encoded — the same defect the
 * server fixed in S435, carried here by the shared router lineage.
 *
 * The hub has TWO extraction sites, not the server's one-plus-HEAD-fallback:
 * `Router::dispatch()` and `Router::matchRoutesForPath()` (the OPTIONS preflight
 * chain publishes its params through the latter). Both must decode, or an
 * authed preflight sees a different value shape than the real request.
 *
 * RED-ON-DRIFT CONTRACT (mirrors the S435 planted-drift proofs):
 *  - deleting `$params = $this->decodePathParams($params);` from
 *    `Router::dispatch()` reddens the space/unicode/plus/slash tests here BY
 *    NAME;
 *  - deleting it from `Router::matchRoutesForPath()` reddens
 *    testThePreflightChainSeesDecodedPathParams BY NAME;
 *  - decoding twice (boundary AND handler) reddens
 *    testPercentDoubleEncodingDecodesExactlyOnceThe2520Fixture.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Boundary-level pins for {@see Router} path-parameter percent-decoding.
 */
final class RouterPathParamDecodingTest extends TestCase
{
    /** HUB-3 lane survival marker — code-resident proof this class is live. */
    public const SURVIVAL_TOKEN = 'HUB3ROUTEPARAMDECODEX5R8';

    /**
     * The lane's survival token lives in code, never in markdown (P-1 law).
     */
    public function testSurvivalTokenIsCodeResident(): void
    {
        self::assertSame('HUB3ROUTEPARAMDECODEX5R8', self::SURVIVAL_TOKEN);
    }

    /**
     * The headline defect: `%20` in a path segment must reach the handler as a
     * space. REDS BY NAME if the dispatch boundary decode is removed.
     */
    public function testSpaceEncodedPathParamIsDecodedAtTheBoundary(): void
    {
        $captured = $this->dispatchServerSegment('Abbey%20Road');

        self::assertSame(
            'Abbey Road',
            $captured['id'],
            'a %20-encoded path segment must be handed to the handler decoded',
        );
    }

    /**
     * Unicode names ride percent-encoded UTF-8 bytes. REDS BY NAME if the
     * boundary decode is removed.
     */
    public function testUnicodeEncodedPathParamIsDecodedAtTheBoundary(): void
    {
        $captured = $this->dispatchServerSegment('%C3%89dith%20Piaf');

        self::assertSame(
            'Édith Piaf',
            $captured['id'],
            'percent-encoded UTF-8 (Édith Piaf) must decode to the real name',
        );
    }

    /**
     * %-POLICY (documented in Router::decodePathParams): a `+` in a PATH segment
     * is a LITERAL plus (RFC 3986 sub-delim); `+`-means-space belongs to
     * application/x-www-form-urlencoded QUERY strings only. So the boundary
     * decodes with rawurldecode(), and a name carrying a real plus —
     * `Black+Sabbath` — must survive verbatim. urldecode() would corrupt it.
     */
    public function testPlusSignInPathSegmentStaysALiteralPlus(): void
    {
        $literal = $this->dispatchServerSegment('Black+Sabbath');
        self::assertSame('Black+Sabbath', $literal['id']);

        // And a percent-encoded plus decodes to a literal plus — same name.
        $encoded = $this->dispatchServerSegment('Black%2BSabbath');
        self::assertSame('Black+Sabbath', $encoded['id']);
    }

    /**
     * The `%2520` fixture (planted-drift proof for DOUBLE-decoding): a name that
     * really contains the three characters `%20` arrives on the wire as
     * `%2520`. ONE boundary decode yields the literal `Abbey%20Road`; a second
     * decode anywhere downstream would collapse it to `Abbey Road` and resolve
     * the WRONG row. REDS if the fix ever decodes twice.
     */
    public function testPercentDoubleEncodingDecodesExactlyOnceThe2520Fixture(): void
    {
        $captured = $this->dispatchServerSegment('Abbey%2520Road');

        self::assertSame(
            'Abbey%20Road',
            $captured['id'],
            'decoding must happen EXACTLY once — %2520 yields a literal %20, never a space',
        );
    }

    /**
     * Malformed percent sequences never throw at the boundary: rawurldecode
     * leaves `%ZZ` verbatim, so the handler sees the faithful raw reading and
     * answers 404 for a name that could not have been registered this way.
     */
    public function testMalformedPercentSequencesPassThroughWithoutThrowing(): void
    {
        $garbage = $this->dispatchServerSegment('%ZZ%YY');
        self::assertSame('%ZZ%YY', $garbage['id']);

        $trailing = $this->dispatchServerSegment('Tupac%');
        self::assertSame('Tupac%', $trailing['id']);

        $lonePercent = $this->dispatchServerSegment('%');
        self::assertSame('%', $lonePercent['id']);
    }

    /**
     * Routing must keep matching the RAW path: `%2F` is not a segment
     * separator until AFTER the match, so an encoded slash stays inside the
     * one `{id}` segment and decodes to the real slash-name (AC/DC). Without
     * the ordering guarantee, `%2F` before matching would forge routes and
     * defeat upstream traversal guards.
     */
    public function testEncodedSlashDecodesToASlashNameAfterSingleSegmentMatching(): void
    {
        $captured = $this->dispatchServerSegment('AC%2FDC');

        self::assertSame(
            'AC/DC',
            $captured['id'],
            'a %2F-encoded slash must arrive as ONE decoded segment value',
        );
    }

    /**
     * …and a LITERAL slash still cannot split the parameter — the compiled
     * `[^/]+` class is untouched by the fix, so `%2F` decoding cannot smuggle
     * an extra segment through.
     */
    public function testLiteralSlashStillDoesNotMatchTheSingleSegmentPlaceholder(): void
    {
        $router = new Router();
        $reached = false;
        $router->get('/api/v1/servers/{id}', function () use (&$reached): Response {
            $reached = true;
            return (new Response())->status(200)->json(['ok' => true]);
        });

        $response = $router->dispatch($this->request('GET', '/api/v1/servers/AC/DC'));

        self::assertFalse($reached, 'a literal / must never cross a [^/]+ boundary');
        self::assertSame(404, $response->statusCode);
    }

    /**
     * `Request::$pathParams` (the published map middleware and handlers read)
     * must carry the decoded values too — it is written from the same
     * extraction. (The hub Request has no pathParam() accessor; the public
     * property is the wire shape here.)
     */
    public function testPublishedPathParamsAreDecoded(): void
    {
        $router = new Router();
        $requestSeen = null;
        $router->get('/api/v1/servers/{id}', function (Request $req) use (&$requestSeen): Response {
            $requestSeen = $req;
            return (new Response())->status(200)->json(['ok' => true]);
        });

        $router->dispatch($this->request('GET', '/api/v1/servers/Abbey%20Road'));

        self::assertInstanceOf(Request::class, $requestSeen);
        self::assertSame(['id' => 'Abbey Road'], $requestSeen->pathParams);
    }

    /**
     * HUB-SPECIFIC second extraction site: the OPTIONS preflight chain publishes
     * its params through `Router::matchRoutesForPath()`, and the hub's position
     * law (review `hub__crossfe` M9r3) runs the matched route's middleware AFTER
     * that publication — so a gate inspecting `{param}` segments must see the
     * SAME decoded shape the eventual dispatched request will. REDS BY NAME if
     * the decode is missing from the preflight arm (which the server's HEAD
     * test cannot catch, because the hub has no HEAD→GET fallback).
     */
    public function testThePreflightChainSeesDecodedPathParams(): void
    {
        /** @var array<string, string>|null $seen */
        $seen = null;
        $captureGate = function (Request $req) use (&$seen): ?Response {
            $seen = $req->pathParams;
            return null;
        };
        $ok = static fn (Request $r): Response => (new Response())->status(200)->json(['ok' => true]);

        $router = new Router();
        $router->group('/api/v1/servers', static function (Router $r) use ($ok): void {
            $r->get('/{id}', $ok);
        }, [$captureGate]);

        $router->dispatch($this->request('OPTIONS', '/api/v1/servers/Abbey%20Road'));

        self::assertIsArray($seen, 'the preflight chain must have run its gate');
        self::assertSame(
            'Abbey Road',
            $seen['id'] ?? null,
            'the preflight arm must decode at the same boundary as dispatch()',
        );
    }

    /**
     * Dispatch one raw (still-encoded) segment through the real servers route
     * pattern and hand back what the handler receives.
     *
     * @return array<string, string> The params passed to the handler
     */
    private function dispatchServerSegment(string $rawSegment): array
    {
        $router = new Router();
        /** @var array<string, string>|null $captured */
        $captured = null;
        $router->get('/api/v1/servers/{id}', function (Request $req, array $params) use (&$captured): Response {
            $captured = $params;
            return (new Response())->status(200)->json(['ok' => true]);
        });

        $router->dispatch($this->request('GET', '/api/v1/servers/' . $rawSegment));

        self::assertIsArray($captured, 'the route must have matched and reached the handler');
        return $captured;
    }

    private function request(string $method, string $path): Request
    {
        $r = new Request();
        $r->method = $method;
        $r->path = $path;
        return $r;
    }
}
