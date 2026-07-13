<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Common\RateLimit;

use Phlix\Hub\Common\RateLimit\DbRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-MySQL integration tests for {@see DbRateLimiter}, the shared DB-backed
 * login limiter (HB-4.6 Option B, table `login_rate_limit`, migration 040).
 *
 * The unit suite ({@see \Phlix\Hub\Tests\Unit\Common\RateLimit\DbRateLimiterTest})
 * drives a BEHAVIOURAL fake `Connection` that models the table semantics from the
 * SQL keywords — it proves the PHP logic but, by construction, models correct
 * `restart-vs-increment` semantics REGARDLESS of the real SQL column-assignment
 * order, so it cannot catch an accidental swap of the two `ON DUPLICATE KEY
 * UPDATE` SET clauses (or any other real-MySQL-specific mistake). These tests
 * close that gap by exercising the ACTUAL SQL against a real MySQL row:
 *
 * - {@see testHitAfterWindowExpiryRestartsAtOne()} is the column-ORDER guard.
 *   The upsert assigns `attempts = IF(reset_at <= now, 1, attempts + 1)` FIRST
 *   (reading the OLD `reset_at`) and `reset_at = IF(reset_at <= now, renew,
 *   reset_at)` SECOND. MySQL evaluates ODKU assignments left-to-right and a later
 *   clause sees a value assigned by an earlier one, so SWAPPING the two clauses
 *   makes `attempts` read the already-renewed (future) `reset_at` → the expired
 *   window would INCREMENT the stale counter instead of RESETTING to 1. This
 *   test asserts the reset to 1 and therefore goes RED on such a swap (verified
 *   empirically by swapping the source and watching it fail).
 * - {@see testConcurrentHitsDoNotLoseIncrements()} fires many hits at ONE key
 *   from several concurrent OS processes (each its own MySQL session) and asserts
 *   the final `attempts` equals writers × iterations — proving the atomic upsert
 *   loses no increment under genuine InnoDB row-lock contention (the shared-store
 *   race the in-memory limiter cannot have).
 *
 * Skipped automatically when the `HUB_TEST_DB_*` environment variables are not
 * set, so the suite stays green in environments without MySQL — mirroring
 * {@see \Phlix\Hub\Tests\Integration\Migrations\MigrationRunnerIntegrationTest}.
 * Required env: `HUB_TEST_DB_HOST`, `HUB_TEST_DB_PORT`, `HUB_TEST_DB_USER`,
 * `HUB_TEST_DB_PASSWORD`, `HUB_TEST_DB_NAME` (the DB must already exist and the
 * user must have full privileges on it — the `login_rate_limit` table is dropped
 * and recreated fresh at setUp()).
 *
 * @package Phlix\Hub\Tests\Integration\Common\RateLimit
 *
 * @group integration
 */
#[CoversClass(DbRateLimiter::class)]
#[Group('integration')]
final class DbRateLimiterIntegrationTest extends TestCase
{
    private const string TABLE = 'login_rate_limit';

    private Connection $db;

    protected function setUp(): void
    {
        $host = getenv('HUB_TEST_DB_HOST');
        $name = getenv('HUB_TEST_DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            self::markTestSkipped(
                'HUB_TEST_DB_* environment variables not set — skipping DbRateLimiter integration suite.',
            );
        }

        $this->db = $this->newConnection();
        $this->recreateTable();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    /**
     * A fresh key inserts a new window whose counter starts at 1 (never 0, never
     * incremented off a phantom row), with `reset_at = now + window`.
     */
    public function testFreshKeyStartsAttemptsAtOne(): void
    {
        $now = 1_000_000;
        $limiter = new DbRateLimiter($this->db, 900, 5, static fn (): int => $now);

        $state = $limiter->hit('auth:login:10.0.0.1');

        self::assertSame(1, $state->count, 'A fresh key must start at attempts=1.');
        self::assertSame(4, $state->remaining);
        self::assertFalse($state->limited);
        self::assertSame(5, $state->limit);
        self::assertSame($now + 900, $state->resetAt);
        self::assertSame(1, $this->readAttempts('auth:login:10.0.0.1'), 'Row persisted with attempts=1.');
    }

    /**
     * Repeated hits inside the window increment the SAME row and keep the
     * original `reset_at` (the window does not slide on increment), tripping
     * `limited` once `attempts >= max`.
     */
    public function testRepeatedHitsIncrementWithinWindow(): void
    {
        $now = 2_000_000;
        $limiter = new DbRateLimiter($this->db, 900, 3, static fn (): int => $now);

        $first = $limiter->hit('k');
        self::assertSame(1, $first->count);
        self::assertFalse($first->limited);

        $second = $limiter->hit('k');
        self::assertSame(2, $second->count);
        self::assertFalse($second->limited);

        $third = $limiter->hit('k');
        self::assertSame(3, $third->count);
        self::assertSame(0, $third->remaining);
        self::assertTrue($third->limited, 'Reaching max must trip the limit against a real row.');

        // reset_at is anchored to the FIRST hit's window across all increments.
        self::assertSame($now + 900, $first->resetAt);
        self::assertSame($now + 900, $third->resetAt);
        self::assertSame($now + 900, $this->readResetAt('k'));
        self::assertSame(3, $this->readAttempts('k'));
    }

    /**
     * THE column-ORDER guard. After a window has expired (`reset_at` in the
     * past), the next hit must RESTART the counter at 1 — not increment the
     * stale value. This is exactly the semantics that silently breaks if the two
     * `ON DUPLICATE KEY UPDATE` SET clauses are swapped (verified empirically:
     * swapping the source flips the observed `attempts` from 1 to the stale+1).
     */
    public function testHitAfterWindowExpiryRestartsAtOne(): void
    {
        $now = 3_000_000;
        $limiter = new DbRateLimiter($this->db, 100, 5, static fn (): int => $now);

        // Build up a stale window: attempts=2, reset_at = now + 100.
        $limiter->hit('k');
        $limiter->hit('k');
        self::assertSame(2, $this->readAttempts('k'));

        // Force the window to have expired (reset_at strictly in the past).
        $this->db->query(
            'UPDATE ' . self::TABLE . ' SET reset_at = :past WHERE rate_key = :key',
            ['past' => $now - 10, 'key' => 'k'],
        );

        $restarted = $limiter->hit('k');

        self::assertSame(
            1,
            $restarted->count,
            'An expired window must RESTART at 1 (a swapped column order would increment the stale value).',
        );
        self::assertSame(1, $this->readAttempts('k'), 'The persisted row restarted at 1, not incremented.');
        self::assertSame($now + 100, $restarted->resetAt, 'The expired window renewed reset_at to now + window.');
        self::assertSame($now + 100, $this->readResetAt('k'));
        self::assertFalse($restarted->limited);
    }

    /**
     * `peek()` must never mutate state on the login hot path (it runs on every
     * attempt): a live row's counter is unchanged by any number of peeks.
     */
    public function testPeekDoesNotMutateLiveRow(): void
    {
        $now = 4_000_000;
        $limiter = new DbRateLimiter($this->db, 900, 5, static fn (): int => $now);

        $limiter->hit('k');
        $limiter->hit('k');
        self::assertSame(2, $this->readAttempts('k'));

        $peek1 = $limiter->peek('k');
        $peek2 = $limiter->peek('k');

        self::assertSame(2, $peek1->count);
        self::assertSame(2, $peek2->count);
        self::assertSame(2, $this->readAttempts('k'), 'peek() must not change the persisted counter.');
    }

    /**
     * `peek()` on an expired window reports an empty (unlimited) state WITHOUT
     * writing — the stale row is left untouched (peek issues no DELETE/upsert).
     */
    public function testPeekReportsEmptyForExpiredWindowWithoutWriting(): void
    {
        $now = 5_000_000;
        $limiter = new DbRateLimiter($this->db, 100, 5, static fn (): int => $now);

        $limiter->hit('k');
        $this->db->query(
            'UPDATE ' . self::TABLE . ' SET reset_at = :past WHERE rate_key = :key',
            ['past' => $now - 1, 'key' => 'k'],
        );

        $state = $limiter->peek('k');

        self::assertSame(0, $state->count, 'An expired window peeks as empty.');
        self::assertFalse($state->limited);
        // The stale row is still physically present — peek performed no write.
        self::assertSame(1, $this->rowCount('k'), 'peek() must not delete the expired row.');
    }

    /**
     * `reset()` (after a successful login) deletes the bucket row entirely.
     */
    public function testResetDeletesRow(): void
    {
        $now = 6_000_000;
        $limiter = new DbRateLimiter($this->db, 900, 5, static fn (): int => $now);

        $limiter->hit('k');
        self::assertSame(1, $this->rowCount('k'));

        $limiter->reset('k');

        self::assertSame(0, $this->rowCount('k'), 'reset() removes the row.');
        self::assertSame(0, $limiter->peek('k')->count);
    }

    /**
     * The bounded sweep inside `hit()` reclaims expired rows for OTHER keys
     * (bounding table growth) while never touching the live row it just wrote.
     */
    public function testHitSweepsExpiredRowsButKeepsLiveRow(): void
    {
        $now = 7_000_000;

        // Seed three already-expired rows for keys that never returned.
        foreach (['stale-a', 'stale-b', 'stale-c'] as $stale) {
            $this->db->query(
                'INSERT INTO ' . self::TABLE . ' (rate_key, attempts, reset_at) '
                . 'VALUES (:key, 9, :past)',
                ['key' => $stale, 'past' => $now - 1],
            );
        }
        self::assertSame(3, $this->totalRows());

        $limiter = new DbRateLimiter($this->db, 900, 5, static fn (): int => $now);
        $limiter->hit('live');

        self::assertSame(0, $this->rowCount('stale-a'), 'hit() swept the expired rows.');
        self::assertSame(0, $this->rowCount('stale-b'));
        self::assertSame(0, $this->rowCount('stale-c'));
        self::assertSame(1, $this->readAttempts('live'), 'The freshly written live row survived the sweep.');
        self::assertSame(1, $this->totalRows(), 'Only the live row remains after the sweep.');
    }

    /**
     * Genuine concurrent-writer proof: several OS processes (each its own MySQL
     * session) hammer the SAME key at once; the atomic `INSERT … ON DUPLICATE
     * KEY UPDATE attempts = … attempts + 1` must lose NO increment under real
     * InnoDB row-lock contention, so the final counter equals writers × hits.
     *
     * @group slow
     */
    public function testConcurrentHitsDoNotLoseIncrements(): void
    {
        $writers = 6;
        $iterations = 25;
        $expected = $writers * $iterations;
        $key = 'auth:login:concurrent-' . bin2hex(random_bytes(4));

        $worker = __DIR__ . '/Fixtures/concurrent_hit_worker.php';
        self::assertFileExists($worker);

        // A shared, near-future start epoch so every writer's hit loop overlaps
        // (a large window ⇒ no expiry mid-run; a huge max ⇒ `limited` is moot).
        $startEpoch = microtime(true) + 0.4;
        $window = 3_600;
        $max = 1_000_000;

        $env = [
            'HUB_TEST_DB_HOST'     => (string) getenv('HUB_TEST_DB_HOST'),
            'HUB_TEST_DB_PORT'     => (string) (getenv('HUB_TEST_DB_PORT') ?: '3306'),
            'HUB_TEST_DB_USER'     => (string) getenv('HUB_TEST_DB_USER'),
            'HUB_TEST_DB_PASSWORD' => (string) getenv('HUB_TEST_DB_PASSWORD'),
            'HUB_TEST_DB_NAME'     => (string) getenv('HUB_TEST_DB_NAME'),
            'PATH'                 => (string) (getenv('PATH') ?: ''),
        ];

        /** @var list<array{proc: resource, pipes: array<int, resource>}> $running */
        $running = [];
        for ($i = 0; $i < $writers; $i++) {
            $cmd = [
                PHP_BINARY,
                $worker,
                $key,
                (string) $iterations,
                (string) $window,
                (string) $max,
                sprintf('%.6f', $startEpoch),
            ];
            $pipes = [];
            $proc = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 4),
                $env,
            );
            self::assertIsResource($proc, 'Failed to launch concurrent writer.');
            $running[] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $failures = [];
        foreach ($running as $idx => $entry) {
            $stdout = stream_get_contents($entry['pipes'][1]);
            $stderr = stream_get_contents($entry['pipes'][2]);
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            $exit = proc_close($entry['proc']);
            if ($exit !== 0 || !is_string($stdout) || !str_contains($stdout, 'ok:' . $iterations)) {
                $failures[] = "writer {$idx}: exit={$exit} stdout=" . trim((string) $stdout)
                    . ' stderr=' . trim((string) $stderr);
            }
        }

        self::assertSame([], $failures, "All concurrent writers must succeed:\n" . implode("\n", $failures));
        self::assertSame(
            $expected,
            $this->readAttempts($key),
            "Concurrent hits must not lose increments: expected {$expected} "
            . '(writers × iterations) with no lost updates under row-lock contention.',
        );
    }

    private function newConnection(): Connection
    {
        return new Connection(
            (string) getenv('HUB_TEST_DB_HOST'),
            (int) (getenv('HUB_TEST_DB_PORT') ?: '3306'),
            (string) (getenv('HUB_TEST_DB_USER') ?: 'root'),
            (string) (getenv('HUB_TEST_DB_PASSWORD') ?: ''),
            (string) getenv('HUB_TEST_DB_NAME'),
        );
    }

    /**
     * Recreate the real `login_rate_limit` table from the production migration
     * DDL (migration 040), so the tests exercise the exact deployed schema and
     * would surface schema drift.
     */
    private function recreateTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->query($this->migrationCreateStatement());
    }

    /**
     * Extract the `CREATE TABLE` statement from migration 040 (strip `--`
     * comment lines and the trailing `;`).
     */
    private function migrationCreateStatement(): string
    {
        $path = dirname(__DIR__, 4) . '/migrations/040_login_rate_limit.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql, 'Could not read migration 040.');

        $lines = array_filter(
            explode("\n", $sql),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );

        return rtrim(trim(implode("\n", $lines)), ';');
    }

    private function readAttempts(string $key): int
    {
        return $this->scalar('SELECT attempts FROM ' . self::TABLE . ' WHERE rate_key = :key', $key, 'attempts');
    }

    private function readResetAt(string $key): int
    {
        return $this->scalar('SELECT reset_at FROM ' . self::TABLE . ' WHERE rate_key = :key', $key, 'reset_at');
    }

    private function rowCount(string $key): int
    {
        $rows = $this->db->query(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE rate_key = :key',
            ['key' => $key],
        );

        return is_array($rows) ? count($rows) : 0;
    }

    private function totalRows(): int
    {
        $rows = $this->db->query('SELECT COUNT(*) AS c FROM ' . self::TABLE);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return 0;
        }

        return self::asInt($rows[0]['c'] ?? 0, 0);
    }

    private function scalar(string $sql, string $key, string $column): int
    {
        $rows = $this->db->query($sql, ['key' => $key]);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return -1;
        }

        return self::asInt($rows[0][$column] ?? -1, -1);
    }

    /**
     * Coerce a query-result cell (typed `mixed` under strict analysis) to an int,
     * falling back to `$default` for non-numeric values.
     */
    private static function asInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
