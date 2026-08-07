<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\RateLimit;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;

/**
 * Unit tests for {@see RateLimitProfiles}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\RateLimit
 *
 * @covers \Phlix\Hub\Common\RateLimit\RateLimitProfiles
 */
final class RateLimitProfilesTest extends TestCase
{
    public function testLoginProfileExists(): void
    {
        self::assertSame('rate_limiter.login', RateLimitProfiles::LOGIN);
    }

    public function testProxyProfileExists(): void
    {
        self::assertSame('rate_limiter.proxy', RateLimitProfiles::PROXY);
    }

    public function testHeartbeatProfileExists(): void
    {
        self::assertSame('rate_limiter.heartbeat', RateLimitProfiles::HEARTBEAT);
    }

    public function testJwksProfileExists(): void
    {
        self::assertSame('rate_limiter.jwks', RateLimitProfiles::JWKS);
    }

    public function testRelayConnectProfileExists(): void
    {
        self::assertSame('rate_limiter.relay_connect', RateLimitProfiles::RELAY_CONNECT);
    }

    public function testClientMountProfileExists(): void
    {
        self::assertSame('rate_limiter.client_mount', RateLimitProfiles::CLIENT_MOUNT);
    }

    public function testMcpProfileExists(): void
    {
        self::assertSame('rate_limiter.mcp', RateLimitProfiles::MCP);
    }

    public function testAlexaProfileExists(): void
    {
        self::assertSame('rate_limiter.alexa', RateLimitProfiles::ALEXA);
    }

    public function testDefaultsReturnsAllEightProfiles(): void
    {
        $defaults = RateLimitProfiles::defaults();

        // Eight since S91 added `alexa` (seven since S62 added `mcp`). This count
        // is the whole reason the test exists: it turns "a profile was added but
        // never given a config key or a default" into a red, so bumping the
        // number without adding the matching `testDefaultsContains…Profile` below
        // defeats it.
        self::assertCount(8, $defaults);
    }

    public function testDefaultsContainsLoginProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::LOGIN, $defaults);
        self::assertSame('login', $defaults[RateLimitProfiles::LOGIN]['key']);
        self::assertSame(5, $defaults[RateLimitProfiles::LOGIN]['max']);
        self::assertSame(900, $defaults[RateLimitProfiles::LOGIN]['window']);
    }

    public function testDefaultsContainsProxyProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::PROXY, $defaults);
        self::assertSame('proxy', $defaults[RateLimitProfiles::PROXY]['key']);
        self::assertSame(600, $defaults[RateLimitProfiles::PROXY]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::PROXY]['window']);
    }

    public function testDefaultsContainsHeartbeatProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::HEARTBEAT, $defaults);
        self::assertSame('heartbeat', $defaults[RateLimitProfiles::HEARTBEAT]['key']);
        self::assertSame(30, $defaults[RateLimitProfiles::HEARTBEAT]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::HEARTBEAT]['window']);
    }

    public function testDefaultsContainsJwksProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::JWKS, $defaults);
        self::assertSame('jwks', $defaults[RateLimitProfiles::JWKS]['key']);
        self::assertSame(120, $defaults[RateLimitProfiles::JWKS]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::JWKS]['window']);
    }

    public function testDefaultsContainsRelayConnectProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::RELAY_CONNECT, $defaults);
        self::assertSame('relay_connect', $defaults[RateLimitProfiles::RELAY_CONNECT]['key']);
        self::assertSame(10, $defaults[RateLimitProfiles::RELAY_CONNECT]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::RELAY_CONNECT]['window']);
    }

    public function testDefaultsContainsClientMountProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::CLIENT_MOUNT, $defaults);
        self::assertSame('client_mount', $defaults[RateLimitProfiles::CLIENT_MOUNT]['key']);
        self::assertSame(30, $defaults[RateLimitProfiles::CLIENT_MOUNT]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::CLIENT_MOUNT]['window']);
    }

    public function testDefaultsContainsMcpProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::MCP, $defaults);
        self::assertSame('mcp', $defaults[RateLimitProfiles::MCP]['key']);
        self::assertSame(10, $defaults[RateLimitProfiles::MCP]['max']);
        self::assertSame(900, $defaults[RateLimitProfiles::MCP]['window']);
    }

    public function testDefaultsContainsAlexaProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::ALEXA, $defaults);
        self::assertSame('alexa', $defaults[RateLimitProfiles::ALEXA]['key']);
        self::assertSame(60, $defaults[RateLimitProfiles::ALEXA]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::ALEXA]['window']);
    }

    /**
     * S62: the MCP profile must be a LOGIN-GRADE budget, not a proxy-grade one.
     * Presenting a bearer PAT is a credential guess, and the 600/60 the proxy
     * uses would let an attacker try ten a second. Pinned as a relationship to
     * the login profile rather than as a bare number, so re-tuning login without
     * thinking about MCP goes red.
     */
    public function testTheMcpBudgetIsLoginGradeNotProxyGrade(): void
    {
        $defaults = RateLimitProfiles::defaults();

        $mcp = $defaults[RateLimitProfiles::MCP];
        $login = $defaults[RateLimitProfiles::LOGIN];
        $proxy = $defaults[RateLimitProfiles::PROXY];

        self::assertSame(
            $login['window'],
            $mcp['window'],
            'the MCP window drifted from login\'s; both count failed credential presentations.',
        );
        self::assertLessThan(
            $proxy['max'],
            $mcp['max'],
            'the MCP budget is as generous as the PROXY budget, which is sized for HLS segment '
            . 'bursts, not for credential guessing.',
        );
        self::assertGreaterThanOrEqual(
            $login['max'],
            $mcp['max'],
            'an agent legitimately retries more than a human, so the MCP budget should not be '
            . 'tighter than login\'s.',
        );
    }

    public function testDefaultsReturnsCorrectStructure(): void
    {
        $defaults = RateLimitProfiles::defaults();

        foreach ($defaults as $profile) {
            self::assertArrayHasKey('key', $profile);
            self::assertArrayHasKey('max', $profile);
            self::assertArrayHasKey('window', $profile);
            self::assertIsString($profile['key']);
            self::assertIsInt($profile['max']);
            self::assertIsInt($profile['window']);
            self::assertGreaterThan(0, $profile['max']);
            self::assertGreaterThan(0, $profile['window']);
        }
    }
}
