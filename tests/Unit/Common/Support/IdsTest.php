<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Support;

use Phlix\Hub\Application;
use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Http\Controllers\FederationController;
use Phlix\Shared\Database\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Ids}.
 *
 * Asserts the CSPRNG UUID helper produces canonically-shaped RFC-4122
 * version-4 UUIDs, that draws are unique across a large sample, and that
 * the token helper yields the expected hex length.
 *
 * v0.57 wave-A (S443/M-9 + R3): the mint bodies now delegate to the shared
 * {@see Uuid} kernel; the differential pins at the end freeze (a) that
 * delegation and (b) the strictly-permissive accept-sets of the live
 * boundary shape sites, which the shared strict validator must NOT replace
 * without an owner ruling.
 */
final class IdsTest extends TestCase
{
    /**
     * Canonical RFC-4122 v4 UUID: 8-4-4-4-12 lowercase hex, version nibble
     * `4`, variant nibble one of 8/9/a/b.
     */
    private const string UUID_V4_REGEX =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testUuidV4MatchesCanonicalShape(): void
    {
        $uuid = Ids::uuidV4();

        self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $uuid);
        self::assertSame(36, strlen($uuid));
    }

    public function testUuidV4IsUniqueAndWellFormedOverManyDraws(): void
    {
        $draws = 10000;
        $seen  = [];

        for ($i = 0; $i < $draws; $i++) {
            $uuid = Ids::uuidV4();
            self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $uuid);
            $seen[$uuid] = true;
        }

        self::assertCount($draws, $seen, 'Expected every UUID draw to be unique.');
    }

    public function testTokenDefaultLength(): void
    {
        $token = Ids::token();

        // 32 bytes -> 64 hex chars.
        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTokenCustomLength(): void
    {
        $token = Ids::token(16);

        // 16 bytes -> 32 hex chars.
        self::assertSame(32, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function testTokenIsUniqueAcrossDraws(): void
    {
        $a = Ids::token();
        $b = Ids::token();

        self::assertNotSame($a, $b);
    }

    /**
     * Tripwire (SH-068): Ids::uuidV4() is the SINGLE mint. The 13 private
     * one-line `generateUuid()` delegates this repo carried were byte-for-byte
     * `return Ids::uuidV4();` wrappers — two of them with docblocks claiming an
     * inline sprintf implementation that no longer existed. A wrapper
     * reappearing anywhere in src/ re-opens the drift surface (the false
     * docblocks were exactly where the truth had rotted), so this scans for
     * any declaration or call of `generateUuid` in src/ and reddens on it.
     *
     * The prose mention in {@see Ids}'s own class docblock (the history
     * note) cannot match: declarations need the `function` keyword and call
     * sites need `->`/`::`.
     */
    public function testNoGenerateUuidDelegateSurvivesAnywhereInSrc(): void
    {
        $srcRoot = dirname(__DIR__, 4) . '/src';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcRoot));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (
                preg_match('/function\s+generateUuid\s*\(/', $contents) === 1
                || preg_match('/(?:->|::)generateUuid\s*\(/', $contents) === 1
            ) {
                $offenders[] = substr($file->getPathname(), strlen($srcRoot) + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'src/ must mint every UUID through Ids::uuidV4() directly — no generateUuid() wrappers (SH-068).',
        );
    }

    public function testNoHexSprintfUuidGeneratorSurvivesAnywhereInSrc(): void
    {
        // SH-068 swept the thin generateUuid() delegates; HUB-5.3 (bug #10)
        // closes the family's remaining hole: a LOCAL sprintf('%04x%04x-…',
        // random_int(…)) re-implementation of the v4 shape. The pattern
        // demands the CONCATENATED '%04x%04x' token pair, so the unrelated
        // '0x%04x' mask renderings (HookDelivery, CoroutineHooks) stay
        // innocent; only uuid-group generators match.
        $srcRoot = dirname(__DIR__, 4) . '/src';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcRoot));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/%04x%04x/', $contents) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($srcRoot) + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'src/ must mint every UUID through Ids::uuidV4() — no local hex-sprintf generators (D-07).',
        );
    }

    /**
     * Adoption pin (v0.57 wave-A, S443/M-9): the facade no longer carries
     * its own entropy. A re-materialised mint body here would open a second
     * entropy seam — exactly what the shared kernel retired — so the scan
     * pins delegation in place and forbids local CSPRNG calls.
     */
    public function testTheMintBodiesDelegateToTheSharedKernel(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(Ids::class))->getFileName());
        self::assertNotSame('', $source, 'the Ids source scan must never run vacuously.');
        self::assertStringContainsString('return Uuid::v4();', $source);
        self::assertStringContainsString('return Uuid::randomHex($bytes);', $source);
        self::assertStringNotContainsString(
            'random_bytes(',
            $source,
            'Ids must delegate entropy to the shared Uuid kernel (S443/M-9), not mint a third seam.',
        );
    }

    /**
     * Differential shape pin: the delegated mints stay inside the strict
     * canonical contract the shared validator owns. Value equality across
     * CSPRNG draws is impossible by design; shape equality is the law.
     */
    public function testDelegatedMintsStayInsideTheSharedShapeContract(): void
    {
        for ($i = 0; $i < 200; $i++) {
            self::assertTrue(
                Uuid::isV4(Ids::uuidV4()),
                'Ids::uuidV4() must emit exactly the shape shared Uuid::isV4() accepts.',
            );
        }

        foreach ([1, 8, 16, 32, 64] as $bytes) {
            $token = Ids::token($bytes);
            self::assertSame(2 * $bytes, strlen($token));
            self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
        }
    }

    /**
     * R3 differential pin (zero-behaviour law): the three live hub shape
     * gates accept the PERMISSIVE any-hex-UUID / any-case shape — strictly
     * wider than the shared canonical-lowercase-v4 validator. Swapping the
     * strict validator in at these boundaries would reject values accepted
     * today (uppercase ids, non-4 version nibbles, non-[89ab] variants), so
     * the sites stay untouched and this test freezes both accept-sets
     * against a future silent "cleanup". The Application leg is pinned by
     * behaviour: its regex arm is redundant with the letters-and-digits
     * segment arm, so narrowing the regex alone would not change its
     * answers — the federation sites are the sole-gate teeth.
     */
    public function testTheLiveShapeSitesStayPermissiveAgainstTheSharedValidator(): void
    {
        $widestThanShared = [
            'uppercase v4' => '2F1E9D7C-6B5A-4C3D-9E8F-1A2B3C4D5E6F',
            'v1 shape id'  => 'f47ac10b-58cc-1372-a567-0e02b2c3d479',
            'variant-f id' => '2f1e9d7c-6b5a-4c3d-fe8f-1a2b3c4d5e6f',
        ];
        $canonical = '2f1e9d7c-6b5a-4c3d-9e8f-1a2b3c4d5e6f';
        $neverAccepted = [
            'empty'          => '',
            'too short'      => '2f1e9d7c-6b5a-4c3d-9e8f-1a2b3c4d5e6',
            'too long'       => '2f1e9d7c-6b5a-4c3d-9e8f-1a2b3c4d5e6f00',
            'no dashes'      => '2f1e9d7c6b5a4c3d9e8f1a2b3c4d5e6f',
            'non-hex char'   => '2f1e9d7c-6b5a-4c3d-9e8f-1a2b3c4d5e6g',
            'wrong groups'   => '2f1e9d7c-6b5a-4c3d-9e8f1a2b3c4d5e6f',
        ];

        foreach ([FederationLibraryShareRepository::class, FederationController::class] as $fqcn) {
            $pattern = (new \ReflectionClass($fqcn))->getConstant('UUID_PATTERN');
            self::assertIsString($pattern, $fqcn . ' must keep its UUID_PATTERN boundary const.');
            self::assertSame(1, preg_match($pattern, $canonical), $fqcn . ' must accept canonical v4.');
            foreach ($widestThanShared as $label => $id) {
                self::assertSame(1, preg_match($pattern, $id), $fqcn . ' must still accept: ' . $label . ' (R3 delta vs shared).');
            }
            foreach ($neverAccepted as $label => $id) {
                self::assertSame(0, preg_match($pattern, $id), $fqcn . ' must reject: ' . $label . '.');
            }
            foreach ($widestThanShared as $label => $id) {
                self::assertFalse(Uuid::isV4($id), 'shared validator is narrower on: ' . $label . ' (the frozen delta).');
            }
        }

        $isVariable = new \ReflectionMethod(Application::class, 'isVariableSegment');
        self::assertTrue((bool) $isVariable->invoke(null, $canonical));
        foreach ($widestThanShared as $label => $id) {
            self::assertTrue((bool) $isVariable->invoke(null, $id), 'Application::isVariableSegment must still accept: ' . $label . '.');
        }
        self::assertFalse((bool) $isVariable->invoke(null, 'health'));
    }
}
