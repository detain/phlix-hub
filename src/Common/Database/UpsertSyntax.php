<?php

/**
 * Phlix hub component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Database;

/**
 * Portable `INSERT ... ON DUPLICATE KEY UPDATE` across MySQL 8.0+ and MariaDB 10.6+.
 *
 * MySQL 8.0.19+ supports the row alias (`VALUES (...) AS new_row ... new_row.col`)
 * and DEPRECATES the `VALUES(col)` function (8.0.20+). MariaDB has NO row alias —
 * only `VALUES(col)`. Hub SQL is therefore authored once in the alias form
 * (`AS new_row` before `ON DUPLICATE KEY UPDATE`, `new_row.col` for the
 * proposed value) and passed through {@see adapt()}, which leaves it untouched on
 * MySQL >= 8.0.19 and rewrites it to the `VALUES(col)` form on MariaDB (and on
 * pre-8.0.19 MySQL). The server flavor is detected at connect time from
 * `SELECT VERSION()` by {@see PhlixMySQLConnection}.
 *
 * Only the exact `AS new_row` alias and `new_row.<ident>` tokens are rewritten.
 *
 * @since HUB-S14
 */
final class UpsertSyntax
{
    /** Row alias every hub upsert uses. */
    public const ALIAS = 'new_row';

    /** @var bool True when the connected server supports the row alias. */
    private static bool $aliasSupported = true;

    /**
     * Decide the flavor from a `SELECT VERSION()` string.
     *
     * @param string $version e.g. "8.0.46", "10.6.18-MariaDB-0ubuntu0.22.04.1", "5.5.5-10.11.6-MariaDB".
     */
    public static function detect(string $version): void
    {
        self::$aliasSupported = self::supportsAlias($version);
    }

    /**
     * Pure predicate: does this server version string support the row alias?
     */
    public static function supportsAlias(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return false;
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return true; // unknown format: assume modern MySQL (the tested engine)
        }

        return ((int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3]) >= 80019;
    }

    /** Whether the current flavor uses the row alias. */
    public static function usesAlias(): bool
    {
        return self::$aliasSupported;
    }

    /** Reset to the default (MySQL row-alias) flavor — for tests. */
    public static function reset(): void
    {
        self::$aliasSupported = true;
    }

    /**
     * Adapt alias-form upsert SQL to the connected server's flavor.
     *
     * @param string $sql Upsert authored with `AS new_row` and `new_row.col`.
     */
    public static function adapt(string $sql): string
    {
        return self::rewrite($sql, self::$aliasSupported);
    }

    /**
     * Pure rewrite (testable without global state).
     */
    public static function rewrite(string $sql, bool $aliasSupported): string
    {
        if ($aliasSupported) {
            return $sql;
        }
        $sql = (string) preg_replace('/\s+AS\s+' . self::ALIAS . '\b/i', '', $sql);

        return (string) preg_replace('/\b' . self::ALIAS . '\.(\w+)/', 'VALUES($1)', $sql);
    }
}
