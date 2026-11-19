<?php

/**
 * Phlix hub test: Unit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Config;

use Phlix\Hub\Common\Database\PooledMySQLConnection;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Red-first pins for HUB-2.1 on the database pool block: the config's
 * `getenv(...) ?: <default>` arms swallowed the string "0" (the trap
 * statement at the head of the pool block's own comments), so
 * `DB_POOL_SIZE=0` silently became 8 and `HUB_DB_HOST=0` became
 * 127.0.0.1. With the ported EnvConfig reader the explicit zero now REACHES
 * the config layer — and these pins prove the pool's OWN validation floor
 * (PooledMySQLConnection clamps its ceiling to ≥ 1, exactly like the
 * server's) keeps "0" from ever producing a zero-worker pool. The same
 * before/after holds for HUB_DB_PORT ("0" arrives as 0, where `?:` used to
 * hand back 3306).
 *
 * @package Phlix\Hub\Tests\Unit\Config
 */
final class DatabasePoolSizeEnvTest extends TestCase
{
    private const ENVS = [
        'HUB_DB_HOST',
        'HUB_DB_PORT',
        'HUB_DB_NAME',
        'DB_POOL_ENABLED',
        'DB_POOL_SIZE',
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENVS as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function mysql(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/database.php';

        /** @var array<string, mixed> $mysql */
        $mysql = $config['mysql'];

        return $mysql;
    }

    public function testPoolSizeZeroReachesConfigVerbatim(): void
    {
        putenv('DB_POOL_SIZE=0');

        // RED against the `?:` era, which yielded 8 here — the dial operator
        // set was thrown away. The floor below is what makes that safe.
        $this->assertSame(0, $this->mysql()['pool_size']);
    }

    public function testPoolSizeZeroStillCannotProduceAZeroWorkerPool(): void
    {
        putenv('DB_POOL_SIZE=0');
        $poolSize = $this->mysql()['pool_size'];
        // Boundary pin: the config layer now guarantees an int here
        // (EnvConfig::integer), which is what the pool consumes.
        $this->assertIsInt($poolSize);

        // Walk the real consumer path: config value → pool front-end ceiling.
        $pool = new PooledMySQLConnection(
            '127.0.0.1',
            3306,
            'u',
            'p',
            'db',
            $poolSize,
            'utf8mb4',
            static function (): never {
                throw new RuntimeException('the ceiling pin must never open a socket');
            },
        );

        $maxSize = (new ReflectionProperty(PooledMySQLConnection::class, 'maxSize'))->getValue($pool);

        $this->assertIsInt($maxSize);
        // PooledMySQLConnection::__construct clamps max(1, $maxSize) — the
        // documented "Pool ceiling (clamped to ≥ 1)" floor, same as the
        // server's sibling class. "0" becomes a one-connection pool, never
        // a zero-connection pool.
        $this->assertGreaterThanOrEqual(1, $maxSize);
    }

    public function testHostAndPortZeroArriveVerbatim(): void
    {
        putenv('HUB_DB_HOST=0');
        putenv('HUB_DB_PORT=0');
        $mysql = $this->mysql();

        // `?:` made HUB_DB_HOST=0 silently read '127.0.0.1' and
        // HUB_DB_PORT=0 read 3306.
        $this->assertSame('0', $mysql['host']);
        $this->assertSame(0, $mysql['port']);
    }

    public function testPoolEnabledEmptyHonorsTheDefault(): void
    {
        putenv('DB_POOL_ENABLED=');

        // Set-but-empty is "not configured" under the EnvConfig convention:
        // the pool default (ON, '1') governs. The old `=== false` arm fed the
        // empty string itself to filter_var, which read it as DISABLE.
        $this->assertTrue($this->mysql()['pool_enabled']);
    }

    public function testPoolEnabledZeroStillDisables(): void
    {
        putenv('DB_POOL_ENABLED=0');

        $this->assertFalse($this->mysql()['pool_enabled']);
    }

    public function testUnsetPoolKnobsKeepDocumentedDefaults(): void
    {
        $mysql = $this->mysql();

        $this->assertTrue($mysql['pool_enabled']);
        $this->assertSame(8, $mysql['pool_size']);
        $this->assertSame('127.0.0.1', $mysql['host']);
        $this->assertSame(3306, $mysql['port']);
        $this->assertSame('phlix_hub', $mysql['database']);
    }

    public function testPoolSizeInvalidStringCastsTheWayTheSiteAlwaysDid(): void
    {
        putenv('DB_POOL_SIZE=12x');

        // (int) '12x' === 12 — pre-existing cast posture, preserved.
        $this->assertSame(12, $this->mysql()['pool_size']);
    }
}
