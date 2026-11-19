<?php

/**
 * B-06 hub twin — the password-hashing SSOT suite.
 *
 * Pins the server's PasswordHasher contract (SRV-4.1) leg for leg inside the
 * hub tree: one canonical ARGON2ID cost set carried by every mint, the
 * needsRehash drift matrix that lets a successful login re-mint stale
 * credentials forward, and the coupling pin that the repository's timing
 * burn dummy hash carries the SAME embedded parameters — if the two ever
 * drift apart, unknown-user and known-user logins stop costing the same and
 * the timing equalization this wave relocated is silently undone.
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use Phlix\Hub\Auth\PasswordHasher;
use Phlix\Hub\Auth\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PasswordHasherTest extends TestCase
{
    /** The exact PHC string prefix a canonical mint must carry. */
    private const CANONICAL_EMBEDDED_PARAMS = '$argon2id$v=19$m=65536,t=4,p=1$';

    public function testCanonicalOptionsAreTheDocumentedArgon2idTriple(): void
    {
        $this->assertSame(
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
            PasswordHasher::ARGON2ID_OPTIONS,
            'The canonical cost set is the estate SSOT constant; changing it must go '
                . 'together with regenerating UserRepository::TIMING_BURN_DUMMY_HASH '
                . '(pinned by testTheTimingBurnDummyHashCarriesTheCanonicalParameters) '
                . 'and with the server original for the wave-2 phlix-shared consolidation.'
        );
    }

    public function testHashEmbedsTheCanonicalArgon2idParameters(): void
    {
        $hash = PasswordHasher::hash('red-first-canonical-mint');

        $this->assertStringStartsWith(
            self::CANONICAL_EMBEDDED_PARAMS,
            $hash,
            'Every mint must carry the canonical memory/time/threads parameters in the '
                . 'hash itself, not whatever the host PHP has as ini defaults today.'
        );
    }

    public function testVerifyRoundTripAcceptsTheMintedPassword(): void
    {
        $hash = PasswordHasher::hash('round-trip-password');

        $this->assertTrue(PasswordHasher::verify('round-trip-password', $hash));
    }

    public function testVerifyRejectsTheWrongPassword(): void
    {
        $hash = PasswordHasher::hash('round-trip-password');

        $this->assertFalse(PasswordHasher::verify('wrong-password', $hash));
    }

    /**
     * Backward compatibility (brief item (d)): password_verify() reads the
     * algorithm and cost parameters EMBEDDED in each hash, so every legacy
     * shape still present in the users table — bcrypt history, argon2i, or
     * drifted-cost argon2id — keeps verifying through the SSOT unchanged.
     * The canonical-mint law applies to NEW mints only; nothing here forces
     * a re-hash before the owner's next successful login.
     */
    public function testVerifyStillAcceptsLegacyHashShapes(): void
    {
        $bcrypt = password_hash('legacy-secret', PASSWORD_BCRYPT);
        $argon2i = password_hash('legacy-secret', PASSWORD_ARGON2I);
        $driftedArgon2id = password_hash('legacy-secret', PASSWORD_ARGON2ID, [
            'memory_cost' => 1024,
            'time_cost' => 1,
            'threads' => 1,
        ]);

        $this->assertTrue(PasswordHasher::verify('legacy-secret', $bcrypt));
        $this->assertTrue(PasswordHasher::verify('legacy-secret', $argon2i));
        $this->assertTrue(PasswordHasher::verify('legacy-secret', $driftedArgon2id));
        $this->assertFalse(PasswordHasher::verify('wrong-legacy-secret', $bcrypt));
    }

    /**
     * Matrix of stored-hash shapes a rehash-aware login path can meet, with the
     * answer needsRehash() must give for each.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function rehashMatrixProvider(): iterable
    {
        yield 'canonical-mint-needs-no-rehash' => [
            password_hash('matrix-password', PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 1,
            ]),
            false,
        ];

        yield 'legacy-low-cost-argon2id-needs-rehash' => [
            password_hash('matrix-password', PASSWORD_ARGON2ID, [
                'memory_cost' => 1024,
                'time_cost' => 1,
                'threads' => 1,
            ]),
            true,
        ];

        yield 'argon2i-variant-needs-rehash' => [
            password_hash('matrix-password', PASSWORD_ARGON2I, [
                'memory_cost' => 1024,
                'time_cost' => 1,
                'threads' => 1,
            ]),
            true,
        ];

        yield 'bcrypt-needs-rehash' => [
            password_hash('matrix-password', PASSWORD_BCRYPT),
            true,
        ];
    }

    #[DataProvider('rehashMatrixProvider')]
    public function testNeedsRehashAnswersTheDriftMatrix(string $hash, bool $expected): void
    {
        $this->assertSame(
            $expected,
            PasswordHasher::needsRehash($hash),
            'needsRehash() must flag every non-canonical cost shape (and only those) '
                . 'so the login path can re-mint drifted credentials forward.'
        );
    }

    public function testNeedsRehashTreatsGarbageAsNonCanonical(): void
    {
        $this->assertTrue(
            PasswordHasher::needsRehash('not-a-password-hash'),
            'A stored value that is not a canonical ARGON2ID PHC string is by definition '
                . 'not canonical — needsRehash() answers true without throwing. That is safe '
                . 'on the login path because the re-mint is gated on a successful verify() '
                . 'first, and garbage never verifies.'
        );
    }

    public function testTheTimingBurnDummyHashCarriesTheCanonicalParameters(): void
    {
        $dummy = (new ReflectionClass(UserRepository::class))->getConstant('TIMING_BURN_DUMMY_HASH');

        $this->assertIsString($dummy);
        $this->assertStringStartsWith(
            self::CANONICAL_EMBEDDED_PARAMS,
            $dummy,
            'UserRepository::burnPasswordVerifyTime() equalizes known-user vs unknown-user '
                . 'login timing by verifying against this dummy hash. If its embedded cost '
                . 'shape stops matching the canonical options the two paths stop costing the '
                . 'same — regenerate the dummy hash in lockstep with any options change.'
        );
    }
}
