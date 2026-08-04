<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;

/**
 * Real-MySQL round-trip for the DURABLE per-user relay THROTTLE (S42 review fix,
 * migration 043 `relay_user_settings`).
 *
 * Proves {@see RelaySessionManager::setUserThrottle()} /
 * {@see RelaySessionManager::getUserThrottleBps()} persist and read the throttle
 * from the per-user `relay_user_settings` store keyed by `user_id` ALONE — NOT
 * the period-scoped `relay_user_quotas` rollup (the source of the "throttle
 * silently reverts to 3 Mbps on the 1st of the month" bug). Skipped automatically
 * when the `HUB_TEST_DB_*` env vars are not set, matching the other integration
 * suites, so it stays green in environments without MySQL.
 *
 * Required env vars: `HUB_TEST_DB_HOST`, `HUB_TEST_DB_PORT`, `HUB_TEST_DB_USER`,
 * `HUB_TEST_DB_PASSWORD`, `HUB_TEST_DB_NAME`. The named database **must already
 * exist** and the user must have full privileges on it — every table is emptied
 * at setUp().
 *
 * S185: the connect / skip-gate / schema / data-reset boilerplate moved to
 * {@see RealDatabaseTestCase}, which builds the real schema (including migration
 * 043 `relay_user_settings`) once per process and empties every table before and
 * after each test instead of re-applying all 29 migrations twice over.
 *
 * @package Phlix\Hub\Tests\Integration\Hub
 *
 * @covers \Phlix\Hub\Hub\RelaySessionManager
 *
 * @group integration
 */
final class RelaySessionManagerThrottleIntegrationTest extends RealDatabaseTestCase
{
    private RelaySessionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $logger = $this->createMock(StructuredLogger::class);
        $this->manager = new RelaySessionManager($this->db, $logger);
    }

    public function testThrottleRoundTripsThroughDurableStore(): void
    {
        // A concrete cap persists and reads back verbatim.
        $this->manager->setUserThrottle('u-rt', 50_000_000);
        self::assertSame(50_000_000, $this->manager->getUserThrottleBps('u-rt'));

        // 0 = Unlimited is stored and returned verbatim (NOT coerced to default).
        $this->manager->setUserThrottle('u-unl', 0);
        self::assertSame(0, $this->manager->getUserThrottleBps('u-unl'));

        // An unconfigured user (no durable row) falls back to the 3 Mbps default.
        self::assertSame(
            RelaySessionManager::DEFAULT_THROTTLE_BPS,
            $this->manager->getUserThrottleBps('u-unknown'),
        );
        self::assertSame(3_000_000, RelaySessionManager::DEFAULT_THROTTLE_BPS);

        // The upsert overwrites in place (keyed by user_id alone), so re-setting a
        // user's throttle — including to Unlimited — updates the single durable row.
        $this->manager->setUserThrottle('u-rt', 0);
        self::assertSame(0, $this->manager->getUserThrottleBps('u-rt'));
        self::assertSame(1, $this->settingsRowCount('u-rt'), 'the upsert must keep exactly one row');
    }

    public function testReadIgnoresPeriodScopedQuotaRollup(): void
    {
        // Seed ONLY the period-scoped rollup (the old, buggy location) with a
        // non-default throttle and NO durable row. The durable read must ignore it
        // and fall back to the default — proving the throttle is no longer read
        // from relay_user_quotas.
        $this->seedQuotaThrottle('u-legacy', '2026-06-01', 77_000_000);
        self::assertNull($this->settingsThrottle('u-legacy'), 'precondition: no durable row yet');
        self::assertSame(
            RelaySessionManager::DEFAULT_THROTTLE_BPS,
            $this->manager->getUserThrottleBps('u-legacy'),
            'the read must come from the durable store, not the period-scoped rollup',
        );

        // Once an admin sets it durably, the durable value wins even though the
        // stale period-scoped rollup still holds a different number.
        $this->manager->setUserThrottle('u-legacy', 50_000_000);
        self::assertSame(50_000_000, $this->manager->getUserThrottleBps('u-legacy'));
        self::assertSame(
            77_000_000,
            $this->quotaThrottle('u-legacy', '2026-06-01'),
            'the period-scoped rollup is left untouched by setUserThrottle',
        );
    }

    private function seedQuotaThrottle(string $userId, string $periodStart, int $throttleBps): void
    {
        $this->db->query(
            'INSERT INTO relay_user_quotas (user_id, period_start, throttle_bps)'
            . ' VALUES (:user_id, :period_start, :throttle_bps)',
            ['user_id' => $userId, 'period_start' => $periodStart, 'throttle_bps' => $throttleBps],
        );
    }

    private function quotaThrottle(string $userId, string $periodStart): ?int
    {
        $rows = $this->db->query(
            'SELECT throttle_bps FROM relay_user_quotas WHERE user_id = :user_id AND period_start = :ps',
            ['user_id' => $userId, 'ps' => $periodStart],
        );
        return $this->firstThrottle($rows);
    }

    private function settingsThrottle(string $userId): ?int
    {
        $rows = $this->db->query(
            'SELECT throttle_bps FROM relay_user_settings WHERE user_id = :user_id',
            ['user_id' => $userId],
        );
        return $this->firstThrottle($rows);
    }

    private function settingsRowCount(string $userId): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS c FROM relay_user_settings WHERE user_id = :user_id',
            ['user_id' => $userId],
        );
        if (!is_array($rows) || $rows === []) {
            return 0;
        }
        $row = $rows[0];
        return is_array($row) && is_numeric($row['c'] ?? null) ? (int) $row['c'] : 0;
    }

    /**
     * @param mixed $rows
     */
    private function firstThrottle($rows): ?int
    {
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $row = $rows[0];
        if (!is_array($row) || !isset($row['throttle_bps']) || !is_numeric($row['throttle_bps'])) {
            return null;
        }
        return (int) $row['throttle_bps'];
    }
}
