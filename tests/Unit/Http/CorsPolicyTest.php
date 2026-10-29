<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\CorsPolicy;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see CorsPolicy} — the hub REST surface's default-deny
 * cross-origin gate (review hub__crossfe MAJOR-1).
 *
 * The allowlist is parse-at-the-boundary: a malformed entry is a loud
 * construction failure, never a silent drop to deny-all on a typo. The runtime
 * question is exactly two-valued: is this origin on the list, yes or no.
 */
final class CorsPolicyTest extends TestCase
{
    private function requestWithOrigin(?string $origin): Request
    {
        $request = new Request();
        if ($origin !== null) {
            // Request headers arrive uppercase-dashed from Workerman; prove the
            // policy reads through Request::getHeader()'s case-insensitive path.
            $request->headers['ORIGIN'] = $origin;
        }
        return $request;
    }

    // ---- allowlist parsing ----

    public function testEmptyAllowListDeniesEveryOrigin(): void
    {
        $policy = new CorsPolicy([]);

        self::assertFalse($policy->allowsOrigin('https://evil.example'));
        self::assertFalse($policy->allowsOrigin('app://-'));
        self::assertFalse($policy->allowsOrigin(null));
    }

    public function testAllowListMatchesExactOriginsOnly(): void
    {
        $policy = new CorsPolicy(['https://hub.example.com', 'app://-']);

        self::assertTrue($policy->allowsOrigin('https://hub.example.com'));
        self::assertTrue($policy->allowsOrigin('app://-'));
        // prefix/suffix games must not match
        self::assertFalse($policy->allowsOrigin('https://hub.example.com.evil.test'));
        self::assertFalse($policy->allowsOrigin('https://hub.example.com/any/path'));
        self::assertFalse($policy->allowsOrigin('http://hub.example.com'));
    }

    public function testBlankEntriesAreIgnored(): void
    {
        $policy = new CorsPolicy([' ', '', 'app://-', '']);

        self::assertTrue($policy->allowsOrigin('app://-'));
        self::assertFalse($policy->allowsOrigin('https://hub.example.com'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedOriginProvider(): array
    {
        return [
            'wildcard' => ['*'],
            'scheme-only-wildcard' => ['https://*'],
            'carries-a-path' => ['https://hub.example.com/ui'],
            'uppercase-host' => ['https://Hub.Example.com'],
            'uppercase-scheme' => ['HTTPS://hub.example.com'],
            'no-scheme' => ['hub.example.com'],
            'embedded-space' => ['https://ex ample.test'],
            'query-string' => ['https://hub.example.com?x=1'],
            'fragment' => ['https://hub.example.com#'],
            'trailing-slash' => ['https://hub.example.com/'],
        ];
    }

    /**
     * @dataProvider malformedOriginProvider
     */
    public function testMalformedEntryFailsLoudAtConstruction(string $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid CORS allow-origin entry/');

        new CorsPolicy([$entry]);
    }

    public function testPortsAndHyphenatedHostsAreValidOrigins(): void
    {
        $policy = new CorsPolicy(['http://localhost:8080', 'https://hub.example.com:8443']);

        self::assertTrue($policy->allowsOrigin('http://localhost:8080'));
        self::assertTrue($policy->allowsOrigin('https://hub.example.com:8443'));
    }

    // ---- environment plumbing ----

    public function testUnsetEnvironmentYieldsTheDenyAllDefault(): void
    {
        $previous = getenv(CorsPolicy::ENV_ALLOW_ORIGINS);
        putenv(CorsPolicy::ENV_ALLOW_ORIGINS);
        try {
            $policy = CorsPolicy::fromEnvironment();
            self::assertFalse($policy->allowsOrigin('https://anything.test'));
        } finally {
            self::restoreEnv($previous);
        }
    }

    public function testEnvironmentListIsCommaSplitAndTrimmed(): void
    {
        $previous = getenv(CorsPolicy::ENV_ALLOW_ORIGINS);
        putenv(CorsPolicy::ENV_ALLOW_ORIGINS . '=https://hub.example.com, app://- ,');
        try {
            $policy = CorsPolicy::fromEnvironment();
            self::assertTrue($policy->allowsOrigin('https://hub.example.com'));
            self::assertTrue($policy->allowsOrigin('app://-'));
            self::assertFalse($policy->allowsOrigin('https://other.test'));
        } finally {
            self::restoreEnv($previous);
        }
    }

    public function testMalformedEnvironmentFailsLoudAtConstruction(): void
    {
        $previous = getenv(CorsPolicy::ENV_ALLOW_ORIGINS);
        putenv(CorsPolicy::ENV_ALLOW_ORIGINS . '=https://good.test,*');
        try {
            $this->expectException(\InvalidArgumentException::class);
            CorsPolicy::fromEnvironment();
        } finally {
            self::restoreEnv($previous);
        }
    }

    private static function restoreEnv(string|false $previous): void
    {
        if ($previous === false) {
            putenv(CorsPolicy::ENV_ALLOW_ORIGINS);
            return;
        }
        putenv(CorsPolicy::ENV_ALLOW_ORIGINS . '=' . $previous);
    }

    // ---- preflight response shape ----

    public function testAllowedPreflightCarriesTheExactHeaderSet(): void
    {
        $policy = new CorsPolicy(['app://-']);
        $request = $this->requestWithOrigin('app://-');
        $request->headers['ACCESS-CONTROL-REQUEST-HEADERS'] = 'Authorization, Content-Type, X-Phlix-Device-Id';

        $response = $policy->preflightResponse($request, ['POST', 'GET']);

        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body, 'a preflight carries no body');
        self::assertSame('app://-', $response->headers['Access-Control-Allow-Origin']);
        // OPTIONS is always truthful for a path the router answers; the list is sorted.
        self::assertSame('GET, OPTIONS, POST', $response->headers['Access-Control-Allow-Methods']);
        self::assertSame(
            'Authorization, Content-Type, X-Phlix-Device-Id',
            $response->headers['Access-Control-Allow-Headers'],
        );
        self::assertSame('600', $response->headers['Access-Control-Max-Age']);
        self::assertSame('Origin', $response->headers['Vary']);
        // credentials are never offered: bearer tokens authenticate hub REST.
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
    }

    public function testPreflightWithoutRequestedHeadersOmitsAllowHeaders(): void
    {
        $policy = new CorsPolicy(['app://-']);
        $response = $policy->preflightResponse($this->requestWithOrigin('app://-'), ['GET']);

        self::assertSame(204, $response->statusCode);
        self::assertArrayNotHasKey('Access-Control-Allow-Headers', $response->headers);
        self::assertSame('GET, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
    }

    public function testNoOriginPreflightIsTruthfulButGrantsNothing(): void
    {
        // A fetch can never send an OPTIONS without Origin; a curl probe may.
        // It gets the verb list — no ACAO, so no browser can consume it.
        $policy = new CorsPolicy(['app://-']);
        $response = $policy->preflightResponse($this->requestWithOrigin(null), ['GET']);

        self::assertSame(204, $response->statusCode);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertSame('GET, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
    }

    // ---- response decoration ----

    public function testAllowedOriginDecoratesActualResponseWithEchoAndVary(): void
    {
        $policy = new CorsPolicy(['https://hub.example.com']);
        $response = (new Response())->status(200)->json(['ok' => true]);

        $decorated = $policy->decorate($this->requestWithOrigin('https://hub.example.com'), $response);

        self::assertSame('https://hub.example.com', $decorated->headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $decorated->headers['Vary']);
    }

    public function testDisallowedOriginGetsVaryButNeverAllowOrigin(): void
    {
        $policy = new CorsPolicy(['https://hub.example.com']);
        $decorated = $policy->decorate($this->requestWithOrigin('https://evil.test'), (new Response())->status(200));

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $decorated->headers);
        self::assertSame('Origin', $decorated->headers['Vary'], 'deny responses must not be cache-shareable either');
    }

    public function testSameOriginRequestWithoutOriginHeaderIsUntouched(): void
    {
        $policy = new CorsPolicy(['app://-']);
        $plain = (new Response())->status(200)->json(['ok' => true]);
        $decorated = $policy->decorate($this->requestWithOrigin(null), $plain);

        self::assertSame($plain, $decorated, 'no Origin header means the reply is returned as-built, no header added');
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $decorated->headers);
        self::assertArrayNotHasKey('Vary', $decorated->headers);
    }

    public function testDecorationMergesIntoAnExistingVaryWithoutDuplicating(): void
    {
        $policy = new CorsPolicy(['app://-']);
        $response = (new Response())->status(200)->header('vary', 'Accept-Encoding');

        $once = $policy->decorate($this->requestWithOrigin('app://-'), $response);
        self::assertSame(
            'Accept-Encoding, Origin',
            $once->headers['vary'],
            'merge onto the name as set, lowercase included',
        );

        $twice = $policy->decorate($this->requestWithOrigin('app://-'), $once);
        self::assertSame('Accept-Encoding, Origin', $twice->headers['vary'], 're-decoration must not repeat Origin');
    }

    public function testDecorationDoesNotOverwriteAnExplicitAllowOrigin(): void
    {
        $policy = new CorsPolicy(['app://-']);
        $response = (new Response())->status(200)->header('Access-Control-Allow-Origin', 'https://curated.test');

        $decorated = $policy->decorate($this->requestWithOrigin('app://-'), $response);

        self::assertSame('https://curated.test', $decorated->headers['Access-Control-Allow-Origin']);
    }
}
