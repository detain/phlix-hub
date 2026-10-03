<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Hub\MaintenanceGate;
use Phlix\Hub\Tests\Support\InMemoryHubSettingsConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MaintenanceGate} (`hub.maintenance_mode`, W5).
 *
 * Pins: the exemption allow-list answers with ZERO store reads; the one-second
 * memo bounds hot-path cost but never serves stale-forever; every outage path
 * fails OPEN to the boot default (maintenance is an operator posture, a DB
 * blip must never take a healthy hub down).
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class MaintenanceGateTest extends TestCase
{
    private int $clockNow = 1_000_000;

    // Reflection round-trips of ConnectionPool private statics: captured as
    // opaque mixed in setUp and restored verbatim in tearDown.
    /** @var mixed */
    private $connectionsSnapshot = null;

    /** @var mixed */
    private $configPathSnapshot = null;

    /** @var mixed */
    private $instanceSnapshot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionsSnapshot = self::poolProperty('connections')->getValue();
        $this->configPathSnapshot  = self::poolProperty('configPath')->getValue();
        $this->instanceSnapshot    = self::poolProperty('instance')->getValue();
    }

    protected function tearDown(): void
    {
        self::poolProperty('connections')->setValue(null, $this->connectionsSnapshot);
        self::poolProperty('configPath')->setValue(null, $this->configPathSnapshot);
        self::poolProperty('instance')->setValue(null, $this->instanceSnapshot);

        parent::tearDown();
    }

    private static function poolProperty(string $name): ReflectionProperty
    {
        $property = (new ReflectionClass(ConnectionPool::class))->getProperty($name);
        $property->setAccessible(true);

        return $property;
    }

    private function primePool(Connection $connection): void
    {
        self::poolProperty('instance')->setValue(null, new ConnectionPool());
        self::poolProperty('connections')->setValue(null, ['mysql' => $connection]);
    }

    private function gate(bool $bootEnabled = false): MaintenanceGate
    {
        return new MaintenanceGate($bootEnabled, fn (): int => $this->clockNow);
    }

    private function row(InMemoryHubSettingsConnection $db, string $value): void
    {
        $db->rows['hub.maintenance_mode'] = [
            'setting_key'  => 'hub.maintenance_mode',
            'setting_value' => $value,
            'value_type'   => 'bool',
        ];
    }

    // ------------------------------------------------------ exemption list

    public function testEveryExemptPrefixPassesWithZeroStoreReadsWhileEnabled(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $this->row($db, '1');
        $this->primePool($db);

        $gate = $this->gate();

        foreach (MaintenanceGate::EXEMPT_PREFIXES as $prefix) {
            $db->resetStatementLog();
            self::assertFalse($gate->shouldBlock($prefix . 'anything'), "prefix {$prefix} must stay reachable");
            self::assertSame([], $db->statements, 'exemption must be answered statically — no store read at all');
        }

        // Exact-boundary shapes documented by the constant.
        self::assertTrue(MaintenanceGate::isExempt('/health'));
        self::assertTrue(MaintenanceGate::isExempt('/api/v1/auth/login'));
        self::assertTrue(MaintenanceGate::isExempt('/api/v1/me/hub-settings'));
        self::assertTrue(MaintenanceGate::isExempt('/api/v1/admin/settings'));
        self::assertTrue(MaintenanceGate::isExempt('/api/v1/admin/restart'));
    }

    public function testNonExemptApiPathsAreBlockedWhileEnabled(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $this->row($db, '1');
        $this->primePool($db);

        $gate = $this->gate();

        self::assertTrue($gate->shouldBlock('/api/v1/me/servers'));
        self::assertTrue($gate->shouldBlock('/api/v1/search'));
        // Auth-adjacent paths NOT under the exempt prefix must block:
        self::assertTrue($gate->shouldBlock('/api/v1/me/invite-links'));
    }

    public function testAuthPrefixIsNotBlockingWhenDisabled(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $this->row($db, '0');
        $this->primePool($db);

        $gate = $this->gate();

        self::assertFalse($gate->shouldBlock('/api/v1/me/servers'));
    }

    // -------------------------------------------------- boot-only (no pool)

    public function testUnbootedPoolBootFlagIsTheGate(): void
    {
        self::assertNull(ConnectionPool::getInstance(), 'precondition: pool uninitialised');

        self::assertTrue($this->gate(true)->enabled());
        self::assertFalse($this->gate(false)->enabled());
    }

    // ----------------------------------------------------- live + one-second

    public function testRowFlipObservedAfterOneSecondNotBefore(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $this->row($db, '0');
        $this->primePool($db);

        $gate = $this->gate();

        self::assertFalse($gate->enabled());

        // Admin PUT lands mid-second: the 1s memo keeps this tick cheap...
        $this->row($db, '1');
        self::assertFalse($gate->enabled(), 'same-second flip is memoised, not missed forever');

        // ...and the very next second observes it — anti-stale-forever pin.
        $this->clockNow += 1;
        self::assertTrue($gate->enabled(), 'memo must tick: live read within one second of the flip');
    }

    public function testEnabledReadsHitStoreOncePerMemoWindow(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $this->row($db, '1');
        $this->primePool($db);

        $gate = $this->gate();

        $gate->enabled();
        $gate->enabled();
        $gate->enabled();

        $selects = array_filter(
            $db->statements,
            static fn (string $sql): bool => str_contains($sql, 'SELECT setting_value'),
        );
        self::assertCount(1, $selects, 'three calls inside one memo window = one SELECT');

        $this->clockNow += 1;
        $gate->enabled();
        $selects = array_filter(
            $db->statements,
            static fn (string $sql): bool => str_contains($sql, 'SELECT setting_value'),
        );
        self::assertCount(2, $selects, 'next window re-reads — the memo expires, values never freeze');
    }

    // --------------------------------------------------------- fail-open

    public function testStoreOutageFailsOpenToBootDefaultFalse(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('store down'));
        $this->primePool($db);

        self::assertFalse($this->gate(false)->enabled(), 'DB blip must never take a healthy hub down');
    }

    public function testStoreOutageRespectsBootTruePosture(): void
    {
        // HUB_MAINTENANCE_MODE=1 with a dead DB: the env posture still holds.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('store down'));
        $this->primePool($db);

        self::assertTrue($this->gate(true)->enabled());
    }

    public function testNonBoolRowFallsBackToBootDefault(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['hub.maintenance_mode'] = [
            'setting_key'  => 'hub.maintenance_mode',
            'setting_value' => 'garbage',
            'value_type'   => 'string',
        ];
        $this->primePool($db);

        self::assertFalse($this->gate(false)->enabled());
    }
}
