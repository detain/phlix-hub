<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Closure;
use Phlix\Hub\Common\Database\ConnectionPool;
use Throwable;

/**
 * Canonical factories for LIVE hub-settings reads at runtime call sites.
 *
 * Every Phase-6 toggle needs the same shape: consult the EFFECTIVE value
 * ({@see HubSettingsRepository::getEffective()}) at the moment of use — never
 * a value baked in at container build — while degrading safely when no store
 * is reachable. The original precedent is hand-rolled twice in
 * `AuthServicesProvider` (`makeSignupsDisabledResolver()` /
 * `makeTtlResolver()`); these factories generalise that idiom so each new
 * consumer wires one closure instead of re-implementing the pool guard.
 *
 * ## Contract of every returned closure
 *
 * - The memoised {@see HubSettingsRepository} is shared configuration access,
 *   never per-request state, so holding it in the closure is
 *   resident-memory-safe (resident-process law: no cross-request caches).
 * - An uninitialised {@see ConnectionPool} (unit tests, CLI) or ANY
 *   repository/DB failure returns the caller's `$bootDefault` — the value the
 *   consumer would have used before this setting existed. A settings outage
 *   must never brick a runtime path beyond what the environment dictates.
 * - Numeric results are CLAMPED to [`$min`, `$max`] on every read. The
 *   controller rejects out-of-bounds writes, but the clamp makes a
 *   hand-edited or pre-existing bad row harmless at the point of use
 *   (parse-don't-validate at the boundary; the consumer is handed a trusted
 *   value).
 *
 * `getEffective()` re-SELECTs per call by design: live reads are the point.
 * Call sites choose low-frequency moments (token mint, request handling,
 * timer ticks) — the pool's per-query cost is a unique-index lookup.
 *
 * @package Phlix\Hub\Hub
 */
final class HubSettingsResolvers
{
    /**
     * Resolve a bool setting live, falling back to `$bootDefault` when no
     * store is reachable or the row holds a non-bool.
     *
     * @param string $key         Dotted allow-listed key.
     * @param bool   $bootDefault Wire-time fallback (env/config-derived).
     *
     * @return Closure(): bool
     */
    public static function bool(string $key, bool $bootDefault): Closure
    {
        $repository = null;

        return static function () use ($key, $bootDefault, &$repository): bool {
            /** @var mixed $value */
            $value = self::live($key, $repository);

            return is_bool($value) ? $value : $bootDefault;
        };
    }

    /**
     * Resolve an int setting live, clamped to bounds, falling back to
     * `$bootDefault` (also clamped) when no store is reachable.
     *
     * @param string $key         Dotted allow-listed key.
     * @param int    $bootDefault Wire-time fallback.
     * @param int    $min         Inclusive lower bound enforced on every read.
     * @param int    $max         Inclusive upper bound enforced on every read.
     *
     * @return Closure(): int
     */
    public static function int(string $key, int $bootDefault, int $min, int $max): Closure
    {
        $repository = null;

        return static function () use ($key, $bootDefault, $min, $max, &$repository): int {
            $value = self::live($key, $repository);

            if ($value === null) {
                return self::clampInt($bootDefault, $min, $max);
            }

            if (!is_numeric($value)) {
                return self::clampInt($bootDefault, $min, $max);
            }

            return self::clampInt((int) $value, $min, $max);
        };
    }

    /**
     * Resolve a float setting live, clamped to bounds, falling back to
     * `$bootDefault` when no store is reachable.
     *
     * @param string $key         Dotted allow-listed key.
     * @param float  $bootDefault Wire-time fallback.
     * @param float  $min         Inclusive lower bound enforced on every read.
     * @param float  $max         Inclusive upper bound enforced on every read.
     *
     * @return Closure(): float
     */
    public static function float(string $key, float $bootDefault, float $min, float $max): Closure
    {
        $repository = null;

        return static function () use ($key, $bootDefault, $min, $max, &$repository): float {
            $value = self::live($key, $repository);

            if (!is_numeric($value)) {
                return min(max($bootDefault, $min), $max);
            }

            return min(max((float) $value, $min), $max);
        };
    }

    /**
     * Resolve a non-empty string setting live, falling back to `$bootDefault`
     * when the effective value is absent or blank.
     *
     * @param string $key         Dotted allow-listed key.
     * @param string $bootDefault Wire-time fallback.
     *
     * @return Closure(): string
     */
    public static function string(string $key, string $bootDefault): Closure
    {
        $repository = null;

        return static function () use ($key, $bootDefault, &$repository): string {
            $value = self::live($key, $repository);

            if (!is_string($value)) {
                return $bootDefault;
            }

            $trimmed = trim($value);

            return $trimmed === '' ? $bootDefault : $trimmed;
        };
    }

    /**
     * Resolve a json (array) setting live, falling back to `$bootDefault`
     * when the effective value is absent or not an array.
     *
     * @param string                       $key         Dotted allow-listed key.
     * @param array<array-key, mixed>      $bootDefault Wire-time fallback.
     *
     * @return Closure(): array<array-key, mixed>
     */
    public static function array(string $key, array $bootDefault): Closure
    {
        $repository = null;

        return static function () use ($key, $bootDefault, &$repository): array {
            /** @var mixed $value */
            $value = self::live($key, $repository);

            return is_array($value) ? $value : $bootDefault;
        };
    }

    /**
     * Read the effective value, treating every unreachable-store path as
     * "no answer" (null). Mutates the caller's memo slot on first success.
     *
     * @param HubSettingsRepository|null $repository Memo slot (by reference).
     *
     * @return mixed Effective value or null when no live answer exists.
     */
    private static function live(string $key, ?HubSettingsRepository &$repository): mixed
    {
        try {
            if (!$repository instanceof HubSettingsRepository) {
                // Not booted (unit tests, CLI smoke commands): there is no
                // store to consult. Checked explicitly rather than caught,
                // because ConnectionPool::getConnection() on an uninitialised
                // pool emits warnings instead of throwing.
                if (ConnectionPool::getInstance() === null) {
                    return null;
                }
                $repository = new HubSettingsRepository(ConnectionPool::getConnection('mysql'));
            }

            return $repository->getEffective($key);
        } catch (Throwable) {
            // Settings outage: the caller's boot fallback keeps behaviour
            // exactly at the pre-setting status quo.
            return null;
        }
    }

    private static function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
