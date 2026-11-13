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
}
