<?php

/**
 * Phlix hub component: Application tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use Phlix\Hub\Application;
use Phlix\Hub\Tests\Support\TempLoggerCapture;
use PHPUnit\Framework\TestCase;

/**
 * HUB-S17: the boot DB-connection-ceiling check (workers x 3 connection names
 * x pool_size vs MySQL max_connections) is pinned on both halves it was split
 * into: the pure demand arithmetic + skip arms, and the ERROR report.
 */
final class ApplicationDbCeilingTest extends TestCase
{
    use TempLoggerCapture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startLoggerCapture();
    }

    protected function tearDown(): void
    {
        $this->stopLoggerCapture();
        parent::tearDown();
    }

    public function testDemandIsWorkersTimesThreeNamesTimesPoolSize(): void
    {
        self::assertSame(['demand' => 4 * 3 * 10, 'pool_size' => 10], Application::dbCeilingDemand(4, '10', false));
        self::assertSame(['demand' => 1 * 3 * 8, 'pool_size' => 8], Application::dbCeilingDemand(1, false, false));
    }

    /**
     * @return array<string, array{string|false}>
     */
    public static function unusablePoolSizes(): array
    {
        return ['unset' => [false], 'zero' => ['0'], 'negative' => ['-5'], 'garbage' => ['lots'], 'empty' => ['']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusablePoolSizes')]
    public function testUnusablePoolSizeFallsBackToTheDefault(string|false $raw): void
    {
        $plan = Application::dbCeilingDemand(2, $raw, false);
        self::assertNotNull($plan);
        self::assertSame(Application::DB_POOL_SIZE_DEFAULT, $plan['pool_size']);
        self::assertSame(2 * 3 * Application::DB_POOL_SIZE_DEFAULT, $plan['demand']);
    }

    public function testDisabledPoolSkipsTheCheck(): void
    {
        foreach (['0', 'false', 'off', 'no'] as $off) {
            self::assertNull(Application::dbCeilingDemand(8, '8', $off), $off);
        }
        self::assertNotNull(Application::dbCeilingDemand(8, '8', '1'));
        self::assertNotNull(Application::dbCeilingDemand(8, '8', 'true'));
    }

    /**
     * HUB-ENVBOOL host-lockstep characterization (answer CHANGE, red-first
     * against the old filter_var arm): `DB_POOL_ENABLED=""` boots the pool ON
     * on the config side — that is the documented HUB-2.1 posture the pool
     * block states verbatim — yet the boot check here read "" through
     * filter_var as DISABLED and skipped the ceiling silently. Under the one
     * estate bool grammar (EnvConfig::boolValue, default ON) "" is
     * "not-configured" and the default plans the check, matching the host the
     * config actually arms.
     */
    public function testEmptyPoolEnabledPlansTheCeilingLikeTheConfigSide(): void
    {
        self::assertNotNull(Application::dbCeilingDemand(8, '8', ''));
    }

    /**
     * Junk in the opt-out knob answers the ON default and is loud rather than
     * silently assuming DISABLED (the E-04 posture the server's reader
     * documents; the one-line warning itself is pinned at the reader suite —
     * EnvConfigTest). Red against the old arm, which skipped the ceiling for
     * any unparseable value.
     */
    public function testJunkPoolEnabledPlansTheCeiling(): void
    {
        // The junk leg's one-line warning is EnvConfig behaviour pinned at the
        // reader suite; here only the ANSWER is judged — with the warning kept
        // off stderr (PHPUnit scores stray output as risky).
        self::assertNotNull($this->withoutWarningStream(fn (): mixed => Application::dbCeilingDemand(8, '8', 'maybe')));
    }

    /**
     * Hold legs (answer-unchanged spellings): the shared grammar's trim +
     * case-fold agree with the old filter_var arm for padded false words and
     * upper-case words, and unset still plans via the default.
     */
    public function testPoolEnabledVocabularyHoldsAcrossShapes(): void
    {
        foreach ([' off ', "\tfalse", 'FALSE', 'No'] as $off) {
            self::assertNull(Application::dbCeilingDemand(8, '8', $off), $off);
        }
        self::assertNotNull(Application::dbCeilingDemand(8, '8', 'ON'));
    }

    public function testDemandMeetingOrExceedingMaxConnectionsLogsAnError(): void
    {
        // Equality counts: demand must be strictly BELOW the ceiling.
        self::assertTrue(Application::reportDbCeiling(4, 96, 8, 96));
        $log = $this->capturedLoggerOutput();
        self::assertStringContainsString('DB connection ceiling', $log);
        self::assertStringContainsString('"demand":96', $log);
        self::assertStringContainsString('"max_connections":96', $log);

        $this->clearLoggerCapture();
        self::assertTrue(Application::reportDbCeiling(8, 192, 8, 151));
        self::assertStringContainsString('"max_connections":151', $this->capturedLoggerOutput());
    }

    public function testDemandBelowTheCeilingOrAFailedProbeStaysSilent(): void
    {
        self::assertFalse(Application::reportDbCeiling(4, 96, 8, 97));
        self::assertFalse(Application::reportDbCeiling(4, 96, 8, 0), 'a failed max_connections probe must not alarm');
        self::assertFalse(Application::reportDbCeiling(4, 96, 8, -1));
        self::assertStringNotContainsString('DB connection ceiling', $this->capturedLoggerOutput());
    }

    /**
     * Run $read with PHP's `error_log` ini target redirected to a scratch
     * file. Distinct from {@see TempLoggerCapture} (which redirects the
     * Monolog factory): EnvConfig's junk warning is a raw `error_log()`
     * line, and stray stderr output is scored risky by this repo's
     * phpunit.xml. The warning's CONTENT is pinned at EnvConfigTest.
     *
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function withoutWarningStream(callable $read): mixed
    {
        $file = tempnam(sys_get_temp_dir(), 'phlix-envbool-');
        self::assertIsString($file);

        $previous = (string) ini_get('error_log');
        ini_set('error_log', $file);

        try {
            return $read();
        } finally {
            ini_set('error_log', $previous);
            unlink($file);
        }
    }
}
