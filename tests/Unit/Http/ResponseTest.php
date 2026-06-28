<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Response} cookie defaults (step S3).
 *
 * @package Phlix\Hub\Tests\Unit\Http
 *
 * @covers \Phlix\Hub\Http\Response
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
