<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Health;

use Phlix\Hub\Health\HealthController;
use Phlix\Hub\Version;
use Phlix\Shared\Version as SharedVersion;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see HealthController}.
 *
 * @package Phlix\Hub\Tests\Health
 *
 * @covers \Phlix\Hub\Health\HealthController
 */
final class HealthControllerTest extends TestCase
{
    public function testInvokeReturnsOkStatus(): void
    {
        $payload = (new HealthController())();

        self::assertSame('ok', $payload['status']);
    }

    public function testInvokeIdentifiesAsPhlixHub(): void
    {
        $payload = (new HealthController())();

        self::assertSame('phlix-hub', $payload['service']);
    }

    public function testInvokeIncludesPackageVersion(): void
    {
        $payload = (new HealthController())();

        self::assertSame(Version::VERSION, $payload['version']);
    }

    public function testInvokeIncludesSharedVersion(): void
    {
        $payload = (new HealthController())();

        self::assertSame(SharedVersion::VERSION, $payload['phlixShared']);
    }

    public function testInvokeIncludesRecentTimestamp(): void
    {
        $before = time();
        $payload = (new HealthController())();
        $after = time();

        self::assertGreaterThanOrEqual($before, $payload['timestamp']);
        self::assertLessThanOrEqual($after, $payload['timestamp']);
    }
}
