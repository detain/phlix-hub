<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Hub\HubSettingsResolvers;
use Phlix\Hub\Tests\Support\InMemoryHubSettingsConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see HubSettingsResolvers} (W5 Phase-6 settings program).
 *
 * Pins the three laws every toggle consumer depends on:
 * 1. unreachable store (no pool / DB failure) -> boot default, never a throw;
 * 2. live semantics -> every call re-SELECTs (no memoised VALUE);
 * 3. numeric results are clamped to bounds on EVERY read, bad rows harmless.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class HubSettingsResolversTest extends TestCase
{
    /** The W5 float key (long enough to break line limits inline). */
    private const string GRACE = 'server.relay.reconnect_drain_grace_seconds';
    /** @var array<string, mixed> */
    private array $connectionsSnapshot = [];

    private string $configPathSnapshot = '';

    private ?ConnectionPool $instanceSnapshot = null;

    private bool $snapshotTaken = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotTaken = true;
        /** @var array<string, mixed> $connections */
        $connections = self::poolProperty('connections')->getValue();
        $this->connectionsSnapshot = $connections;
        /** @var string $path */
        $path = self::poolProperty('configPath')->getValue();
        $this->configPathSnapshot = $path;
        /** @var ConnectionPool|null $instance */
        $instance = self::poolProperty('instance')->getValue();
        $this->instanceSnapshot = $instance;
    }

    protected function tearDown(): void
    {
        if ($this->snapshotTaken) {
            self::poolProperty('connections')->setValue(null, $this->connectionsSnapshot);
            self::poolProperty('configPath')->setValue(null, $this->configPathSnapshot);
            self::poolProperty('instance')->setValue(null, $this->instanceSnapshot);
        }

        parent::tearDown();
    }

    private static function poolProperty(string $name): ReflectionProperty
    {
        $property = (new ReflectionClass(ConnectionPool::class))->getProperty($name);
        $property->setAccessible(true);

        return $property;
    }

    /**
     * Prime the process-global pool so getInstance() !== null while
     * getConnection('mysql') returns the supplied double.
     */
    private function primePool(Connection $connection): void
    {
        self::poolProperty('instance')->setValue(null, new ConnectionPool());
        self::poolProperty('connections')->setValue(null, ['mysql' => $connection]);
    }

    // ------------------------------------------------------- unbooted pool

    public function testUnbootedPoolEveryFactoryReturnsBootDefault(): void
    {
        // Default process state in unit runs: getInstance() === null.
        self::assertNull(ConnectionPool::getInstance(), 'precondition: pool uninitialised');

        self::assertTrue((HubSettingsResolvers::bool('federation.enabled', true))());
        self::assertFalse((HubSettingsResolvers::bool('hub.maintenance_mode', false))());
        self::assertSame(3, (HubSettingsResolvers::int('invite.default_expiry_seconds', 3, 0, 10))());
        self::assertSame(2.5, (HubSettingsResolvers::float(self::GRACE, 2.5, 0.0, 300.0))());
        self::assertSame('fallback', (HubSettingsResolvers::string('server.arr.sonarr.url', 'fallback'))());
        self::assertSame(['fallback' => 1], (HubSettingsResolvers::array('server.rate_limit', ['fallback' => 1]))());
    }

    // ------------------------------------------------------------- bool

    public function testBoolTrueOverrideRow(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['hub.maintenance_mode'] = [
            'setting_key' => 'hub.maintenance_mode',
            'setting_value' => '1',
            'value_type' => 'bool',
        ];
        $this->primePool($db);

        self::assertTrue((HubSettingsResolvers::bool('hub.maintenance_mode', false))());
    }

    public function testBoolFalseOverrideRowBeatsTrueBootDefault(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['federation.enabled'] = [
            'setting_key' => 'federation.enabled',
            'setting_value' => '0',
            'value_type' => 'bool',
        ];
        $this->primePool($db);

        self::assertFalse((HubSettingsResolvers::bool('federation.enabled', true))());
    }

    public function testBoolMissingRowFallsBackToEffectiveDefault(): void
    {
        // No override row: getEffective answers the REAL config default
        // (config/federation.php ships enabled=true), never the caller's boot
        // arg — boot arg only guards store outages.
        $db = new InMemoryHubSettingsConnection();
        $this->primePool($db);

        self::assertTrue((HubSettingsResolvers::bool('federation.enabled', false))());
    }

    public function testBoolNonBoolRowFallsBackToBootDefault(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['federation.enabled'] = [
            'setting_key' => 'federation.enabled',
            'setting_value' => 'yes',
            'value_type' => 'string',
        ];
        $this->primePool($db);

        self::assertTrue((HubSettingsResolvers::bool('federation.enabled', true))());
        self::assertFalse((HubSettingsResolvers::bool('federation.enabled', false))());
    }

    // -------------------------------------------------------------- int

    public function testIntOverrideRowIsClampedToBounds(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.max_servers_per_user'] = [
            'setting_key' => 'server.max_servers_per_user',
            'setting_value' => '99999',
            'value_type' => 'int',
        ];
        $this->primePool($db);

        // Schema bound is 1000; the clamp makes a hand-edited bad row harmless.
        self::assertSame(1000, (HubSettingsResolvers::int('server.max_servers_per_user', 0, 0, 1000))());
    }

    public function testIntNegativeOverrideRowClampsToMinimum(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.metrics.retention_days'] = [
            'setting_key' => 'server.metrics.retention_days',
            'setting_value' => '-5',
            'value_type' => 'int',
        ];
        $this->primePool($db);

        self::assertSame(1, (HubSettingsResolvers::int('server.metrics.retention_days', 7, 1, 3650))());
    }

    public function testIntZeroIsARealValueNotAMissingRow(): void
    {
        // 0 means "unlimited" for the cap keys — it must survive both the
        // override path and the bounds clamp (min 0), never degrade to boot.
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.max_users_per_server'] = [
            'setting_key' => 'server.max_users_per_server',
            'setting_value' => '0',
            'value_type' => 'int',
        ];
        $this->primePool($db);

        self::assertSame(0, (HubSettingsResolvers::int('server.max_users_per_server', 50, 0, 10000))());
    }

    public function testIntNonNumericRowFallsBackToClampedBootDefault(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['invite.default_expiry_seconds'] = [
            'setting_key' => 'invite.default_expiry_seconds',
            'setting_value' => 'soon',
            'value_type' => 'string',
        ];
        $this->primePool($db);

        self::assertSame(50, (HubSettingsResolvers::int('invite.default_expiry_seconds', 50, 0, 31536000))());
    }

    // ------------------------------------------------------------ float

    public function testFloatOverrideRowClampedToUpperBound(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.relay.reconnect_drain_grace_seconds'] = [
            'setting_key' => 'server.relay.reconnect_drain_grace_seconds',
            'setting_value' => '400',
            'value_type' => 'float',
        ];
        $this->primePool($db);

        self::assertSame(300.0, (HubSettingsResolvers::float(self::GRACE, 5.0, 0.0, 300.0))());
    }

    public function testFloatOverrideRowClampedToLowerBound(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.relay.reconnect_drain_grace_seconds'] = [
            'setting_key' => 'server.relay.reconnect_drain_grace_seconds',
            'setting_value' => '-1.5',
            'value_type' => 'float',
        ];
        $this->primePool($db);

        self::assertSame(0.0, (HubSettingsResolvers::float(self::GRACE, 5.0, 0.0, 300.0))());
    }

    public function testFloatMissingRowUsesRealConfigDefault(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $this->primePool($db);

        // config/server.php relay.reconnect_drain_grace_seconds = 5.0.
        self::assertSame(5.0, (HubSettingsResolvers::float(self::GRACE, 99.0, 0.0, 300.0))());
    }

    // ----------------------------------------------------------- string

    public function testStringOverrideRowIsTrimmed(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.arr.sonarr.url'] = [
            'setting_key' => 'server.arr.sonarr.url',
            'setting_value' => "  https://arr.example.com/  \n",
            'value_type' => 'string',
        ];
        $this->primePool($db);

        $resolver = HubSettingsResolvers::string('server.arr.sonarr.url', 'http://localhost:8989');

        self::assertSame('https://arr.example.com/', $resolver());
    }

    public function testStringBlankOverrideRowFallsBackToBootDefault(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.arr.radarr.url'] = [
            'setting_key' => 'server.arr.radarr.url',
            'setting_value' => '   ',
            'value_type' => 'string',
        ];
        $this->primePool($db);

        $resolver = HubSettingsResolvers::string('server.arr.radarr.url', 'http://localhost:7878');

        self::assertSame('http://localhost:7878', $resolver());
    }

    // ------------------------------------------------------------ array

    public function testArrayOverrideRowDecodesJson(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.rate_limit'] = [
            'setting_key' => 'server.rate_limit',
            'setting_value' => '{"login":{"max":9,"window":120}}',
            'value_type' => 'json',
        ];
        $this->primePool($db);

        self::assertSame(
            ['login' => ['max' => 9, 'window' => 120]],
            (HubSettingsResolvers::array('server.rate_limit', []))(),
        );
    }

    public function testArrayScalarRowFallsBackToBootDefault(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.rate_limit'] = [
            'setting_key' => 'server.rate_limit',
            'setting_value' => '"not an object"',
            'value_type' => 'json',
        ];
        $this->primePool($db);

        self::assertSame(['boot' => true], (HubSettingsResolvers::array('server.rate_limit', ['boot' => true]))());
    }

    // ------------------------------------------------------ live + safety

    public function testResolverReReadsEveryCallNeverMemoisesValue(): void
    {
        $db = new InMemoryHubSettingsConnection();
        $db->rows['server.max_servers_per_user'] = [
            'setting_key' => 'server.max_servers_per_user',
            'setting_value' => '1',
            'value_type' => 'int',
        ];
        $this->primePool($db);

        $resolver = HubSettingsResolvers::int('server.max_servers_per_user', 0, 0, 1000);

        self::assertSame(1, $resolver());
        $db->rows['server.max_servers_per_user']['setting_value'] = '2';
        self::assertSame(2, $resolver(), 'LIVE read: the second call must observe the new row');

        $selects = array_values(array_filter(
            $db->statements,
            static fn (string $sql): bool => str_contains($sql, 'SELECT setting_value'),
        ));
        self::assertCount(2, $selects, 'one SELECT per call — the memoised thing is the repository, never the value');
    }

    public function testDbFailureFallsBackToBootDefault(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('store down'));
        $this->primePool($db);

        self::assertSame(7, (HubSettingsResolvers::int('server.metrics.retention_days', 7, 1, 3650))());
        self::assertTrue((HubSettingsResolvers::bool('federation.enabled', true))());
    }

    public function testEveryFactorySharesTheOneLiveGuard(): void
    {
        // A booted pool whose handle explodes mid-flight must degrade each
        // factory to ITS boot default — one outage, five safe answers.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new RuntimeException('store down'));
        $this->primePool($db);

        self::assertTrue((HubSettingsResolvers::bool('federation.enabled', true))());
        self::assertSame(5, (HubSettingsResolvers::int('invite.default_expiry_seconds', 5, 0, 31536000))());
        self::assertSame(9.0, (HubSettingsResolvers::float(self::GRACE, 9.0, 0.0, 300.0))());
        self::assertSame('boot-url', (HubSettingsResolvers::string('server.arr.sonarr.url', 'boot-url'))());
        self::assertSame(['boot' => true], (HubSettingsResolvers::array('server.rate_limit', ['boot' => true]))());
    }
}
