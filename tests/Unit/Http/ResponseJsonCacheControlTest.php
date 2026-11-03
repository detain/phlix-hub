<?php

/**
 * Phlix hub component: tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Review hub Minor 48: every hub JSON response carries a Cache-Control.
 *
 * Before this law, `Response::json()` set only `Content-Type` while
 * `Response::html()` already seeded `no-cache` — browsers therefore cached
 * 401s, member-scoped lists and error envelopes HEURISTICALLY (historically
 * a real hazard: a 401 body surviving a re-login). `json()` now seeds
 * `no-store` with the exact same precedence law html() documents:
 *
 *  1. a Cache-Control set BEFORE the json()/error() call survives (JWKS's
 *     deliberate `public, max-age=3600`),
 *  2. a header() call AFTER the json() call overwrites (per-route opt-out),
 *  3. with neither, the default is `no-store` — the honest posture for
 *     session-scoped data (stricter than the shell's `no-cache`, which is
 *     right for a revalidatable document but not for authenticated payloads).
 *
 * @group unit
 */
final class ResponseJsonCacheControlTest extends TestCase
{
    public function testJsonSeedsNoStoreWhenCallerSetNothing(): void
    {
        $response = (new Response())->json(['ok' => true]);

        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame('application/json', $response->headers['Content-Type'] ?? null);
    }

    public function testErrorEnvelopeInheritsTheNoStoreSeed(): void
    {
        // error() delegates to json() — the 401/404/500 path is the one the
        // review named ("a cached 401 that outlives a re-login"), so the seed
        // must ride it without any caller cooperation.
        $response = (new Response())->error(401, 'unauthorized', 'Unauthorized');

        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
    }

    public function testPreSetCacheControlSurvivesTheSeed(): void
    {
        // HubJwksController's law: the JWKS document is deliberately public
        // and cacheable. The seed is if-absent only — an unconditional write
        // here reddens this pin.
        $response = (new Response())
            ->header('Cache-Control', 'public, max-age=3600')
            ->json(['keys' => []]);

        self::assertSame('public, max-age=3600', $response->headers['Cache-Control'] ?? null);
    }

    public function testHeaderAfterJsonOverwritesTheSeed(): void
    {
        // The documented escape hatch: header() assigns unconditionally, so a
        // route opting into a custom policy AFTER building the body wins.
        $response = (new Response())
            ->json(['ok' => true])
            ->header('Cache-Control', 'private, max-age=5');

        self::assertSame('private, max-age=5', $response->headers['Cache-Control'] ?? null);
    }

    public function testHtmlStillSeedsNoCacheNotNoStore(): void
    {
        // Cross-check that the two defaults did not get conflated: the SPA
        // shell must keep revalidating (no-cache), not refuse storage.
        $response = (new Response())->html('<!doctype html><p>x</p>');

        self::assertSame('no-cache', $response->headers['Cache-Control'] ?? null);
    }
}
