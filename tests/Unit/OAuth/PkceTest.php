<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\OAuth;

use Phlix\Hub\OAuth\Pkce;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function hash;
use function rtrim;
use function str_repeat;
use function strtr;

/**
 * Unit tests for {@see Pkce} — the S256-only PKCE implementation.
 *
 * Every refusal below sits NEXT TO a succeeding control. A test that only ever
 * asserts `false` cannot tell "the guard fired" apart from "the method returns
 * false for everything", which is the shape a broken implementation passes.
 *
 * @package Phlix\Hub\Tests\Unit\OAuth
 *
 * @covers \Phlix\Hub\OAuth\Pkce
 */
final class PkceTest extends TestCase
{
    /** A syntactically valid 43-character verifier. */
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    public function testS256IsTheOnlySupportedMethod(): void
    {
        // Control: the one method that IS supported.
        self::assertTrue(Pkce::isSupportedMethod('S256'));

        // The refusals, each named.
        self::assertFalse(Pkce::isSupportedMethod('plain'), '`plain` must be refused, not merely deprecated');
        self::assertFalse(Pkce::isSupportedMethod(''), 'an omitted method must NOT default to plain');
        self::assertFalse(Pkce::isSupportedMethod('s256'), 'the method name is case-sensitive');
        self::assertFalse(Pkce::isSupportedMethod('S256 '), 'no trimming — a padded method is not S256');
        self::assertFalse(Pkce::isSupportedMethod('S512'));
        self::assertFalse(Pkce::isSupportedMethod('S256plain'), 'must not be a prefix match');
    }

    public function testThePlainConstantNamesTheMethodThatIsRefused(): void
    {
        // Pins the constant to the RFC's spelling, so the rejection message and
        // the tests cannot drift apart from what a client actually sends.
        self::assertSame('plain', Pkce::METHOD_PLAIN);
        self::assertFalse(Pkce::isSupportedMethod(Pkce::METHOD_PLAIN));
        self::assertTrue(Pkce::isSupportedMethod(Pkce::METHOD_S256));
    }

    public function testChallengeDerivationMatchesTheRfc7636WorkedExample(): void
    {
        // RFC 7636 Appendix B's verifier/challenge pair. An independently
        // published vector, so this test cannot be satisfied by an
        // implementation that merely agrees with itself.
        $verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        self::assertSame($challenge, Pkce::challengeFor($verifier));
        self::assertTrue(Pkce::verify($verifier, $challenge));
    }

    public function testTheChallengeIsUnpaddedBase64urlNotBase64(): void
    {
        $challenge = Pkce::challengeFor(self::VERIFIER);
        $plain     = base64_encode(hash('sha256', self::VERIFIER, true));

        self::assertStringNotContainsString('=', $challenge, 'padding must be stripped');
        self::assertStringNotContainsString('+', $challenge, '+ must be translated to -');
        self::assertStringNotContainsString('/', $challenge, '/ must be translated to _');
        self::assertSame(rtrim(strtr($plain, '+/', '-_'), '='), $challenge);
        self::assertSame(43, strlen($challenge));
    }

    public function testVerifyAcceptsTheMatchingVerifierAndRefusesADifferentOne(): void
    {
        $challenge = Pkce::challengeFor(self::VERIFIER);

        // Control.
        self::assertTrue(Pkce::verify(self::VERIFIER, $challenge));

        // A different but equally well-formed verifier.
        $other = 'M25iVXpKU3puUjFaYWg3T1NDTDQtcW1ROUY5YXlwalNoc0hhakxpZlRuSQ';
        self::assertFalse(Pkce::verify($other, $challenge));
    }

    public function testVerifyRefusesAVerifierThatIsTooShortOrTooLong(): void
    {
        // Control: exactly at the lower bound.
        $atMin = str_repeat('a', Pkce::MIN_VERIFIER_LENGTH);
        self::assertTrue(Pkce::verify($atMin, Pkce::challengeFor($atMin)));

        // Control: exactly at the upper bound.
        $atMax = str_repeat('b', Pkce::MAX_VERIFIER_LENGTH);
        self::assertTrue(Pkce::verify($atMax, Pkce::challengeFor($atMax)));

        // One character either side. Without the length floor a one-character
        // verifier would be brute-forceable inside the code's 60-second life.
        $tooShort = str_repeat('a', Pkce::MIN_VERIFIER_LENGTH - 1);
        self::assertFalse(Pkce::verify($tooShort, Pkce::challengeFor($tooShort)));

        $tooLong = str_repeat('b', Pkce::MAX_VERIFIER_LENGTH + 1);
        self::assertFalse(Pkce::verify($tooLong, Pkce::challengeFor($tooLong)));

        self::assertFalse(Pkce::verify('', Pkce::challengeFor('')), 'an empty verifier proves nothing');
    }

    public function testVerifyRefusesAVerifierOutsideTheUnreservedAlphabet(): void
    {
        // Control: 43 characters, all legal, including every legal punctuation.
        $legal = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJ-._~01234';
        self::assertTrue(Pkce::isValidVerifier(substr($legal, 0, 43)));

        foreach (['+', '/', '=', ' ', '%', '&', "\n", '"'] as $illegal) {
            $candidate = str_repeat('a', 42) . $illegal;
            self::assertFalse(
                Pkce::isValidVerifier($candidate),
                'a verifier containing ' . var_export($illegal, true) . ' must be refused',
            );
            self::assertFalse(Pkce::verify($candidate, Pkce::challengeFor($candidate)));
        }
    }

    public function testAMalformedChallengeIsRefusedEvenByItsOwnVerifier(): void
    {
        // Control: a well-formed challenge validates.
        self::assertTrue(Pkce::isValidChallenge(Pkce::challengeFor(self::VERIFIER)));

        // A challenge of the wrong length can never be an S256 digest, so it is
        // refused before any hashing happens. Storing one would mean a code
        // nothing could ever redeem — better to refuse at the authorize step.
        self::assertFalse(Pkce::isValidChallenge(''));
        self::assertFalse(Pkce::isValidChallenge(str_repeat('a', 42)));
        self::assertFalse(Pkce::isValidChallenge(str_repeat('a', 44)));

        // Right length, wrong alphabet: standard base64 rather than base64url.
        self::assertFalse(Pkce::isValidChallenge(str_repeat('a', 42) . '+'));
        self::assertFalse(Pkce::isValidChallenge(str_repeat('a', 42) . '='));
    }

    public function testPlainStyleVerificationIsImpossible(): void
    {
        // Under `plain`, challenge === verifier. This asserts the server cannot
        // be tricked into that behaviour by simply sending the challenge as the
        // verifier — which is precisely the attack `plain` enables.
        $challenge = Pkce::challengeFor(self::VERIFIER);

        self::assertFalse(
            Pkce::verify($challenge, $challenge),
            'presenting the challenge AS the verifier must not validate — that is plain-mode PKCE',
        );
        self::assertTrue(Pkce::verify(self::VERIFIER, $challenge), 'control: the real verifier still works');
    }
}
