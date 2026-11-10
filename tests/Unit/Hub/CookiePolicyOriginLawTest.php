<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\CookiePolicy;
use PHPUnit\Framework\TestCase;

/**
 * CP-012 (COOKIE-DESIGN §2.5/§4.2, hub twin of the server's ratified
 * validateCsrfOrigin/validateSameSiteOrigin in RequestAuthenticator): the
 * pure Origin/Referer law behind the hub's CSRF Origin guard. The shape is
 * adopted from the server law (not literals): state-changing cookie-lineage
 * requests must name this host — no Origin AND no Referer is refused
 * fail-closed, the literal `null` Origin is refused outright (M-SERVER-76),
 * hosts compare EXACTLY (suffix tricks die) and ports must both-be-present-
 * and-equal or both-be-absent (the server does NOT normalize default ports
 * on this axis — stricter than the issuance-side originIsSameSite(), which
 * normalizes; the two twins differ from each other the same way the
 * server's own pair of laws does, disclosed at the method docblock).
 *
 * The `Sec-Fetch-Site: cross-site` reject is the §2.5 design ADD the server
 * has not grown yet: a present, honest cross-site signal never outruns the
 * guard even when a forged same-host Origin accompanies it.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class CookiePolicyOriginLawTest extends TestCase
{
    private const HOST = 'hub.example.test';

    private function law(
        ?string $origin,
        ?string $referer = null,
        ?string $secFetchSite = null,
        ?string $host = self::HOST,
    ): bool {
        return CookiePolicy::stateChangingRequestOriginAllowed($origin, $referer, $host, $secFetchSite);
    }

    public function testNoOriginAndNoRefererIsRefusedFailClosed(): void
    {
        // Server law: legitimate browsers always send at least one on a
        // state-changing same-site request — a request naming no origin at
        // all is refused rather than trusted.
        self::assertFalse($this->law(null, null));
    }

    public function testLiteralNullOriginIsRefusedEvenWithSameSiteReferer(): void
    {
        // M-SERVER-76 mirror: `Origin: null` is the opaque-origin marker
        // (sandboxed iframes); a cookie-auth write that cannot name its
        // origin is refused outright, REFERER PRESENT OR NOT — the server
        // checks this limb before either header leg.
        self::assertFalse($this->law('null'));
        self::assertFalse($this->law('null', 'https://' . self::HOST . '/app/settings'));
    }

    public function testNullOriginWithEmptyHostHeaderIsRefusedByTheLimbAlone(): void
    {
        // The limb is LOAD-BEARING, not redundant: parse_url('null') yields
        // an EMPTY host, and an empty Host header (HTTP/1.0 shape) parses to
        // the same empty host — without the explicit refusal the two ''
        // would MATCH and an opaque-origin sandbox write would pass the
        // generic legs by coincidence. This is the input the M-SERVER-76
        // limb exists for.
        self::assertFalse($this->law('null', null, null, ''));
    }

    public function testEmptyStringOriginAlonePassesAsServerRatifiedQuirk(): void
    {
        // Server-ratified shape DISCLOSED and mirrored verbatim: the
        // fail-closed limb checks for NULL headers, and the Origin leg skips
        // the empty string (M-SERVER-76 names only the literal 'null'), so
        // `Origin: ` arriving WITHOUT a Referer walks to the accept tail.
        // A browser cannot produce this frame on a state-changing request;
        // pinning the quirk keeps the twin honest — changing it is a design
        // decision for BOTH repos, not a hub-side drift.
        self::assertTrue($this->law('', null));
    }

    public function testSameHostOriginWithoutPortPasses(): void
    {
        self::assertTrue($this->law('https://' . self::HOST));
    }

    public function testForeignOriginIsRefused(): void
    {
        self::assertFalse($this->law('https://evil.example'));
    }

    public function testSuffixTrickOriginIsRefused(): void
    {
        // Exact host comparison: hub.example.test.evil.example is NOT a
        // suffix-match of hub.example.test.
        self::assertFalse($this->law('https://hub.example.test.evil.example'));
        self::assertFalse($this->law('https://evil-hub.example.test'));
    }

    public function testExplicitPortMustMatchServerPort(): void
    {
        self::assertTrue($this->law('https://' . self::HOST . ':8443', null, null, self::HOST . ':8443'));
        self::assertFalse($this->law('https://' . self::HOST . ':9999', null, null, self::HOST . ':8443'));
    }

    public function testDefaultPortIsNotNormalizedOnThisAxis(): void
    {
        // Server law: both sides present a port or neither does — Origin
        // :443 against a port-less Host header is REFUSED (the issuance-side
        // originIsSameSite() would accept it; the guard stays on the
        // ratified server shape, disclosed at the method docblock).
        self::assertFalse($this->law('https://' . self::HOST . ':443', null, null, self::HOST));
    }

    public function testRefererWithSameHostPassesAndPathIsIgnored(): void
    {
        self::assertTrue($this->law(null, 'https://' . self::HOST . '/app/settings?page=1'));
    }

    public function testForeignRefererIsRefused(): void
    {
        self::assertFalse($this->law(null, 'https://evil.example/app'));
    }

    public function testUnparseableRefererIsRefused(): void
    {
        // parse_url('not a url') yields a path-only array — host '' never
        // matches the server host, so the exact-match limb refuses it.
        self::assertFalse($this->law(null, 'not a url'));
    }

    public function testEvilRefererCannotHideBehindGoodOrigin(): void
    {
        // Both legs run: a good Origin does not excuse a forged Referer
        // (server checks Referer first; either mismatch is a refusal).
        self::assertFalse($this->law('https://' . self::HOST, 'https://evil.example/'));
        self::assertFalse($this->law('https://evil.example', 'https://' . self::HOST . '/'));
    }

    public function testBothHeadersGoodPasses(): void
    {
        self::assertTrue($this->law('https://' . self::HOST, 'https://' . self::HOST . '/app'));
    }

    public function testHostComparisonIsCaseSensitiveAsServerRatified(): void
    {
        // The server compares parsed hosts RAW (no lowercasing): a
        // mixed-case same host fails. Browsers send lowercase hosts; the
        // fail-closed direction is the ratified shape.
        self::assertFalse($this->law('https://HUB.EXAMPLE.TEST'));
    }

    public function testBracketedIpv6HostPairMatches(): void
    {
        self::assertTrue($this->law('http://[::1]:8800', null, null, '[::1]:8800'));
        self::assertFalse($this->law('http://[::1]:8800', null, null, '[::1]:9900'));
    }

    public function testSecFetchSiteCrossSiteBeltRejectsEvenWithGoodOrigin(): void
    {
        // §2.5 design ADD: an honest UA that reports cross-site is refused
        // regardless of the header legs — the belt closes the forged-
        // same-host-Origin hole the header law alone cannot see.
        self::assertFalse($this->law('https://' . self::HOST, null, 'cross-site'));
        self::assertFalse($this->law('https://' . self::HOST, 'https://' . self::HOST, 'Cross-Site'));
    }

    public function testSecFetchSiteOtherValuesDoNotTouchTheLaw(): void
    {
        self::assertTrue($this->law('https://' . self::HOST, null, 'same-origin'));
        self::assertTrue($this->law('https://' . self::HOST, null, 'same-site'));
        self::assertTrue($this->law('https://' . self::HOST, null, 'none'));
        self::assertTrue($this->law('https://' . self::HOST, null, ''));
        // Absent belt = today's header law alone (native clients send
        // nothing here and must keep working through their own carriers).
        self::assertTrue($this->law('https://' . self::HOST, null, null));
    }

    public function testEmptyHostHeaderRefusesNamedOrigins(): void
    {
        // HTTP/1.0-style missing Host: the server-parts fallback keeps the
        // raw (empty) host, so any named origin mismatches → refusal
        // (same shape as the server's parseHostHeader tail).
        self::assertFalse($this->law('https://' . self::HOST, null, null, ''));
    }
}
