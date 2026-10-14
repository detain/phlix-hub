<?php

/**
 * R14-08 boot-guard tests: the JWT secret pre-flight (placeholder denylist,
 * empty-value refusal, short-key floor) in AuthServicesProvider and its
 * start.php wiring.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\Container\MissingJwtSecretException;
use Phlix\Hub\Common\Container\Providers\AuthServicesProvider;
use ReflectionMethod;

/**
 * Policy matrix for {@see AuthServicesProvider::secretViolation()} (pure
 * seam), the placeholder fold inside the private resolveSecret (reflection,
 * mirroring AuthServicesProviderTest's isTruthyEnv style), the PHPUnit
 * early-return of assertSecretConfigured, and the start.php pre-flight pin.
 */
final class JwtSecretBootGuardTest extends TestCase
{
    /**
     * The exact literal README.md's env example ships (line
     * `HUB_JWT_SECRET=CHANGE-ME-run-openssl-rand-hex-32`). 33 bytes — the
     * proof that the old ≥32-byte-only gate accepted it verbatim.
     */
    private const README_PLACEHOLDER = 'CHANGE-ME-run-openssl-rand-hex-32';

    public function testTheReadmePlaceholderIsThirtyThreeBytesAndListed(): void
    {
        self::assertSame(
            33,
            strlen(self::README_PLACEHOLDER),
            'the README example is long enough to pass a pure length gate'
        );
        self::assertContains(self::README_PLACEHOLDER, AuthServicesProvider::PLACEHOLDER_JWT_SECRETS);
    }

    public function testEmptyValueIsFatalInEveryMode(): void
    {
        self::assertNotNull(AuthServicesProvider::secretViolation('', false));
        self::assertNotNull(
            AuthServicesProvider::secretViolation('', true),
            'the empty tier is absolute — dev has the random fallback instead'
        );
    }

    public function testEveryPlaceholderIsFatalEvenInDevMode(): void
    {
        foreach (AuthServicesProvider::PLACEHOLDER_JWT_SECRETS as $placeholder) {
            self::assertNotNull(
                AuthServicesProvider::secretViolation($placeholder, true),
                'placeholder ' . $placeholder . ' must never mint tokens, dev included'
            );
        }
    }

    public function testPlaceholderMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        $shouty = '  CHANGE-ME-RUN-OPENSSL-RAND-HEX-32 ';
        self::assertNotNull(AuthServicesProvider::secretViolation($shouty, false));
    }

    public function testShortValueIsFatalOnlyOutsideDev(): void
    {
        $short = str_repeat('a', AuthServicesProvider::MIN_SECRET_BYTES - 1);
        self::assertNotNull(AuthServicesProvider::secretViolation($short, false));
        self::assertNull(
            AuthServicesProvider::secretViolation($short, true),
            'the relaxation covers ONLY the length floor'
        );
        self::assertStringContainsString(
            (string) AuthServicesProvider::MIN_SECRET_BYTES,
            (string) AuthServicesProvider::secretViolation($short, false)
        );
    }

    public function testRealHexSecretsPassAtTheFloorAndAbove(): void
    {
        $atFloor = str_repeat('f', AuthServicesProvider::MIN_SECRET_BYTES);
        $generated = bin2hex(random_bytes(32));
        self::assertNull(AuthServicesProvider::secretViolation($atFloor, false));
        self::assertNull(AuthServicesProvider::secretViolation($generated, false));
    }

    public function testResolveSecretRefusesAPlaceholderFromConfig(): void
    {
        $resolve = new ReflectionMethod(AuthServicesProvider::class, 'resolveSecret');
        $resolve->setAccessible(true);

        $this->expectException(MissingJwtSecretException::class);
        $this->expectExceptionMessageMatches('/placeholder/i');

        $resolve->invoke(null, ['secret' => self::README_PLACEHOLDER]);
    }

    public function testResolveSecretRefusesAPlaceholderFromEnv(): void
    {
        $previous = getenv('HUB_JWT_SECRET');
        putenv('HUB_JWT_SECRET=' . self::README_PLACEHOLDER);
        try {
            $resolve = new ReflectionMethod(AuthServicesProvider::class, 'resolveSecret');
            $resolve->setAccessible(true);

            $this->expectException(MissingJwtSecretException::class);
            $this->expectExceptionMessageMatches('/placeholder/i');

            $resolve->invoke(null, []);
        } finally {
            if ($previous === false) {
                putenv('HUB_JWT_SECRET');
            } else {
                putenv('HUB_JWT_SECRET=' . $previous);
            }
        }
    }

    public function testResolveSecretStillAcceptsARealEnvSecret(): void
    {
        $previous = getenv('HUB_JWT_SECRET');
        $secret = bin2hex(random_bytes(32));
        putenv('HUB_JWT_SECRET=' . $secret);
        try {
            $resolve = new ReflectionMethod(AuthServicesProvider::class, 'resolveSecret');
            $resolve->setAccessible(true);

            self::assertSame($secret, $resolve->invoke(null, []));
        } finally {
            if ($previous === false) {
                putenv('HUB_JWT_SECRET');
            } else {
                putenv('HUB_JWT_SECRET=' . $previous);
            }
        }
    }

    public function testAssertSecretConfiguredIsInertUnderPhpunitByDesign(): void
    {
        // The test-env early-return is what lets the whole suite boot without
        // a real secret; assertSecretConfigured can therefore never throw here
        // — the pure secretViolation seam carries the matrix instead (same
        // doctrine as phlix-server). A placeholder override must NOT throw.
        AuthServicesProvider::assertSecretConfigured(self::README_PLACEHOLDER);
        $this->expectNotToPerformAssertions();
    }

    public function testStartPhpCallsThePreFlightBeforeTheContainerIsBuilt(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../../start.php');

        $guard = strpos($source, 'AuthServicesProvider::assertSecretConfigured()');
        $container = strpos($source, 'ContainerFactory::create($serverConfig)');
        self::assertNotFalse($guard, 'start.php must keep the R14-08 pre-flight call');
        self::assertNotFalse($container);
        self::assertLessThan($container, $guard, 'the pre-flight must run BEFORE the container builds');
        self::assertStringContainsString('exit(1)', $source, 'the violation path exits non-zero');
        self::assertStringContainsString(
            'fwrite(STDERR',
            $source,
            'the message goes to the console, not into a swallowed log'
        );
    }
}
