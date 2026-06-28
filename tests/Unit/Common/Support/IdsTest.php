<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Support;

use Phlix\Hub\Common\Support\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Ids}.
 *
 * Asserts the CSPRNG UUID helper produces canonically-shaped RFC-4122
 * version-4 UUIDs, that draws are unique across a large sample, and that
 * the token helper yields the expected hex length.
 */
#[CoversClass(Ids::class)]
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
}
