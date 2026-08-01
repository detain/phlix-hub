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

    public function testDefaultsReturnsAllSixProfiles(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertCount(6, $defaults);
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
