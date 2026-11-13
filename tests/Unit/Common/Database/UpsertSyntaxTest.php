<?php

/**
 * Phlix hub component: tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use Phlix\Hub\Common\Database\UpsertSyntax;
use Phlix\Hub\Hub\HubSettingsRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * HUB-S14: the upsert SQL is portable across MySQL 8.0.19+ (row alias) and
 * MariaDB / older MySQL (VALUES()).
 *
 * @group unit
 */
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

    /**
     * @dataProvider versions
     */
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
}
