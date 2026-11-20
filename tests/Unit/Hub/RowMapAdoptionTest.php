<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Shared\Database\RowMap;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * v0.59 bank item 6 (FR-SSOT): the hub's hand-rolled `is_string($row[...])`
 * narrowings moved onto the shared RowMap accessors. This file owns the
 * BEHAVIOR-FREE proof in two directions:
 *
 * 1. a table-driven differential — for every value a MySQL driver or a
 *    sloppy caller can deliver (missing key, NULL, bool, int, numeric
 *    string, decimal string, empty string, text, float, array, object),
 *    each accessor returns EXACTLY what the retired hub closure returned.
 *    The legacy closures are re-quoted verbatim from the replaced lines,
 *    so this test convicts any future accessor drift against the shape
 *    the hub actually adopted;
 * 2. an extinction pin — the migrated files must never regrow a direct
 *    index narrowing of the converted families. The remaining
 *    `is_string($row[...)` hits in the tree are ONLY the adjudicated
 *    KEEP-with-evidence guards (control-flow throws in ServerInfoHandler
 *    rowToDto, the MigrationRunner applied-rows validation); those are
 *    not row-DTO coercions and stay on purpose.
 */
final class RowMapAdoptionTest extends TestCase
{
    /**
     * Legacy closures, verbatim from the replaced hub call sites.
     *
     * @return array<string, array{0: callable(array<string,mixed>): mixed, 1: callable(array<string,mixed>): mixed}>
     */
    public static function familyPairs(): array
    {
        return [
            'str (default "")' => [
                /** replaced shape: is_string($row['c'] ?? null) ? $row['c'] : '' */
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = is_string($row['c'] ?? null) ? $row['c'] : '';

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::str($r, 'c', ''),
            ],
            'str (default "offline")' => [
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = is_string($row['c'] ?? null) ? $row['c'] : 'offline';

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::str($r, 'c', 'offline'),
            ],
            'nullableStr' => [
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = is_string($row['c'] ?? null) ? (string) $row['c'] : null;

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::nullableStr($r, 'c'),
            ],
            'int (strict, default 0)' => [
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = is_int($row['c'] ?? null) ? $row['c'] : 0;

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::int($r, 'c', 0),
            ],
            'nullableIntFromNumeric (?? form)' => [
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = is_numeric($row['c'] ?? null) ? (int) $row['c'] : null;

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::nullableIntFromNumeric($r, 'c'),
            ],
            'nullableIntFromNumeric (isset form)' => [
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = isset($row['c']) && is_numeric($row['c']) ? (int) $row['c'] : null;

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::nullableIntFromNumeric($r, 'c'),
            ],
            'nullableNonEmptyStr (joined secretHash fold)' => [
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = is_string($row['c'] ?? null) ? (string) $row['c'] : null;
                    if ($value === '') {
                        $value = null;
                    }

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::nullableNonEmptyStr($r, 'c'),
            ],
            'intFromNumeric (default 0)' => [
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = is_numeric($row['c'] ?? null) ? (int) $row['c'] : 0;

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::intFromNumeric($r, 'c', 0),
            ],
            'intFromNumeric (default 1 - maxUses)' => [
                static fn (array $r): mixed => (static function (array $row): mixed {
                    $value = is_numeric($row['c'] ?? null) ? (int) $row['c'] : 1;

                    return $value;
                })($r),
                static fn (array $r): mixed => RowMap::intFromNumeric($r, 'c', 1),
            ],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function valueMatrix(): array
    {
        return [
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'int-zero' => [0],
            'int-seven' => [7],
            'string-empty' => [''],
            'string-zero' => ['0'],
            'string-numeric' => ['7'],
            'string-decimal' => ['7.9'],
            'string-text' => ['text'],
            'string-space' => [' '],
            'float' => [7.0],
            'array' => [[]],
            'array-nonempty' => [['x']],
            'object' => [new stdClass()],
        ];
    }

    /**
     * Every family × every value (plus the fully-missing key): the accessor
     * answer must be identical to the retired closure answer.
     */
    public function testEachAccessorReproducesItsRetiredLegacyClosureByteExactly(): void
    {
        $cases = 0;
        foreach (self::familyPairs() as $label => [$legacy, $accessor]) {
            foreach (self::valueMatrix() as $valueLabel => [$value]) {
                $row = ['c' => $value];
                self::assertSame(
                    $legacy($row),
                    $accessor($row),
                    "family '$label' on value '$valueLabel'"
                );
                $cases++;

                $missing = [];
                self::assertSame(
                    $legacy($missing),
                    $accessor($missing),
                    "family '$label' on MISSING key"
                );
                $cases++;
            }
        }

        // 9 families x 15 matrix values x (value-case + missing-key-case) —
        // the floor catches a silently-shrunken matrix, mirroring the
        // corpus-floor discipline.
        self::assertSame(270, $cases, 'the differential matrix must run in full');
    }

    /**
     * Extinction pin: the migrated files never regrow a direct-index
     * narrowing of the converted families (KEEP guards live only in the
     * three files named below and nowhere else).
     */
    public function testMigratedFilesCarryNoDirectRowNarrowings(): void
    {
        $migrated = [
            'src/Http/Controllers/LibraryController.php',
            'src/Hub/ServerInfoHandler.php',
            'src/Hub/HubSettingsRepository.php',
            'src/Hub/LibrarySharingHandler.php',
            'src/Hub/InviteLinkHandler.php',
            'src/Hub/InviteLink.php',
            'src/Hub/LibraryShare.php',
            'src/Hub/RelaySessionManager.php',
            'src/Hub/ClaimRequestHandler.php',
            'src/Hub/AuditLogRepository.php',
            'src/OAuth/OAuthClientRegistry.php',
        ];

        $hubRoot = dirname(__DIR__, 3);
        foreach ($migrated as $relative) {
            $code = file_get_contents($hubRoot . '/' . $relative);
            self::assertIsString($code, $relative . ' must exist to be policed');

            // stripComments-free: these are code-shape greps over the exact
            // retired idiom; a comment reciting the idiom would be prose, and
            // a smuggled throw past a fake marker is policed by the (upgraded)
            // TxMutex extinction twin, not here.
            foreach (['is_string($row[', 'is_int($row[', 'is_numeric($row['] as $forbidden) {
                $hits = substr_count($code, $forbidden);
                // ServerInfoHandler/HubSettingsRepository keep their
                // adjudicated control-flow guards (throw-continue shapes);
                // assert the EXACT kept counts instead of blanket zero so any
                // drift — growth of hand-rolling or silent removal of a
                // load-bearing guard — reddens here.
                $allowed = match (true) {
                    $relative === 'src/Hub/ServerInfoHandler.php' && $forbidden === 'is_string($row[' => 2,
                    default => 0,
                };
                self::assertSame(
                    $allowed,
                    $hits,
                    "$relative: $forbidden — 8 migrated coercions are RowMap now; "
                    . 'ServerInfoHandler keeps its two id/user_id fail-fast guards (adjudicated KEEP)'
                );
            }
        }
    }
}
