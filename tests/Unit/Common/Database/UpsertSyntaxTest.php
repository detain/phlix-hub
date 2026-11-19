<?php

/**
 * Phlix hub component: tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Shared\Database\UpsertSyntax;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Workerman\MySQL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * HUB-S14: the upsert SQL is portable across MySQL 8.0.19+ (row alias) and
 * MariaDB / older MySQL (VALUES()).
 *
 * v0.57 leg M-29 retirement pin: the hub-local twin `Phlix\Hub\Common\Database\UpsertSyntax`
 * was retired into its shared home, and this suite exercises the SHARED kernel. The
 * retired twin's bodies are replayed verbatim (`retiredTwinSupportsAlias`,
 * `retiredTwinRewrite`, `retiredTwinAdapt`) against the kernel over the whole
 * alias-grammar corpus (`testTheKernelAnswersByteIdenticallyToTheRetiredTwin`), so a
 * future kernel edit cannot move the hub's dialect silently; the final test is the
 * retire-tripwire: the hub class must NOT come back.
 */
#[Group('unit')]
final class UpsertSyntaxTest extends TestCase
{
    private const SQL = "INSERT INTO t (a, b) VALUES (:a, :b)\n   AS new_row\n   ON DUPLICATE KEY UPDATE "
        . 'a = new_row.a, '
        . 'b = t.b + new_row.b, c = GREATEST(t.c, new_row.c)';

    protected function tearDown(): void
    {
        UpsertSyntax::reset();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function versions(): array
    {
        return [
            'mysql 8.0.46'        => ['8.0.46', true],
            'mysql 8.0.19'        => ['8.0.19', true],
            'mysql 8.4.1'         => ['8.4.1-0ubuntu0.24.04.1', true],
            'mysql 8.0.18'        => ['8.0.18', false],
            'mysql 5.7'           => ['5.7.44', false],
            'mariadb 10.6'        => ['10.6.18-MariaDB-0ubuntu0.22.04.1', false],
            'mariadb rpl prefix'  => ['5.5.5-10.11.6-MariaDB', false],
            'mariadb 11'          => ['11.4.2-MariaDB', false],
            'unparseable'         => ['weird', true],
        ];
    }

    #[DataProvider('versions')]
    public function testFlavorDetection(string $version, bool $alias): void
    {
        self::assertSame($alias, UpsertSyntax::supportsAlias($version));
        UpsertSyntax::detect($version);
        self::assertSame($alias, UpsertSyntax::usesAlias());
    }

    public function testMysqlKeepsRowAlias(): void
    {
        UpsertSyntax::detect('8.0.46');
        self::assertSame(self::SQL, UpsertSyntax::adapt(self::SQL));
    }

    public function testMariaDbUsesValuesFunctionWithoutAlias(): void
    {
        UpsertSyntax::detect('10.6.18-MariaDB');
        $out = UpsertSyntax::adapt(self::SQL);
        self::assertStringNotContainsString('new_row', $out);
        self::assertStringNotContainsString('AS ', $out);
        self::assertStringContainsString('a = VALUES(a)', $out);
        self::assertStringContainsString('b = t.b + VALUES(b)', $out);
        self::assertStringContainsString('GREATEST(t.c, VALUES(c))', $out);
        self::assertStringContainsString(":b)\n   ON DUPLICATE KEY UPDATE", $out);
    }

    public function testRepositoryEmitsFlavorSpecificSql(): void
    {
        foreach (['8.0.46' => true, '10.11.6-MariaDB' => false] as $version => $alias) {
            UpsertSyntax::detect($version);
            $captured = '';
            $db = $this->createMock(Connection::class);
            $db->method('query')->willReturnCallback(static function ($sql) use (&$captured) {
                $captured = (string) $sql;

                return 1;
            });
            (new HubSettingsRepository($db))->set('k', 'v', 'string');
            if ($alias) {
                self::assertStringContainsString('AS new_row ON DUPLICATE KEY UPDATE', $captured);
                self::assertStringContainsString('setting_value = new_row.setting_value', $captured);
            } else {
                self::assertStringNotContainsString('new_row', $captured);
                self::assertStringContainsString('setting_value = VALUES(setting_value)', $captured);
            }
        }
    }

    // ── v0.57 leg M-29 retirement pins ──────────────────────────────────────

    /**
     * The kernel must answer byte-for-byte what the retired hub twin answered:
     * flavor decisions over the version grammar, rewrites over the whole
     * alias-token grammar (case asymmetry, tabs, backticks, decoy identifiers,
     * literals containing the alias, multi-row/nested-paren shapes, plain-VALUES
     * passthroughs) on BOTH flavors, and the ALIAS constant the twin inlined.
     */
    public function testTheKernelAnswersByteIdenticallyToTheRetiredTwin(): void
    {
        self::assertSame('new_row', UpsertSyntax::ALIAS, 'the kernel alias must equal the literal the twin inlined.');

        $versions = [
            '8.0.46', '8.0.19', '8.0.18', '8.4.1-0ubuntu0.24.04.1', '5.7.44', '9999.9.9', 'weird', '',
            '10.6.18-MariaDB-0ubuntu0.22.04.1', '5.5.5-10.11.6-MariaDB', '11.4.2-MariaDB',
            '10.6-mariadb-Trial', 'mariadb 11.8.1', '8.0.191',
        ];
        foreach ($versions as $version) {
            self::assertSame(
                self::retiredTwinSupportsAlias($version),
                UpsertSyntax::supportsAlias($version),
                "flavor decision moved for version: {$version}",
            );
        }

        $aliasForms = ['AS new_row', 'as new_row', 'AS  new_row', "AS\tnew_row", 'AS NEW_ROW', 'AS `new_row`'];
        $refSets = [
            'a = new_row.a',
            'a = NEW_ROW.a',
            'a = new_rowx.a',
            'a = `new_row`.`a`',
            'a = new_row_nothere.a',
            'b = t.b + new_row.b, c = GREATEST(t.c, new_row.c)',
            "x = 'AS new_row inside a literal', a = new_row.a",
        ];
        $fired = 0;
        foreach ($aliasForms as $aliasForm) {
            foreach ($refSets as $refSet) {
                $sql = "INSERT INTO t (a, b) VALUES (:a, :b)\n   {$aliasForm}\n   ON DUPLICATE KEY UPDATE {$refSet}";
                foreach ([true, false] as $aliasSupported) {
                    $rewritten = UpsertSyntax::rewrite($sql, $aliasSupported);
                    self::assertSame(
                        self::retiredTwinRewrite($sql, $aliasSupported),
                        $rewritten,
                        'rewrite delta: ' . $sql . ' flavor=' . var_export($aliasSupported, true),
                    );
                    if ($rewritten !== $sql) {
                        $fired++;
                    }
                }
            }
        }
        foreach ($refSets as $refSet) {
            $sql = "INSERT INTO t (a, b) VALUES (:a, :b) ON DUPLICATE KEY UPDATE {$refSet}";
            foreach ([true, false] as $aliasSupported) {
                self::assertSame(
                    self::retiredTwinRewrite($sql, $aliasSupported),
                    UpsertSyntax::rewrite($sql, $aliasSupported),
                    'no-alias-form rewrite delta: ' . $sql,
                );
            }
        }
        $multi = "INSERT INTO t (a, b) VALUES (1, (SELECT MAX(x) FROM u)), (2, 3)\n   AS new_row\n"
            . '   ON DUPLICATE KEY UPDATE a = new_row.a, b = new_row.b';
        foreach ([true, false] as $aliasSupported) {
            self::assertSame(
                self::retiredTwinRewrite($multi, $aliasSupported),
                UpsertSyntax::rewrite($multi, $aliasSupported),
                'multi-row nested-parens rewrite delta',
            );
        }

        self::assertGreaterThan(0, $fired, 'the rewrite corpus never fired — these pins would be vacuous.');
    }

    /**
     * The process-global flavor state machine (detect/adapt/reset) must behave
     * exactly as the retired twin's static did, operation for operation.
     */
    public function testTheKernelStateTracksTheRetiredTwinThroughOperations(): void
    {
        $ops = [
            ['detect', '8.0.46'],
            ['adapt', self::SQL],
            ['detect', '10.11.6-MariaDB'],
            ['adapt', self::SQL],
            ['reset', ''],
            ['adapt', self::SQL],
            ['detect', 'weird'],
            ['adapt', self::SQL],
        ];
        foreach ($ops as [$op, $arg]) {
            if ($op === 'detect') {
                UpsertSyntax::detect($arg);
                self::retiredTwinDetect($arg);
            } elseif ($op === 'reset') {
                UpsertSyntax::reset();
                self::retiredTwinReset();
            } else {
                self::assertSame(
                    self::retiredTwinAdapt($arg),
                    UpsertSyntax::adapt($arg),
                    'state-machine adapt delta after ' . $op . ': ' . $arg,
                );
            }
            self::assertSame(
                self::$twinAliasSupported,
                UpsertSyntax::usesAlias(),
                'flavor-state delta after ' . $op,
            );
        }
    }

    /**
     * Retire-tripwire (wave-A M-30 shape): the deleted hub-local class must not
     * come back, this suite must drive the shared kernel, and src/ must not
     * re-declare the name. The walker asserts its own coverage with a file floor.
     */
    public function testTheHubLocalTwinIsRetiredAndTheSharedKernelIsSoleImpl(): void
    {
        self::assertFalse(
            class_exists('Phlix\Hub\Common\Database\UpsertSyntax'),
            'the retired hub-local upsert dialect re-materialised; the shared kernel is its only home.',
        );
        self::assertSame(
            UpsertSyntax::class,
            \Phlix\Shared\Database\UpsertSyntax::class,
            'this suite must drive the shared kernel, not a hub-local alias.',
        );

        $declared = [];
        $visited = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::srcDir(), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if ($code === false) {
                continue;
            }
            $visited++;
            $pattern = '/\b(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+UpsertSyntax\b/';
            if (preg_match($pattern, $code) === 1) {
                $declared[] = $file->getPathname();
            }
        }

        self::assertGreaterThanOrEqual(
            200,
            $visited,
            'src/ walk saw too few files to be real (measured 238 at retirement).'
        );
        self::assertSame([], $declared, 'a second UpsertSyntax declaration appeared in src/.');
    }

    // ── the retired twin, replayed verbatim (golden bodies for the pins) ─────

    /** @var bool Mirror of the retired twin's process-global flavor static. */
    private static bool $twinAliasSupported = true;

    /** Verbatim body of the retired hub-local supportsAlias(). */
    private static function retiredTwinSupportsAlias(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return false;
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return true; // unknown format: assume modern MySQL (the tested engine)
        }

        return ((int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3]) >= 80019;
    }

    /** Verbatim body of the retired hub-local rewrite(); ALIAS inlined as it was stored. */
    private static function retiredTwinRewrite(string $sql, bool $aliasSupported): string
    {
        if ($aliasSupported) {
            return $sql;
        }
        $sql = (string) preg_replace('/\s+AS\s+' . 'new_row' . '\b/i', '', $sql);

        return (string) preg_replace('/\b' . 'new_row' . '\.(\w+)/', 'VALUES($1)', $sql);
    }

    /** Verbatim body of the retired hub-local detect(). */
    private static function retiredTwinDetect(string $version): void
    {
        self::$twinAliasSupported = self::retiredTwinSupportsAlias($version);
    }

    /** Verbatim body of the retired hub-local reset(). */
    private static function retiredTwinReset(): void
    {
        self::$twinAliasSupported = true;
    }

    /** Verbatim body of the retired hub-local adapt(). */
    private static function retiredTwinAdapt(string $sql): string
    {
        return self::retiredTwinRewrite($sql, self::$twinAliasSupported);
    }

    /** Repository src/ root, fail-loud when unreachable (SharedJsonDelegationTest idiom). */
    private static function srcDir(): string
    {
        $dir = realpath(__DIR__ . '/../../../../src');
        if ($dir === false) {
            throw new RuntimeException('src/ root not reachable from ' . __DIR__);
        }

        return $dir;
    }
}
