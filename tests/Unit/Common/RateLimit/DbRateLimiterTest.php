<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\RateLimit;

use Phlix\Hub\Common\RateLimit\DbRateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimitState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Behaviour tests for {@see DbRateLimiter} — the shared, DB-backed login
 * limiter (HB-4.6 Option B, table `login_rate_limit`, migration 040).
 *
 * The DB is driven by a behavioural fake ({@see makeFakeDb()}) that models the
 * `login_rate_limit` table semantics from the SQL keywords, so the tests prove
 * the FULL hit/peek/reset/window-expiry behaviour matches the in-memory
 * {@see \Phlix\Hub\Common\RateLimit\RateLimiter} — not just query-string shape.
 * Time is driven by an injected clock so windows advance with no real sleeps.
 * Repository-style DB classes in this repo are unit-tested against a mock
 * {@see Connection} (the coroutine-safety of the socket lives in and is tested
 * on {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection}, not here).
 *
 * @covers \Phlix\Hub\Common\RateLimit\DbRateLimiter
 */
#[CoversClass(DbRateLimiter::class)]
#[CoversClass(RateLimitState::class)]
final class DbRateLimiterTest extends TestCase
{
    /**
     * @var list<array{sql: string, params: array<string, mixed>}> Captured queries.
     */
    private array $log = [];

    /**
     * @var array<string, array{attempts: int, reset_at: int}> In-memory table.
     */
    private array $table = [];

    protected function setUp(): void
    {
        $this->log = [];
        $this->table = [];
    }

    public function testHitInsertsFreshWindowAtOne(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);

        $state = $limiter->hit('auth:login:1.2.3.4');

        self::assertSame(1, $state->count);
        self::assertSame(4, $state->remaining);
        self::assertFalse($state->limited);
        self::assertSame(5, $state->limit);
        self::assertSame($now + 900, $state->resetAt);
    }

    public function testHitIncrementsAndTripsLimitWithinWindow(): void
    {
        $now = 2_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 3, static fn (): int => $now);

        $first = $limiter->hit('k');
        self::assertSame(1, $first->count);
        self::assertFalse($first->limited);

        $limiter->hit('k');
        $third = $limiter->hit('k');
        self::assertSame(3, $third->count);
        self::assertSame(0, $third->remaining);
        self::assertTrue($third->limited, 'Reaching maxAttempts must trip the limit.');

        // reset_at is fixed at the FIRST hit's window (does not slide on increment).
        self::assertSame($now + 900, $third->resetAt);

        $fourth = $limiter->hit('k');
        self::assertSame(4, $fourth->count);
        self::assertSame(0, $fourth->remaining, 'remaining clamps at 0.');
        self::assertTrue($fourth->limited);
    }

    public function testDistinctKeysHaveIndependentBuckets(): void
    {
        $now = 500;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 100, 2, static fn (): int => $now);

        $limiter->hit('ip-a');
        $limiter->hit('ip-a');
        self::assertTrue($limiter->peek('ip-a')->limited);
        self::assertFalse($limiter->peek('ip-b')->limited, 'A separate key is unaffected.');
    }

    public function testHitRestartsWindowAfterExpiry(): void
    {
        $now = 1_000;
        $clock = $now;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 100, 5, static function () use (&$clock): int {
            return $clock;
        });

        $limiter->hit('k');
        $limiter->hit('k');
        self::assertSame(2, $limiter->peek('k')->count);

        // Advance past the window: the next hit restarts the counter at 1.
        $clock = $now + 101;
        $restarted = $limiter->hit('k');
        self::assertSame(1, $restarted->count);
        self::assertSame($clock + 100, $restarted->resetAt);
    }

    public function testPeekReportsEmptyWhenNoRecord(): void
    {
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => 10);

        $state = $limiter->peek('missing');
        self::assertSame(0, $state->count);
        self::assertSame(5, $state->remaining);
        self::assertSame(0, $state->resetAt);
        self::assertFalse($state->limited);
        self::assertSame(5, $state->limit);
    }

    public function testPeekReportsEmptyForExpiredWindowWithoutWriting(): void
    {
        $now = 1_000;
        $clock = $now;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 100, 5, static function () use (&$clock): int {
            return $clock;
        });

        $limiter->hit('k');
        $clock = $now + 200; // past expiry
        $before = count($this->log); // isolate the peek's queries

        $state = $limiter->peek('k');
        self::assertSame(0, $state->count, 'Expired window reports empty.');
        self::assertFalse($state->limited);

        // peek is read-only: it must NOT write on the login hot path.
        foreach (array_slice($this->log, $before) as $entry) {
            self::assertStringStartsWith(
                'SELECT',
                ltrim($entry['sql']),
                'peek() must issue only reads, never a write.'
            );
        }
    }

    public function testResetClearsBucket(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);

        $limiter->hit('k');
        $limiter->hit('k');
        self::assertSame(2, $limiter->peek('k')->count);

        $limiter->reset('k');
        self::assertSame(0, $limiter->peek('k')->count, 'reset() empties the bucket.');
        self::assertFalse($limiter->peek('k')->limited);
    }

    public function testHitUpsertsAndRunsBoundedSweep(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);
        $limiter->hit('k');

        $upsert = array_filter(
            $this->log,
            static fn (array $e): bool => str_contains($e['sql'], 'INSERT')
                && str_contains($e['sql'], 'ON DUPLICATE KEY UPDATE')
        );
        self::assertNotEmpty($upsert, 'hit() must upsert the counter atomically.');

        $sweep = array_filter(
            $this->log,
            static fn (array $e): bool => str_contains($e['sql'], 'DELETE')
                && str_contains($e['sql'], 'reset_at')
                && str_contains($e['sql'], 'LIMIT')
        );
        self::assertNotEmpty($sweep, 'hit() must run a bounded LIMITed sweep of expired rows.');
    }

    /**
     * Regression guard for the phlix-server 1064 bug: under emulated prepares a
     * string-bound LIMIT renders `LIMIT '100'` → MySQL 1064. The sweep MUST bind
     * both numeric params as ints. A future `(string)` cast turns this red.
     */
    public function testSweepBindsNumericParamsAsInt(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);
        $limiter->hit('k');

        $sweep = null;
        foreach ($this->log as $entry) {
            if (str_contains($entry['sql'], 'DELETE') && str_contains($entry['sql'], 'LIMIT')) {
                $sweep = $entry['params'];
            }
        }

        self::assertNotNull($sweep, 'Expected a bounded DELETE ... LIMIT sweep.');
        self::assertArrayHasKey('batch', $sweep);
        self::assertArrayHasKey('threshold', $sweep);
        self::assertIsInt($sweep['batch'], 'LIMIT must be an int (PARAM_STR would quote it → 1064).');
        self::assertIsInt($sweep['threshold'], 'reset_at threshold must be an int (INT UNSIGNED column).');
    }

    /**
     * Hub DB rule: named `:param` placeholders only (positional `?` breaks
     * bindMore()) and every param key must be COLON-FREE (workerman's bind()
     * prepends the colon itself).
     */
    public function testEveryQueryUsesNamedColonFreeParams(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 900, 5, static fn (): int => $now);

        $limiter->hit('k');
        $limiter->peek('k');
        $limiter->reset('k');

        self::assertNotEmpty($this->log);
        foreach ($this->log as $entry) {
            self::assertStringNotContainsString(
                '?',
                $entry['sql'],
                'No positional placeholders — hub requires named :params.'
            );
            foreach (array_keys($entry['params']) as $paramKey) {
                self::assertIsString($paramKey);
                self::assertStringStartsNotWith(':', $paramKey, 'Param keys must be colon-free.');
                self::assertStringContainsString(
                    ':' . $paramKey,
                    $entry['sql'],
                    "Every bound param must have a matching :placeholder in the SQL ({$paramKey})."
                );
            }
        }
    }

    /**
     * Non-positive window/max clamp to 1 (never a divide-by-zero / never-limited
     * bucket), mirroring the in-memory {@see \Phlix\Hub\Common\RateLimit\RateLimiter}.
     */
    public function testNonPositiveThresholdsClampToOne(): void
    {
        $now = 1_000;
        $limiter = new DbRateLimiter($this->makeFakeDb(), 0, 0, static fn (): int => $now);

        $state = $limiter->hit('k');
        self::assertSame(1, $state->limit, 'maxAttempts clamps to 1.');
        self::assertTrue($state->limited, 'A single hit trips a max-of-1 bucket.');
        self::assertSame($now + 1, $state->resetAt, 'window clamps to 1 second.');
    }

    /**
     * A mock {@see Connection} whose `query()` models the `login_rate_limit`
     * table well enough to exercise the real hit/peek/reset/expiry semantics.
     */
    private function makeFakeDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<string, mixed>|null $params
             * @return list<array<string, int>>|bool
             */
            function (string $sql, ?array $params = null): array|bool {
                $params ??= [];
                /** @var array<string, mixed> $params */
                $this->log[] = ['sql' => $sql, 'params' => $params];

                if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
                    return $this->applyUpsert($params);
                }
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    return $this->applySelect($params);
                }
                if (str_contains($sql, 'DELETE') && str_contains($sql, 'reset_at')) {
                    return $this->applySweep($params);
                }
                if (str_contains($sql, 'DELETE') && str_contains($sql, 'rate_key')) {
                    unset($this->table[self::paramStr($params, 'rateKey')]);
                    return true;
                }

                return true;
            }
        );

        return $db;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyUpsert(array $params): bool
    {
        $key = self::paramStr($params, 'rateKey');
        $now = self::paramInt($params, 'nowCheck');
        $fresh = self::paramInt($params, 'freshReset');

        $existing = $this->table[$key] ?? null;
        if ($existing === null || $existing['reset_at'] <= $now) {
            $this->table[$key] = ['attempts' => 1, 'reset_at' => $fresh];
        } else {
            $this->table[$key]['attempts']++;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array{attempts: int, reset_at: int}>
     */
    private function applySelect(array $params): array
    {
        $key = self::paramStr($params, 'rateKey');
        $row = $this->table[$key] ?? null;

        return $row === null ? [] : [$row];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applySweep(array $params): bool
    {
        $threshold = self::paramInt($params, 'threshold');
        $batch = self::paramInt($params, 'batch');

        $removed = 0;
        foreach ($this->table as $key => $row) {
            if ($removed >= $batch) {
                break;
            }
            if ($row['reset_at'] <= $threshold) {
                unset($this->table[$key]);
                $removed++;
            }
        }

        return true;
    }

    /**
     * Coerce a bound param (typed `mixed`) to a string for the fake table.
     *
     * @param array<string, mixed> $params
     */
    private static function paramStr(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Coerce a bound param (typed `mixed`) to an int for the fake table.
     *
     * @param array<string, mixed> $params
     */
    private static function paramInt(array $params, string $key): int
    {
        $value = $params[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
