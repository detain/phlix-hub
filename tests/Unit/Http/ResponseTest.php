<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Response} cookie defaults (step S3).
 *
 * @package Phlix\Hub\Tests\Unit\Http
 */
final class ResponseTest extends TestCase
{
    /**
     * Restore the HUB_COOKIE_INSECURE override after each test so the
     * secure-by-default assertion in one test cannot leak into another.
     */
    protected function tearDown(): void
    {
        putenv('HUB_COOKIE_INSECURE');
    }

    public function testCookieIsSecureByDefault(): void
    {
        putenv('HUB_COOKIE_INSECURE');

        $response = (new Response())->cookie('phlix_hub_token', 'abc', 3600);

        self::assertCount(1, $response->cookies);
        self::assertTrue($response->cookies[0]['secure'], 'cookies must default to Secure');
        self::assertTrue($response->cookies[0]['http_only']);
        self::assertSame('Lax', $response->cookies[0]['same_site']);
    }

    public function testHubCookieInsecureEnvDisablesSecure(): void
    {
        putenv('HUB_COOKIE_INSECURE=1');

        $response = (new Response())->cookie('phlix_hub_token', 'abc', 3600);

        self::assertFalse($response->cookies[0]['secure'], 'HUB_COOKIE_INSECURE=1 must drop Secure for local HTTP dev');
    }

    /**
     * HUB-ENVBOOL ratified-strict census pin (twin of CookiePolicyTest): the
     * Secure-DROP knob accepts ONLY the literal '1'/'true' spellings. The
     * estate bool grammar (yes/on/case-fold/padded) would WIDEN who may drop
     * cookie Secure — a security-surface change, not a cleanup — which is
     * why secureCookiesDefault() keeps its local strict dialect instead of
     * EnvConfig::bool. The two readers of one knob must always answer alike;
     * converging either one alone splits them (both docblocks say so). This
     * test is the tripwire: green under the law, red if a lane loosens it.
     */
    public function testHubCookieInsecureAcceptSetIsStrictByLaw(): void
    {
        foreach (['yes', 'on', 'TRUE', 'True', ' 1 ', "\ttrue", 'maybe'] as $spelling) {
            putenv('HUB_COOKIE_INSECURE=' . $spelling);

            $response = (new Response())->cookie('phlix_hub_token', 'abc', 3600);

            self::assertTrue(
                $response->cookies[0]['secure'],
                "HUB_COOKIE_INSECURE='{$spelling}' must NOT drop Secure — the accept set is law"
            );
        }
    }

    public function testExplicitSecureArgumentOverridesEnv(): void
    {
        putenv('HUB_COOKIE_INSECURE=1');

        // Explicit true must win over the insecure env override.
        $response = (new Response())->cookie('forced', 'v', 0, '/', true, true, 'Strict');
        self::assertTrue($response->cookies[0]['secure']);

        // Explicit false must win over the (secure) default.
        putenv('HUB_COOKIE_INSECURE');
        $response2 = (new Response())->cookie('forced', 'v', 0, '/', true, false, 'Strict');
        self::assertFalse($response2->cookies[0]['secure']);
    }
}
