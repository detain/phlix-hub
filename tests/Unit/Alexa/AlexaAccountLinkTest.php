<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Alexa;

use Phlix\Hub\Alexa\AlexaAccountLink;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use PHPUnit\Framework\TestCase;

/**
 * S91 — the defects this suite catches in {@see AlexaAccountLink}.
 *
 * **1. A token that is valid but names a user who no longer exists.** Hub access
 * tokens are long-lived and Amazon caches the linked-account token for the life
 * of the link, so a deleted user's token stays cryptographically valid long
 * after the row is gone. Skipping the {@see UserRepository::userExists()} probe
 * would leave the skill answering as a user the hub no longer has — and would
 * hand that id to `AlexaMediaGateway`, where the proxy's ownership gate compares
 * it against server rows that may since have been re-owned. That case is driven
 * here with a REAL, genuinely-valid token and only the existence probe flipped,
 * beside a succeeding control using the same token shape, so a pass cannot be
 * confused with "everything is rejected".
 *
 * **2. A rejection that is really a crash.** Every failure mode must return
 * null, including the ones a naive implementation would let throw: a garbage
 * string handed to a JWT decoder, an empty string, a REFRESH token presented
 * where an access token belongs. The caller must not be able to tell them apart
 * — the skill says "please link your account" to all of them, because a spoken
 * error naming which check failed is a credential oracle read out loud in
 * somebody's kitchen.
 *
 * The JWT here is REAL ({@see JwtHandler} with a real HS256 secret), not a
 * double: a mocked validator would prove only that this class calls a method.
 *
 * @package Phlix\Hub\Tests\Unit\Alexa
 */
final class AlexaAccountLinkTest extends TestCase
{
    /** A >=32-byte HS256 secret, as JwtHandler demands. */
    private const SECRET = 'S91-alexa-account-link-secret-0123456789';

    private JwtHandler $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new JwtHandler(self::SECRET);
    }

    public function testAValidAccessTokenForALiveUserResolvesToThatUserId(): void
    {
        $token = $this->jwt->createAccessToken('user-alpha');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('userExists')->with('user-alpha')->willReturn(true);

        self::assertSame('user-alpha', (new AlexaAccountLink($this->jwt, $users))->resolve($token));
    }

    /**
     * The failure and its succeeding control, in one method: the SAME token
     * resolves when the user exists and does not when it does not. Two separate
     * tests could both pass against a `resolve()` that ignored the probe and
     * happened to be given a stubbed `true` in one and `false` in the other only
     * because the token also differed.
     */
    public function testTheSameValidTokenStopsResolvingOnceTheUserIsGone(): void
    {
        $token = $this->jwt->createAccessToken('user-beta');

        $present = $this->createMock(UserRepository::class);
        $present->method('userExists')->willReturn(true);
        self::assertSame(
            'user-beta',
            (new AlexaAccountLink($this->jwt, $present))->resolve($token),
            'control: the token is genuinely valid',
        );

        $gone = $this->createMock(UserRepository::class);
        $gone->expects(self::once())->method('userExists')->with('user-beta')->willReturn(false);
        self::assertNull(
            (new AlexaAccountLink($this->jwt, $gone))->resolve($token),
            'a cryptographically valid token for a deleted user must not authenticate anybody',
        );
    }

    public function testAnAbsentTokenResolvesToNullWithoutTouchingTheDatabase(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('userExists');

        self::assertNull((new AlexaAccountLink($this->jwt, $users))->resolve(null));
    }

    public function testAnEmptyStringResolvesToNullWithoutTouchingTheDatabase(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('userExists');

        self::assertNull((new AlexaAccountLink($this->jwt, $users))->resolve(''));
    }

    public function testAGarbageTokenResolvesToNullRatherThanThrowing(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('userExists');
        $link = new AlexaAccountLink($this->jwt, $users);

        foreach (['not-a-jwt', 'a.b.c', '....', 'Bearer something', str_repeat('x', 4096)] as $garbage) {
            self::assertNull($link->resolve($garbage), 'garbage must be refused, not thrown on: ' . $garbage);
        }
    }

    /**
     * A token signed with a DIFFERENT secret is structurally perfect and must
     * still be refused — the control that the signature is actually checked.
     */
    public function testATokenSignedWithAnotherSecretIsRefused(): void
    {
        $stranger = new JwtHandler('a-completely-different-secret-0123456789');
        $token = $stranger->createAccessToken('user-gamma');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('userExists');

        self::assertNull((new AlexaAccountLink($this->jwt, $users))->resolve($token));
    }

    /**
     * A REFRESH token is a valid hub credential of the wrong TYPE. Accepting it
     * would let a long-lived refresh credential be replayed at the skill
     * endpoint forever.
     */
    public function testARefreshTokenIsRefused(): void
    {
        $token = $this->jwt->createRefreshToken('user-delta');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('userExists');

        self::assertNull((new AlexaAccountLink($this->jwt, $users))->resolve($token));
    }

    /**
     * A structurally valid token whose `sub` is the EMPTY STRING.
     *
     * `JwtHandler` mints and validates it happily — checked directly here so the
     * case is a real one rather than a hypothetical. An empty user id would sail
     * through the existence probe's SQL as `WHERE id = ''` and, worse, would be
     * assigned as `Request::$userId` where every downstream gate treats "" as
     * "unauthenticated". Refusing it here is what keeps that from being an
     * authorisation question at all.
     */
    public function testATokenWhoseSubjectIsEmptyIsRefusedBeforeTheDatabaseIsAsked(): void
    {
        $token = $this->jwt->createAccessToken('');

        // The premise: the token really is valid, and its subject really is ''.
        $claims = $this->jwt->validateAccessToken($token);
        self::assertNotNull($claims, 'the premise of this test is a VALID token');
        self::assertSame('', $claims->sub);

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('userExists');

        self::assertNull((new AlexaAccountLink($this->jwt, $users))->resolve($token));
    }

    /**
     * An EXPIRED access token. Minted through a real handler configured with a
     * negative TTL so the token is genuinely past its `exp`, not merely
     * hand-edited into something that fails to parse.
     */
    public function testAnExpiredAccessTokenIsRefused(): void
    {
        $expiring = new JwtHandler(self::SECRET, accessTtl: -60);
        $token = $expiring->createAccessToken('user-epsilon');

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('userExists');

        self::assertNull((new AlexaAccountLink($this->jwt, $users))->resolve($token));
    }
}
