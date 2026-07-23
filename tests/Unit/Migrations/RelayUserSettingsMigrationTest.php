<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * Static guards on migration `043_relay_user_settings.sql` — the S42 review fix
 * that relocates the per-user relay THROTTLE from the period-scoped
 * `relay_user_quotas` rollup to a durable per-user `relay_user_settings` store.
 *
 * These assertions run in the unit suite (no DB) so the durable-store shape and
 * the data-migration statement cannot be silently dropped or period-scoped
 * again. The real data-migration BEHAVIOUR is exercised against MySQL in
 * {@see \Phlix\Hub\Tests\Integration\Migrations\MigrationRunnerIntegrationTest}.
 *
 * @package Phlix\Hub\Tests\Unit\Migrations
 */
final class RelayUserSettingsMigrationTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../migrations/043_relay_user_settings.sql';

    private function sql(): string
    {
        $sql = file_get_contents(self::MIGRATION);
        self::assertNotFalse($sql, 'migration 043 must be readable');
        return $sql;
    }

    public function testCreatesDurablePerUserSettingsTableKeyedByUserIdAlone(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS relay_user_settings', $sql);
        self::assertStringContainsString('user_id      CHAR(36) NOT NULL', $sql);
        self::assertStringContainsString('throttle_bps BIGINT UNSIGNED NOT NULL DEFAULT 3000000', $sql);
        self::assertStringContainsString('PRIMARY KEY (user_id)', $sql);
        self::assertStringContainsString('created_at', $sql);
        self::assertStringContainsString('updated_at', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
        self::assertStringContainsString('utf8mb4', $sql);

        // The durable store itself must be keyed by user_id ALONE — no period
        // dimension. (The data-migration statement DOES read period_start from
        // the OLD rollup to pick the most-recent value; that is expected and is
        // asserted separately, so scope this check to the CREATE TABLE block.)
        $createStart = strpos($sql, 'CREATE TABLE');
        self::assertNotFalse($createStart);
        $createEnd = strpos($sql, ';', $createStart);
        self::assertNotFalse($createEnd);
        $createBlock = substr($sql, $createStart, $createEnd - $createStart);
        self::assertStringNotContainsString('period_start', $createBlock);
    }

    public function testDataMigrationPreservesMostRecentNonDefaultThrottle(): void
    {
        $sql = $this->sql();

        // Copies from the old period-scoped rollup into the new durable store.
        self::assertStringContainsString('INSERT INTO relay_user_settings', $sql);
        self::assertStringContainsString('FROM relay_user_quotas', $sql);
        // Skips the 3 Mbps auto-created default rows so a fresh-period default row
        // cannot mask an earlier admin-set value (0 = Unlimited is non-default and
        // IS carried across).
        self::assertStringContainsString('throttle_bps <> 3000000', $sql);
        // Picks the most-recent qualifying period per user.
        self::assertStringContainsString('MAX(period_start)', $sql);
        // Re-run-safe.
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE throttle_bps = VALUES(throttle_bps)', $sql);
    }
}
