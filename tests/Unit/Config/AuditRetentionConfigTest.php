<?php

/**
 * Phlix hub component: Config tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Config;

use Phlix\Hub\ServerReaper;
use PHPUnit\Framework\TestCase;

/**
 * Pins the audit-retention env seam against the mass-delete footgun
 * (review campaign 2026-10-15): the config layer is a pure FORWARDER —
 * $envInt accepts any numeric, including 0 and negatives — so the safety
 * MUST hold at the consumer. This test walks the real production path
 * end-to-end: env → config/server.php → ServerReaper::normalizeRetentionDays,
 * proving env=0 and env=-5 resolve to the 365-day default while env=1 is
 * honored verbatim.
 *
 * If someone ever "helpfully" clamps inside config/server.php too, the
 * forwarder pins below go red and the duplication gets noticed — the
 * single clamp point is deliberate (config files stay autoload-free).
 */
final class AuditRetentionConfigTest extends TestCase
{
    private const ENV = 'HUB_AUDIT_RETENTION_DAYS';

    /** @var string|false */
    private string|false $saved;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = getenv(self::ENV);
        putenv(self::ENV);
    }

    protected function tearDown(): void
    {
        if ($this->saved === false) {
            putenv(self::ENV);
        } else {
            putenv(self::ENV . '=' . $this->saved);
        }
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function serverConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/server.php';

        return $config;
    }

    public function testEnvUnsetDefaultsTo365(): void
    {
        $config = $this->serverConfig();

        $this->assertSame(365, $config['audit_retention_days']);
        $this->assertSame(
            ServerReaper::DEFAULT_AUDIT_RETENTION_DAYS,
            $config['audit_retention_days'],
        );
    }

    public function testZeroEnvForwardsZeroButNormalizesToDefault(): void
    {
        putenv(self::ENV . '=0');
        $config = $this->serverConfig();

        // Boundary truth: config forwards the raw dial (0), unchanged.
        $this->assertSame(0, $config['audit_retention_days']);
        // Consumer truth: the clamp turns it into the safe default, NOT a
        // zero-day cutoff that would wipe audit_logs 1000 rows per tick.
        $this->assertSame(
            365,
            ServerReaper::normalizeRetentionDays(
                $config['audit_retention_days'],
                ServerReaper::DEFAULT_AUDIT_RETENTION_DAYS,
            ),
        );
    }

    public function testNegativeEnvNormalizesToDefault(): void
    {
        putenv(self::ENV . '=-5');
        $config = $this->serverConfig();

        $this->assertSame(-5, $config['audit_retention_days']);
        $this->assertSame(
            365,
            ServerReaper::normalizeRetentionDays(
                $config['audit_retention_days'],
                ServerReaper::DEFAULT_AUDIT_RETENTION_DAYS,
            ),
        );
    }

    public function testPositiveEnvIsHonoredEndToEnd(): void
    {
        putenv(self::ENV . '=1');
        $config = $this->serverConfig();

        $this->assertSame(
            1,
            ServerReaper::normalizeRetentionDays(
                $config['audit_retention_days'],
                ServerReaper::DEFAULT_AUDIT_RETENTION_DAYS,
            ),
        );
    }
}
