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
 * - the helpers never throw on any input; numeric/text readers stay silent,
 *   and the bool family's ONE soft exception (a junk value answering its
 *   default with a one-line `error_log` warning — server E-04) is pinned in
 *   the bool legs below.
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

    // ---- bool() / boolValue() — the E-04 grammar, ported verbatim ----------

    /**
     * HUB-ENVBOOL: the hub's bool family. These legs are the law the
     * migrated seats delegate to — one grammar, trim + case fold, both word
     * sets, junk answers the default and is LOUD (one `error_log` line)
     * without ever blocking the boot. Mirrors the server's E-04 suite
     * spell-for-spell (the `boolOptOut` verb is deliberately not ported — no
     * hub consumer yet, same doctrine as `availableCpuCount`).
     */
    public function testBoolFallsBackToItsDefaultWhenUnset(): void
    {
        $this->assertFalse(EnvConfig::bool(self::KNOB));
        $this->assertTrue(EnvConfig::bool(self::KNOB, true));
    }

    public function testBoolFallsBackOnEmptyString(): void
    {
        putenv(self::KNOB . '=');

        $this->assertFalse(EnvConfig::bool(self::KNOB));
        $this->assertTrue(EnvConfig::bool(self::KNOB, true));
    }

    /** A whitespace-only value reads as unset: default, and NO junk warning. */
    public function testBoolTreatsWhitespaceOnlyAsUnsetWithoutWarning(): void
    {
        putenv(self::KNOB . '=   ');

        $log = $this->capturedErrorLog(fn (): bool => EnvConfig::bool(self::KNOB));

        $this->assertFalse(EnvConfig::bool(self::KNOB));
        $this->assertSame('', $log, 'whitespace-only is unset-shaped, not junk — it must not warn');
    }

    /**
     * Every accepted spelling of the grammar — the full word set both ways,
     * case-folded, plus the whitespace shapes the hub's drifted former copies
     * disagreed on (`ContainerFactory::shouldCompile` never trimmed; the
     * `' 1 '` leg IS that drift case: it must answer TRUE once the site
     * shares this law).
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function boolSpellings(): array
    {
        return [
            'one' => ['1', true],
            'true lower' => ['true', true],
            'TRUE upper' => ['TRUE', true],
            'True mixed' => ['True', true],
            'yes' => ['yes', true],
            'YES' => ['YES', true],
            'Yes mixed' => ['Yes', true],
            'on' => ['on', true],
            'ON upper' => ['ON', true],
            'zero' => ['0', false],
            'false lower' => ['false', false],
            'FALSE upper' => ['FALSE', false],
            'no' => ['no', false],
            'NO upper' => ['NO', false],
            'off' => ['off', false],
            'OFF upper' => ['OFF', false],
            'padded one (the hub compile-knob drift)' => [' 1 ', true],
            'padded true' => [' true ', true],
            'leading-space false (drift case)' => [' false', false],
            'tab-padded on' => ["\ton", true],
        ];
    }

    #[DataProvider('boolSpellings')]
    public function testBoolReadsTheWordGrammar(string $raw, bool $expected): void
    {
        putenv(self::KNOB . '=' . $raw);

        $this->assertSame($expected, EnvConfig::bool(self::KNOB));
    }

    public function testBoolJunkAnswersItsDefaultBothDirections(): void
    {
        putenv(self::KNOB . '=maybe');

        $defaultFalse = null;
        $defaultTrue = null;
        $this->capturedErrorLog(function () use (&$defaultFalse, &$defaultTrue): bool {
            $defaultFalse = EnvConfig::bool(self::KNOB);
            $defaultTrue = EnvConfig::bool(self::KNOB, true);

            return false;
        });

        $this->assertFalse($defaultFalse, 'junk answers false when that is the default');
        $this->assertTrue($defaultTrue, 'junk answers true when that is the default');
    }

    public function testBoolJunkWarnsOneErrorLogLineNamingKnobValueAndDefault(): void
    {
        putenv(self::KNOB . '=maybe');

        $answer = null;
        $log = $this->capturedErrorLog(function () use (&$answer): bool {
            $answer = EnvConfig::bool(self::KNOB);

            return $answer;
        });

        $this->assertFalse($answer, 'junk answers its default');
        $this->assertStringContainsString(self::KNOB, $log);
        $this->assertStringContainsString('maybe', $log);
        $this->assertStringContainsString('false', $log, 'the warning names the default it answered');
        $this->assertSame(1, substr_count($log, "\n"), 'the junk warning is exactly one line');
    }

    /** A junk value carrying a newline must still log a SINGLE line (log injection posture). */
    public function testBoolJunkWarningStaysOneLineForMultilineValues(): void
    {
        putenv(self::KNOB . '=multi' . "\n" . 'line');

        $log = $this->capturedErrorLog(fn (): bool => EnvConfig::bool(self::KNOB));

        $this->assertSame(1, substr_count($log, "\n"));
    }

    /** The truthy/false reads never warn — only junk does. */
    public function testBoolWordReadsLogNothing(): void
    {
        foreach (['on', 'off', ' false '] as $raw) {
            putenv(self::KNOB . '=' . $raw);

            $log = $this->capturedErrorLog(fn (): bool => EnvConfig::bool(self::KNOB));

            $this->assertSame('', $log, "value '{$raw}' is grammatical — it must not warn");
        }
    }

    /**
     * The raw seam: the same grammar over an already-read value — the shape
     * `Application::dbCeilingDemand()` needs (its env values are injected,
     * HUB-S17, so the pool-enabled arm is pinned by raw values, not putenv).
     */
    public function testBoolValueRawSeamDrivesTheSameGrammar(): void
    {
        $this->assertFalse(EnvConfig::boolValue(false, false, self::KNOB));
        $this->assertFalse(EnvConfig::boolValue(null, false, self::KNOB));
        $this->assertTrue(EnvConfig::boolValue('', true, self::KNOB));
        $this->assertTrue(EnvConfig::boolValue(' on ', false, self::KNOB));
        $this->assertFalse(EnvConfig::boolValue(' false', false, self::KNOB));

        $answer = null;
        $log = $this->capturedErrorLog(function () use (&$answer): bool {
            $answer = EnvConfig::boolValue('maybe', false, self::KNOB);

            return $answer;
        });

        $this->assertFalse($answer);
        $this->assertStringContainsString(self::KNOB, $log);
    }

    /**
     * Run $read with `error_log` pointed at a temp file and return what it
     * logged. `error_log()` never throws and never reaches PHPUnit's
     * warning handling — the junk leg is a recorded complaint, not a halt.
     */
    private function capturedErrorLog(callable $read): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phlix-envlog-');
        $this->assertIsString($file);

        $previous = (string) ini_get('error_log');
        ini_set('error_log', $file);

        try {
            $read();

            return (string) file_get_contents($file);
        } finally {
            ini_set('error_log', $previous);
            unlink($file);
        }
    }
}
