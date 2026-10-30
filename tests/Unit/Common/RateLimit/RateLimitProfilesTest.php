<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\RateLimit;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;

/**
 * Unit tests for {@see RateLimitProfiles}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\RateLimit
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

    public function testServerClaimsProfileExists(): void
    {
        self::assertSame('rate_limiter.server_claims', RateLimitProfiles::SERVER_CLAIMS);
    }

    public function testSignupProfileExists(): void
    {
        self::assertSame('rate_limiter.signup', RateLimitProfiles::SIGNUP);
    }

    public function testDefaultsReturnsAllFifteenProfiles(): void
    {
        $defaults = RateLimitProfiles::defaults();

        // Fifteen since the minors campaign M-HUB-22 added
        // `client_relay_token` (the mint budget).
        // Fourteen since the minors campaign M-HUB-37 added `syncplay_mount`
        // (the :8804 pre-auth connect limiter).
        // Thirteen since the minors campaign M-HUB-21 added `invite_redeem`.
        // Twelve since the MAJORS-campaign M-HUB-14 added the two federation
        // surfaces (`federation_connect`, `federation_frame`) — same-numbered
        // namespaces differ; this line disambiguates them on purpose.
        // Ten since the M-HUB-12
        // pairing cure added `server_claims`. Nine since the auth audit added
        // `signup` (eight since S91 added `alexa`, seven since S62 added
        // `mcp`). This count is the whole reason the test exists: it turns
        // "a profile was added but never given a config key or a default"
        // into a red, so bumping the number without adding the matching
        // `testDefaultsContains…Profile` below defeats it.
        self::assertCount(15, $defaults);
    }

    /**
     * Minors campaign M-HUB-37: the :8804 SyncPlay connect path gets its own
     * real-IP-keyed budget — 30 / 60s, config key `syncplay_mount`, the
     * :8803 `client_mount` posture on the sibling surface.
     */
    public function testDefaultsContainsSyncPlayMountProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::SYNCPLAY_MOUNT, $defaults);
        self::assertSame('rate_limiter.syncplay_mount', RateLimitProfiles::SYNCPLAY_MOUNT);
        self::assertSame('syncplay_mount', $defaults[RateLimitProfiles::SYNCPLAY_MOUNT]['key']);
        self::assertSame(30, $defaults[RateLimitProfiles::SYNCPLAY_MOUNT]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::SYNCPLAY_MOUNT]['window']);
    }

    /**
     * Minors campaign M-HUB-22: relay-token mint gets its own member-keyed
     * budget — 10 / 60s, config key `client_relay_token`.
     */
    public function testDefaultsContainsClientRelayTokenProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::CLIENT_RELAY_TOKEN, $defaults);
        self::assertSame('rate_limiter.client_relay_token', RateLimitProfiles::CLIENT_RELAY_TOKEN);
        self::assertSame('client_relay_token', $defaults[RateLimitProfiles::CLIENT_RELAY_TOKEN]['key']);
        self::assertSame(10, $defaults[RateLimitProfiles::CLIENT_RELAY_TOKEN]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::CLIENT_RELAY_TOKEN]['window']);
    }

    /**
     * Minors campaign M-HUB-21: invite redemption gets its own member-keyed
     * budget — 20 / 60s, config key `invite_redeem`.
     */
    public function testDefaultsContainsInviteRedeemProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::INVITE_REDEEM, $defaults);
        self::assertSame('rate_limiter.invite_redeem', RateLimitProfiles::INVITE_REDEEM);
        self::assertSame('invite_redeem', $defaults[RateLimitProfiles::INVITE_REDEEM]['key']);
        self::assertSame(20, $defaults[RateLimitProfiles::INVITE_REDEEM]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::INVITE_REDEEM]['window']);
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

    public function testDefaultsContainsSignupProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::SIGNUP, $defaults);
        self::assertSame('signup', $defaults[RateLimitProfiles::SIGNUP]['key']);
        self::assertSame(3, $defaults[RateLimitProfiles::SIGNUP]['max']);
        self::assertSame(3600, $defaults[RateLimitProfiles::SIGNUP]['window']);
    }

    public function testDefaultsContainsServerClaimsProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::SERVER_CLAIMS, $defaults);
        self::assertSame('server_claims', $defaults[RateLimitProfiles::SERVER_CLAIMS]['key']);
        self::assertSame(60, $defaults[RateLimitProfiles::SERVER_CLAIMS]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::SERVER_CLAIMS]['window']);
    }

    public function testDefaultsContainsFederationConnectProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::FEDERATION_CONNECT, $defaults);
        self::assertSame('federation_connect', $defaults[RateLimitProfiles::FEDERATION_CONNECT]['key']);
        self::assertSame(10, $defaults[RateLimitProfiles::FEDERATION_CONNECT]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::FEDERATION_CONNECT]['window']);
    }

    public function testDefaultsContainsFederationFrameProfile(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertArrayHasKey(RateLimitProfiles::FEDERATION_FRAME, $defaults);
        self::assertSame('federation_frame', $defaults[RateLimitProfiles::FEDERATION_FRAME]['key']);
        self::assertSame(120, $defaults[RateLimitProfiles::FEDERATION_FRAME]['max']);
        self::assertSame(60, $defaults[RateLimitProfiles::FEDERATION_FRAME]['window']);
    }

    /**
     * M-HUB-14: the two federation budgets sit an order of magnitude above
     * the legitimate traffic they must never touch. A leaf hub reconnects at
     * most once per minute (the dial backoff ladder CAPS at 60 s — one link
     * per minute per IP is the worst legit case, ten is the budget) and
     * sustainably moves four heartbeats per minute per link plus ceremony and
     * event-driven offer bursts (120 is the budget). Bumping either default
     * below its floor here redds this pin before it can strand a paired hub.
     */
    public function testFederationBudgetsGiveLegitPeersOrderOfMagnitudeHeadroom(): void
    {
        $defaults = RateLimitProfiles::defaults();

        // Legit worst case per IP: one re-dial per minute (capped ladder);
        // the usable per-window budget (max-1 — the max-th hit trips) must
        // carry at least three neighbour hubs' worth of that.
        self::assertGreaterThanOrEqual(3, $defaults[RateLimitProfiles::FEDERATION_CONNECT]['max'] - 1);
        // Heartbeat floor: 60/15 s = 4 frames/min/link; budget ≥ 20× that.
        self::assertGreaterThanOrEqual(80, $defaults[RateLimitProfiles::FEDERATION_FRAME]['max'] - 1);
        // Window law: both surfaces are per-MINUTE (the ladder and heartbeat
        // math above is expressed in minutes).
        self::assertSame(60, $defaults[RateLimitProfiles::FEDERATION_CONNECT]['window']);
        self::assertSame(60, $defaults[RateLimitProfiles::FEDERATION_FRAME]['window']);
    }

    /**
     * M-HUB-12: the pairing budget is sized between the surfaces — generous
     * enough that the shipped pairing CLI (GET status poll every 2 s = 30
     * requests/min/IP, spread over workers) never trips on legitimate use,
     * bounded enough that a claim-code grind is hundreds of guesses against a
     * ~2^40 space, not line rate. If someone tunes it to login-grade the
     * pairing flow breaks; proxy-grade would make the limiter decorative.
     */
    public function testTheServerClaimsBudgetSitsBetweenPollerTrafficAndProxyGenerosity(): void
    {
        $defaults = RateLimitProfiles::defaults();

        $claims = $defaults[RateLimitProfiles::SERVER_CLAIMS];
        $proxy = $defaults[RateLimitProfiles::PROXY];

        // 30 polls/min/IP must fit with slack (per worker).
        self::assertGreaterThanOrEqual(
            60,
            $claims['max'] * (60 / $claims['window']),
            'the pairing CLI polls every 2 s — a budget under 30/min/IP would 429 legitimate enrollment',
        );
        self::assertLessThan(
            $proxy['max'] * (60 / $proxy['window']),
            $claims['max'] * (60 / $claims['window']),
            'server-claims must stay materially tighter than the HLS-burst proxy budget',
        );
    }

    /**
     * The auth audit fix: signup is the third DB-backed GLOBAL surface, so its
     * budget must stay anonymous-abuse-grade — tighter than login per window,
     * because unlike login EVERY attempt (including successes) is counted and
     * a self-hosted household legitimately creates at most a couple of
     * accounts an hour.
     */
    /**
     * The auth audit fix: signup is the third DB-backed GLOBAL surface, and its
     * budget must stay anonymous-abuse-grade — normalised to attempts per hour
     * it is strictly tighter than login's. Login tolerates 5/900s = 20/h of
     * FAILURES (successes reset the bucket); signup caps 3/3600s = 3/h of
     * EVERYTHING including successes, because creating the account is itself
     * the abuse being bounded (and each attempt costs an Argon2id hash).
     */
    public function testTheSignupBudgetIsTighterThanLogin(): void
    {
        $defaults = RateLimitProfiles::defaults();

        $signup = $defaults[RateLimitProfiles::SIGNUP];
        $login = $defaults[RateLimitProfiles::LOGIN];

        $loginPerHour = $login['max'] * (3600 / $login['window']);
        $signupPerHour = $signup['max'] * (3600 / $signup['window']);

        self::assertLessThan(
            $loginPerHour,
            $signupPerHour,
            'account creation must stay rarer per hour than a failed-login budget — '
            . 'someone widened signup past login without re-reading the audit finding.',
        );
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
