<?php

/**
 * Phlix hub component: Env.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Env;

/**
 * The env-read idiom for config defaults, in one place.
 *
 * Hub port of the server's canonical class (`phlix-server`
 * src/Common/Env/EnvConfig.php) — the estate reads env knobs through THIS
 * shape so a reader behaves identically on both hosts.
 *
 * `getenv('X') ?: $default` is the estate's oldest config bug: PHP's `?:`
 * treats the STRING `"0"` as falsy, so an operator who explicitly sets
 * `X=0` silently gets the DEFAULT instead (canonical statement of the trap:
 * the note at the head of `config/database.php`'s pool block; the hub's
 * worst instance was `HUB_SIGNUPS_ENABLED=0` yielding 'true' — a security
 * fail-open, closed by this port, HUB-2.1; server-side origin M-SERVER-34).
 * This helper keeps the two conventions the config files actually rely on,
 * minus the trap:
 *
 * - unset, or set-but-empty (`""`), means "not configured" → the default;
 * - `"0"` is an EXPLICIT value and survives.
 *
 * The helpers never throw and never warn: a config read cannot take the
 * boot path down. Callers own any further validation (bounds, enums) —
 * including the pools' own ceilings (PooledMySQLConnection clamps its
 * `maxSize` to ≥ 1, so a forwarded `DB_POOL_SIZE=0` can never arm a
 * zero-connection pool).
 *
 * Config files load this class with a plain `require_once` (not the
 * composer autoloader): the estate doctrine is that a config file must
 * stay loadable without class autoload — tests/Unit/Config/DatabaseConfigTest
 * asserts it with `env -i` child processes. require_once dedupes against
 * the PSR-4 loader in the long-running paths.
 *
 * The server original also carries a memoized CPU-count probe
 * (`availableCpuCount()`); the hub has no consumer for it, so it is
 * deliberately NOT ported — port it verbatim from the server original if a
 * consumer ever appears.
 *
 * @package Phlix\Hub\Common\Env
 * @since HUB-2.1
 */
final class EnvConfig
{
    /**
     * Read an integer knob: unset/empty → $default, otherwise the value
     * cast the way `(int) getenv(...)` casts it (`"12x"` → 12 — the old
     * `(int) (getenv(...) ?: default)` sites' behavior, preserved).
     *
     * Every hub site migrated here was that integer-cast shape, so the
     * malformed-value posture is unchanged across the board; the only
     * semantic moves are the trap's: `"0"` now survives, and set-but-empty
     * now means "not configured" (default) exactly as `?:` intended for the
     * empty case.
     */
    public static function integer(string $name, int $default): int
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? $default : (int) $raw;
    }

    /**
     * Read a string knob: unset/empty → $default, otherwise the raw value —
     * including `"0"`, which `?:` used to swallow.
     */
    public static function text(string $name, string $default): string
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? $default : $raw;
    }

    /**
     * Read an optional string knob whose "not configured" marker is `null`
     * (secrets/tokens/cert paths a later gate validates): unset/empty →
     * null; `"0"` survives verbatim for the gate to judge.
     */
    public static function nullableText(string $name): ?string
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? null : $raw;
    }
}
