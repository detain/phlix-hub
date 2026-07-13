<?php

/**
 * Phlix hub component: RateLimit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\RateLimit;

use Closure;
use Workerman\MySQL\Connection;

/**
 * Shared, DB-backed TTL-windowed rate limiter (HB-4.6 "Option B").
 *
 * A cluster/cross-worker-safe {@see RateLimiterInterface} implementation
 * backed by the `login_rate_limit` table (migration 040). One row per opaque
 * bucket key (`rate_key` natural PK, e.g. `auth:login:<ip>`) holds the current
 * window's `attempts` counter and its `reset_at` Unix expiry, so EVERY worker
 * process shares one counter for a key.
 *
 * ## Why (vs the worker-local {@see RateLimiter})
 *
 * The login limiter (5 / 900s) is enforced in {@see \Phlix\Hub\Auth\AuthManager}
 * on the `HUB_WORKERS` HTTP workers. The in-memory {@see RateLimiter} keeps its
 * bucket map on the instance, so each worker counts INDEPENDENTLY and the real
 * brute-force budget is roughly `max × HUB_WORKERS` (e.g. ~20 / 900 with 4
 * workers) instead of the intended 5 / 900 — a genuine weakening on the one
 * surface where it matters. Backing the login bucket with a single shared row
 * per key unifies the counter across all workers. The other five surfaces stay
 * on the worker-local {@see RateLimiter} (per-worker weakening is acceptable
 * there); only {@see RateLimitProfiles::LOGIN} is repointed here.
 *
 * ## Semantics (identical to {@see RateLimiter})
 *
 * - {@see hit()} records one attempt: a fresh (or expired) window restarts the
 *   counter at 1 with `reset_at = now + window`; an active window increments
 *   and keeps its `reset_at`. Returns the resulting {@see RateLimitState}
 *   (`limited` once `attempts >= max`). The counter mutation is an atomic
 *   `INSERT … ON DUPLICATE KEY UPDATE` so concurrent workers can't lose an
 *   increment (the shared-store race the in-memory version cannot have).
 * - {@see peek()} inspects WITHOUT recording (read-only, no writes on the login
 *   hot path — it runs on every attempt); an absent or expired window reports
 *   an empty, unlimited state.
 * - {@see reset()} clears the bucket row after a successful auth.
 *
 * ## DB conventions (hub rules)
 *
 * - Only {@see Connection} with NAMED `:param` placeholders (colon-free keys —
 *   positional `?` breaks `bindMore()`; see {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection}).
 *   A placeholder that repeats in the SQL is bound under a DISTINCT key each
 *   time (no reused named params) so emulated prepares stay unambiguous.
 * - The bounded sweep binds its `LIMIT` as a native PHP INT. Under the hub's
 *   emulated prepares a string would be quoted (`LIMIT '100'`) → MySQL 1064
 *   (phlix-server hit exactly this). {@see PhlixMySQLConnection::execute()}
 *   binds ints as `PARAM_INT` (unquoted); passing an int is the correct type
 *   and defends against a future regression.
 * - Injected the pooled `'mysql'` {@see Connection} (NOT the dedicated `'txn'`
 *   connection): these are simple single-statement reads/writes, not a
 *   multi-statement transaction.
 *
 * ## Table bound
 *
 * The table holds one row per distinct key (not per attempt), but expired rows
 * for keys that never return accumulate, so {@see hit()} runs a bounded
 * `DELETE … WHERE reset_at <= :threshold LIMIT :batch` sweep to keep it from
 * growing without bound — mirroring phlix-server's store.
 *
 * @package Phlix\Hub\Common\RateLimit
 */
final class DbRateLimiter implements RateLimiterInterface
{
    /** Expired rows removed per {@see hit()} sweep batch (bounds the table). */
    private const int CLEANUP_BATCH_SIZE = 100;

    /** Validated window length in seconds (>= 1). */
    private readonly int $windowSeconds;

    /** Validated maximum attempts per window (>= 1). */
    private readonly int $maxAttempts;

    /**
     * Unix-timestamp clock. Injectable so tests advance time deterministically;
     * defaults to {@see time()}.
     *
     * @var Closure(): int
     */
    private readonly Closure $clock;

    /**
     * @param Connection             $db            pooled `'mysql'` connection (NOT `'txn'`)
     * @param int                    $windowSeconds length of the counting window in seconds
     * @param int                    $maxAttempts   attempts allowed within a window before `limited`
     * @param (callable(): int)|null $clock         unix-timestamp source (defaults to {@see time()})
     */
    public function __construct(
        private readonly Connection $db,
        int $windowSeconds = 900,
        int $maxAttempts = 5,
        ?callable $clock = null,
    ) {
        $this->windowSeconds = $windowSeconds > 0 ? $windowSeconds : 1;
        $this->maxAttempts = $maxAttempts > 0 ? $maxAttempts : 1;

        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn (): int => time();
    }

    /**
     * @inheritDoc
     */
    public function hit(string $key): RateLimitState
    {
        $now = ($this->clock)();
        $reset = $now + $this->windowSeconds;

        // Atomic increment/restart. A fresh or expired window restarts at 1 with
        // a new reset_at; an active window increments and keeps its reset_at.
        // Every repeated placeholder gets a DISTINCT key (no reused named params).
        $this->db->query(
            'INSERT INTO login_rate_limit (rate_key, attempts, reset_at) '
            . 'VALUES (:rateKey, 1, :freshReset) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'attempts = IF(reset_at <= :nowCheck, 1, attempts + 1), '
            . 'reset_at = IF(reset_at <= :nowReset, :renewReset, reset_at)',
            [
                'rateKey'    => $key,
                'freshReset' => $reset,
                'nowCheck'   => $now,
                'nowReset'   => $now,
                'renewReset' => $reset,
            ],
        );

        $row = $this->fetchBucket($key);

        // Bounded reclamation of stale rows (post-write, low-frequency failure
        // path). Never touches the row we just wrote (its reset_at > now).
        $this->sweepExpired($now);

        if ($row === null) {
            // Raced with a concurrent reset()/sweep — report our own single hit.
            return $this->stateFrom(1, $reset);
        }

        return $this->stateFrom($row['attempts'], $row['reset_at']);
    }

    /**
     * @inheritDoc
     */
    public function reset(string $key): void
    {
        $this->db->query(
            'DELETE FROM login_rate_limit WHERE rate_key = :rateKey',
            ['rateKey' => $key],
        );
    }

    /**
     * @inheritDoc
     */
    public function peek(string $key): RateLimitState
    {
        $now = ($this->clock)();
        $row = $this->fetchBucket($key);

        if ($row === null || $row['reset_at'] <= $now) {
            return $this->emptyState();
        }

        return $this->stateFrom($row['attempts'], $row['reset_at']);
    }

    /**
     * Read the bucket row for `$key`, or null when absent.
     *
     * @return array{attempts: int, reset_at: int}|null
     */
    private function fetchBucket(string $key): ?array
    {
        $result = $this->db->query(
            'SELECT attempts, reset_at FROM login_rate_limit WHERE rate_key = :rateKey',
            ['rateKey' => $key],
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return [
            'attempts' => self::toInt($row['attempts'] ?? 0),
            'reset_at' => self::toInt($row['reset_at'] ?? 0),
        ];
    }

    /**
     * Coerce a query-result cell (typed `mixed` under strict analysis) to a
     * non-negative int, defaulting to 0 for non-numeric values.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Bounded sweep of expired rows. The `LIMIT` is a native int so emulated
     * prepares don't quote it (see class docblock).
     */
    private function sweepExpired(int $now): void
    {
        $this->db->query(
            'DELETE FROM login_rate_limit WHERE reset_at <= :threshold LIMIT :batch',
            [
                'threshold' => $now,
                'batch'     => self::CLEANUP_BATCH_SIZE,
            ],
        );
    }

    /**
     * Build a state snapshot from a live bucket's counters.
     */
    private function stateFrom(int $attempts, int $resetAt): RateLimitState
    {
        $remaining = $this->maxAttempts - $attempts;

        return new RateLimitState(
            count: $attempts,
            remaining: $remaining > 0 ? $remaining : 0,
            resetAt: $resetAt,
            limited: $attempts >= $this->maxAttempts,
            limit: $this->maxAttempts,
        );
    }

    /**
     * The empty (unlimited, full-remaining) state for an absent/expired window.
     */
    private function emptyState(): RateLimitState
    {
        return new RateLimitState(
            count: 0,
            remaining: $this->maxAttempts,
            resetAt: 0,
            limited: false,
            limit: $this->maxAttempts,
        );
    }
}
