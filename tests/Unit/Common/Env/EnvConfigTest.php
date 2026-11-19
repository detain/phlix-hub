<?php

/**
 * Phlix hub test: Unit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Env;

use Phlix\Hub\Common\Env\EnvConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the ported estate env-reader law (HUB-2.1) at the class level, on a
 * scratch knob name so no production env can bleed into the fixtures:
 *
 * - unset, or set-but-empty, means "not configured" → the default;
 * - "0" is an EXPLICIT value and survives (the `?:` trap this class exists
 *   to kill — the hub's worst instance was HUB_SIGNUPS_ENABLED=0 reading as
 *   'true', a security fail-open; see AuthEnvSignupsTest);
 * - malformed numerics CAST the way `(int) getenv(...)` casts them
 *   ("12x" → 12), matching the server readers byte-for-byte;
 * - the helpers never throw and never warn on any input.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Env
 */
final class EnvConfigTest extends TestCase
{
    private const KNOB = 'HUB_ENVCONFIG_TEST_KNOB';

    /** @var string|false */
    private string|false $saved;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = getenv(self::KNOB);
        putenv(self::KNOB);
    }

    protected function tearDown(): void
    {
        if ($this->saved === false) {
            putenv(self::KNOB);
        } else {
            putenv(self::KNOB . '=' . $this->saved);
        }
        parent::tearDown();
    }

    public function testIntegerUnsetTakesTheDefault(): void
    {
        // The knob was cleared in setUp(): unset means "not configured".
        $this->assertSame(8, EnvConfig::integer(self::KNOB, 8));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function integerCastTable(): array
    {
        return [
            'plain' => ['42', 42],
            'negative' => ['-5', -5],
            'trailing garbage casts' => ['12x', 12],
            'leading whitespace casts' => [' 12', 12],
            'float text truncates' => ['7.9', 7],
            'exponent form' => ['1e3', 1000],
            'set-but-empty is not configured' => ['', 8],
            'explicit zero survives (the ?: trap)' => ['0', 0],
        ];
    }

    #[DataProvider('integerCastTable')]
    public function testIntegerValueSemanticsMatchTheServerReader(string $raw, int $expected): void
    {
        putenv(self::KNOB . '=' . $raw);

        $this->assertSame($expected, EnvConfig::integer(self::KNOB, 8));
    }

    public function testTextUnsetTakesTheDefault(): void
    {
        // Cleared in setUp(): unset means "not configured". The set-but-empty
        // leg rides the provider table below (same law, table-driven).
        $this->assertSame('phlix_hub', EnvConfig::text(self::KNOB, 'phlix_hub'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function textValueTable(): array
    {
        return [
            'set-but-empty is not configured' => ['', 'phlix_hub'],
            'explicit zero survives (the ?: trap)' => ['0', '0'],
            'plain value passes through' => ['value', 'value'],
            'whitespace is content' => [' sp ', ' sp '],
        ];
    }

    #[DataProvider('textValueTable')]
    public function testTextValueSemanticsMatchTheServerReader(string $raw, string $expected): void
    {
        putenv(self::KNOB . '=' . $raw);

        $this->assertSame($expected, EnvConfig::text(self::KNOB, 'phlix_hub'));
    }

    public function testNullableTextUnsetAndEmptyAreNullAndZeroSurvives(): void
    {
        $this->assertNull(EnvConfig::nullableText(self::KNOB));

        putenv(self::KNOB . '=');
        $this->assertNull(EnvConfig::nullableText(self::KNOB));

        putenv(self::KNOB . '=0');
        $this->assertSame('0', EnvConfig::nullableText(self::KNOB));

        putenv(self::KNOB . '=secret');
        $this->assertSame('secret', EnvConfig::nullableText(self::KNOB));
    }

    public function testHelpersNeverThrowOnHostileValues(): void
    {
        foreach (["\n", ' ', '9999999999999999999999', '-0', '012', 'true'] as $raw) {
            putenv(self::KNOB . '=' . $raw);

            // The read itself must not throw or warn on any of these — and
            // each helper's answer must equal PHP's own native cast of the
            // same raw string (the posture the class docblock promises).
            $this->assertSame((int) $raw, EnvConfig::integer(self::KNOB, 1));
            $this->assertSame($raw, EnvConfig::text(self::KNOB, 'd'));
            $this->assertSame($raw, EnvConfig::nullableText(self::KNOB));
        }
    }
}
