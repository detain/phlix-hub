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

    /**
     * HUB-ENVBOOL host-lockstep characterization (answer CHANGE, red-first
     * against the old filter_var arm): junk in the opt-out knob is neither a
     * true word nor a false word, so the estate bool grammar answers the
     * documented ON default — loudly (the one-line warning is pinned at the
     * reader suite, EnvConfigTest). The old arm read any unparseable value as
     * DISABLE, disagreeing with the empty-string leg right above it (which
     * HUB-2.1 had just made mean ON): two neighbouring spellings, opposite
     * answers. Under EnvConfig::bool('DB_POOL_ENABLED', true) both answer ON,
     * and the boot ceiling check (Application::dbCeilingDemand) sees the same
     * matrix — that agreement is the point of the same-spelling rows in
     * ApplicationDbCeilingTest.
     */
    public function testPoolEnabledJunkHonorsTheOnDefault(): void
    {
        putenv('DB_POOL_ENABLED=maybe');

        // The reader's one-line junk warning is pinned at EnvConfigTest; the
        // config-side ANSWER is what this leg judges, with the warning kept
        // off stderr (PHPUnit scores stray output as risky).
        $mysql = $this->withoutWarningStream(fn (): array => $this->mysql());
        $this->assertTrue($mysql['pool_enabled']);
    }

    /**
     * Hold legs (answer-unchanged spellings, the config side of the same
     * matrix): padded/case-folded words keep answering through the trim
     * exactly as filter_var did, so no deployment spelling that works today
     * changes host under the migration.
     */
    public function testPoolEnabledVocabularyHoldsAcrossShapes(): void
    {
        putenv('DB_POOL_ENABLED= true ');
        $this->assertTrue($this->mysql()['pool_enabled']);

        putenv('DB_POOL_ENABLED=FALSE');
        $this->assertFalse($this->mysql()['pool_enabled']);

        putenv('DB_POOL_ENABLED=on');
        $this->assertTrue($this->mysql()['pool_enabled']);
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

    /**
     * Run $read with PHP's `error_log` ini target redirected to a scratch
     * file, and return its result. Re-evaluating config/database.php with a
     * junk DB_POOL_ENABLED emits EnvConfig's one-line warning via raw
     * `error_log()`; stray stderr is scored risky by this repo's phpunit.xml.
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
