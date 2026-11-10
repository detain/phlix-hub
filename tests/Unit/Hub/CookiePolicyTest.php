<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\CookiePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * CP-011 unit law for {@see CookiePolicy} (COOKIE-DESIGN §2.1/§2.4,
 * CD-3a/CD-8a): the three independent decisions — custody CLASS, master
 * SWITCH, scheme LAW — over raw header values and injected resolvers.
 * Pure: no Request, no container, no DB.
 */
final class CookiePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        putenv(CookiePolicy::INSECURE_ENV);
    }

    protected function tearDown(): void
    {
        putenv(CookiePolicy::INSECURE_ENV);
        parent::tearDown();
    }

    private static function policy(?bool $enabled = null, ?bool $insecure = null): CookiePolicy
    {
        return new CookiePolicy(
            $enabled === null ? null : static fn (): bool => $enabled,
            $insecure === null ? null : static fn (): bool => $insecure,
        );
    }

    public function testMasterSwitchFailOpenWithoutAnyResolver(): void
    {
        // No pool under PHPUnit: the canonical resolver degrades to its
        // bootDefault — enabled (a settings outage must never strand login).
        self::assertTrue(self::policy()->cookieModeEnabled());
    }

    public function testExplicitFalseDisablesButAnyOtherReadingStaysEnabled(): void
    {
        self::assertFalse(self::policy(false)->cookieModeEnabled());
        self::assertTrue(self::policy(true)->cookieModeEnabled());
    }

    public function testThrowingEnabledResolverFailsOpenToEnabled(): void
    {
        $policy = new CookiePolicy(
            static function (): bool {
                throw new RuntimeException('store down');
            },
        );
        self::assertTrue($policy->cookieModeEnabled());
    }

    public function testInsecureOverrideAcceptsEnvFormsAndFailsClosed(): void
    {
        putenv(CookiePolicy::INSECURE_ENV . '=1');
        self::assertTrue(self::policy()->insecureOverride());

        putenv(CookiePolicy::INSECURE_ENV . '=true');
        self::assertTrue(self::policy()->insecureOverride());

        putenv(CookiePolicy::INSECURE_ENV . '=0');
        self::assertFalse(self::policy(false)->insecureOverride());

        putenv(CookiePolicy::INSECURE_ENV);
        self::assertFalse(self::policy()->insecureOverride());
    }

    public function testStoredInsecureTwinHonoredAndThrowFailsClosed(): void
    {
        self::assertTrue(self::policy(insecure: true)->insecureOverride());

        $policy = new CookiePolicy(
            null,
            static function (): bool {
                throw new RuntimeException('store down');
            },
        );
        self::assertFalse($policy->insecureOverride());
    }

    public function testCookiesSecureIsTheOverrideInverse(): void
    {
        self::assertTrue(self::policy()->cookiesSecure());
        self::assertFalse(self::policy(insecure: true)->cookiesSecure());
    }

    public function testSchemeEligibilityFirstHopHttpsLocalhostAndOverride(): void
    {
        $policy = self::policy();

        self::assertTrue($policy->schemeEligible('https', 'hub.example.test'));
        self::assertTrue($policy->schemeEligible('HTTPS, http', 'hub.example.test'));
        self::assertFalse($policy->schemeEligible('http, https', 'hub.example.test'));
        self::assertTrue($policy->schemeEligible('http', 'localhost:8800'));
        self::assertTrue($policy->schemeEligible('http', '127.0.0.1'));
        self::assertTrue($policy->schemeEligible(null, '[::1]:8800'));
        self::assertFalse($policy->schemeEligible('http', 'hub.example.test'));
        self::assertFalse($policy->schemeEligible(null, null));

        // The override bypasses the whole scheme gate.
        self::assertTrue(self::policy(insecure: true)->schemeEligible('http', 'lan.example.test'));
    }

    public function testCustodyModeDeviceMarkerWinsEverything(): void
    {
        $policy = self::policy();

        // Browser signals present, device marker still vetoes.
        self::assertSame(
            CookiePolicy::MODE_BEARER,
            $policy->custodyMode('roku', 'https://hub.example.test', 'hub.example.test', 'same-origin'),
        );
    }

    public function testCustodyModeForeignAndWebviewOriginsAreDeviceClass(): void
    {
        $policy = self::policy();

        foreach (['https://evil.example', 'app://-', 'capacitor://localhost', 'file://'] as $origin) {
            self::assertSame(
                CookiePolicy::MODE_BEARER,
                $policy->custodyMode(null, $origin, 'hub.example.test', null),
                "Origin {$origin} must classify device-side (§2.1).",
            );
        }
    }

    public function testCustodyModeSameSiteBrowserSignalsAreCookieClass(): void
    {
        $policy = self::policy();

        self::assertSame(
            CookiePolicy::MODE_COOKIE,
            $policy->custodyMode(null, 'https://hub.example.test', 'hub.example.test', 'same-site'),
        );
        // No Origin (curl-era privacy) but Sec-Fetch names a same-origin fetch.
        self::assertSame(
            CookiePolicy::MODE_COOKIE,
            $policy->custodyMode(null, null, 'hub.example.test', 'same-origin'),
        );
    }

    public function testCustodyModeNoSignalAtAllStaysBearerByteIdentical(): void
    {
        // The central non-regression premise: legacy natives send no Origin,
        // no Sec-Fetch, no device marker (older builds) — they must land in
        // MODE_BEARER, i.e. today's wire, untouched.
        self::assertSame(
            CookiePolicy::MODE_BEARER,
            self::policy()->custodyMode(null, null, 'hub.example.test', null),
        );
    }

    public function testOriginSameSitePortNormalization(): void
    {
        self::assertTrue(CookiePolicy::originIsSameSite('https://hub.example.test', 'hub.example.test:443'));
        self::assertTrue(CookiePolicy::originIsSameSite('https://hub.example.test:443', 'hub.example.test'));
        self::assertTrue(CookiePolicy::originIsSameSite('http://localhost:8800', 'localhost:8800'));
        self::assertFalse(CookiePolicy::originIsSameSite('https://hub.example.test:8443', 'hub.example.test'));
        self::assertFalse(CookiePolicy::originIsSameSite('https://other.example.test', 'hub.example.test'));
    }

    public function testHttpsForwardedReaderChainsTakeFirstHopOnly(): void
    {
        self::assertTrue(CookiePolicy::isHttpsForwarded('https'));
        self::assertTrue(CookiePolicy::isHttpsForwarded('https, http'));
        self::assertFalse(CookiePolicy::isHttpsForwarded('http, https'));
        self::assertFalse(CookiePolicy::isHttpsForwarded(''));
        self::assertFalse(CookiePolicy::isHttpsForwarded(null));
    }

    public function testCookieNamesAreTheHubLegacySurfaceDistinctFromServer(): void
    {
        // Cookie jar on a shared host is port-AGNOSTIC: the hub must never
        // mint the server's phlix_session/phlix_refresh names (cross-service
        // clobber). The names below are the deleted-surface names from the
        // AuthMiddleware history — reviving them keeps history honest.
        self::assertSame('phlix_hub_token', CookiePolicy::SESSION_COOKIE);
        self::assertSame('phlix_hub_refresh', CookiePolicy::REFRESH_COOKIE);
        self::assertSame('auth.cookie.enabled', CookiePolicy::COOKIE_ENABLED_KEY);
        self::assertSame('auth.cookie.insecure', CookiePolicy::COOKIE_INSECURE_KEY);
        self::assertSame('HUB_COOKIE_INSECURE', CookiePolicy::INSECURE_ENV);
    }
}
