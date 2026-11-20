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
 * Facade over the single estate kernel (`Phlix\Shared\Support\EnvConfig`,
 * phlix-shared src/Support/): the pure readers delegate verbatim, so a hub
 * knob and a server knob cannot drift apart by construction — the historic
 * "port of the server's canonical class" duplication ends here.
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
 * The helpers never throw: a config read cannot take the boot path down.
 * Callers own any further validation (bounds, enums) — including the pools'
 * own ceilings (PooledMySQLConnection clamps its `maxSize` to ≥ 1, so a
 * forwarded `DB_POOL_SIZE=0` can never arm a zero-connection pool). The
 * numeric/text readers are also silent; the bool family adds ONE soft
 * exception — a value that is neither a true word nor a false word is
 * answered by the default AND logged as a one-line `error_log` warning
 * (the server's E-04 law): junk in a knob is operator-visible, but a
 * warning is a recording, not a throw, so the boot-path contract holds
 * unchanged.
 *
 * Config files load this class with a plain `require_once` (not the
 * composer autoloader): the estate doctrine is that a config file must
 * stay loadable without class autoload — tests/Unit/Config/DatabaseConfigTest
 * asserts it with `env -i` child processes. require_once dedupes against
 * the PSR-4 loader in the long-running paths. The kernel is pulled in by
 * the same guarded path-load above, so the doctrine survives delegation.
 *
 * The kernel also carries a memoized CPU-count probe and the opt-out verb
 * (`boolOptOut()`); the hub has no consumer, so the facade deliberately
 * does NOT re-expose them — a future hub caller goes to the kernel
 * directly. Re-rolling an opt-out grammar here is the drift this
 * convergence exists to prevent (S2-ADOPT row 2.2).
 *
 * The bool family keeps its verdict arms IN THIS FILE, on purpose: the
 * hub's E-04 law pairs the shared grammar with a one-line `error_log`
 * warning on junk values, and the kernel's zero-I/O charter cannot carry
 * the warning. Dropping the warning would weaken a hub-legit arm, which
 * the convergence law forbids; verdict equality with the kernel is
 * differential-pinned by tests/Unit/Common/Env/EnvConfigTest instead.
 *
 * @package Phlix\Hub\Common\Env
 * @since HUB-2.1
 */
final class EnvConfig
{
    /**
     * Pulls the shared kernel in by PATH when the composer autoloader has
     * not provided it — v0.59 bank row 3 facade duty. Config files still
     * load this class with a plain `require_once` (no autoload; the
     * `env -i` child doctrine of tests/Unit/Config/DatabaseConfigTest), so
     * the kernel arrives the same way, deduped by require_once against the
     * PSR-4 loader on the long-running paths. Kept INSIDE the class (not
     * file top level) so this file declares symbols and carries its
     * side-effect only on demand, per PSR-1.
     */
    private static function ensureKernel(): void
    {
        if (!class_exists(\Phlix\Shared\Support\EnvConfig::class, false)) {
            require_once __DIR__ . '/../../../vendor/detain/phlix-shared/src/Support/EnvConfig.php';
        }
    }

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
        self::ensureKernel();

        return \Phlix\Shared\Support\EnvConfig::integer($name, $default);
    }

    /**
     * Read a string knob: unset/empty → $default, otherwise the raw value —
     * including `"0"`, which `?:` used to swallow.
     */
    public static function text(string $name, string $default): string
    {
        self::ensureKernel();

        return \Phlix\Shared\Support\EnvConfig::text($name, $default);
    }

    /**
     * Read an optional string knob whose "not configured" marker is `null`
     * (secrets/tokens/cert paths a later gate validates): unset/empty →
     * null; `"0"` survives verbatim for the gate to judge.
     */
    public static function nullableText(string $name): ?string
    {
        self::ensureKernel();

        return \Phlix\Shared\Support\EnvConfig::nullableText($name);
    }

    /**
     * The words the bool grammar reads as TRUE (case-folded, trimmed).
     *
     * @var list<string>
     */
    private const TRUE_WORDS = ['1', 'true', 'yes', 'on'];

    /**
     * The words the bool grammar reads as FALSE (case-folded, trimmed).
     *
     * @var list<string>
     */
    private const FALSE_WORDS = ['0', 'false', 'no', 'off'];

    /**
     * Read a boolean knob — the estate's single bool law, ported verbatim
     * from the server's E-04 reader so a knob behaves identically on both
     * hosts.
     *
     * Grammar (identical for every bool reader here, see
     * {@see boolValue()}): unset/empty → $default; true words → true; false
     * words → false; anything else → $default plus a one-line `error_log`
     * warning. The trim is load-bearing: the hub's former hand-rolled copies
     * disagreed on it — `ContainerFactory::shouldCompile()` did NOT trim
     * (`PHLIX_HUB_CONTAINER_COMPILE=" 1 "` answered false) while the
     * `filter_var` arms did — so the same spelling got two answers. Under one
     * law it answers TRUE everywhere.
     */
    public static function bool(string $name, bool $default = false): bool
    {
        return self::boolValue(getenv($name), $default, $name);
    }

    /**
     * The bool grammar as a pure function over an ALREADY-READ raw value.
     *
     * Public because a caller that receives the raw env value from its own
     * caller still owes the same law — `Application::dbCeilingDemand()` is
     * exactly that seam (env values are passed in so the boot check is
     * unit-testable, HUB-S17; its pool-enabled arm must agree with the
     * config's `pool_enabled`, which reads the same knob through
     * {@see bool()}).
     *
     * @param string|false|null $raw     Raw env value; `getenv()`'s false and null both read as unset.
     * @param bool              $default Answer for unset/empty/junk.
     * @param string            $name    Knob name, used only to make the junk warning actionable.
     */
    public static function boolValue(string|false|null $raw, bool $default, string $name): bool
    {
        if (!is_string($raw)) {
            return $default;
        }

        $normalized = strtolower(trim($raw));

        if ($normalized === '') {
            return $default;
        }

        if (in_array($normalized, self::TRUE_WORDS, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSE_WORDS, true)) {
            return false;
        }

        error_log(sprintf(
            'EnvConfig: %s="%s" is neither a true word (%s) nor a false word (%s); using default %s',
            $name,
            str_replace(["\r", "\n"], ' ', $normalized),
            implode('/', self::TRUE_WORDS),
            implode('/', self::FALSE_WORDS),
            $default ? 'true' : 'false',
        ));

        return $default;
    }
}
