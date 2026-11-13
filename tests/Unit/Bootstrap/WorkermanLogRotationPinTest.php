<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function file_get_contents;
use function preg_match_all;
use function str_contains;
use function substr_count;

/**
 * Guards the workerman.log logrotate drop-in in scripts/install.sh.
 *
 * The hub writes six log files: the five Monolog channels rotate themselves
 * (30 files each, config/logger.php), but Workerman's OWN log — the one
 * start.php pins via Worker::$logFile into <install>/.logs/workerman.log —
 * only ever appends. scripts/install.sh now ships a logrotate drop-in for it,
 * written on fresh install, rewritten on --update, and removed on uninstall,
 * alongside the sysctl/PAM drop-ins it already owns.
 *
 * This file is deliberately installed.sh TEXT only: install.sh is under
 * ongoing ops maintenance, and the exact regression this pin kills is a
 * future wholesale rewrite of that script silently dropping the rotation
 * block (or dropping its uninstall half, orphaning a root-owned policy file).
 * copytruncate is asserted by name because it is load-bearing, not cosmetic:
 * the daemon holds the log fd open across rotations, so any rename-based
 * policy would freeze the live file onto a rotated-away inode until restart.
 */
final class WorkermanLogRotationPinTest extends TestCase
{
    private const INSTALL_SH = __DIR__ . '/../../../scripts/install.sh';

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $source = file_get_contents(self::INSTALL_SH);
        self::assertIsString($source, 'scripts/install.sh must be readable by the pin');
        $this->script = $source;
    }

    public function testTheInstallerOwnsALogrotateDropInPath(): void
    {
        self::assertMatchesRegularExpression(
            '/^PHLIX_LOGROTATE_FILE="\/etc\/logrotate\.d\/phlix-hub-workerman"$/m',
            $this->script,
            'install.sh must own a dedicated logrotate drop-in file name'
            . ' (its own name — co-installed services must not clobber each other)'
        );
    }

    public function testTheDropInRotatesTheWorkermanLogWithCopytruncate(): void
    {
        self::assertTrue(
            str_contains($this->script, 'phlix_apply_workerman_logrotate() {'),
            'the rotation policy must live in a dedicated, named installer function'
        );
        self::assertTrue(
            str_contains($this->script, '${INSTALL_PATH}/.logs/*.log {'),
            'the policy must glob every .logs/*.log (workerman.log + dated Monolog files) so worst-case disk is bounded'
        );
        self::assertTrue(
            str_contains($this->script, '    copytruncate'),
            'copytruncate is load-bearing: the daemon inherits the open log fd, so a'
            . ' rename+create rotation would leave it appending to the rotated inode'
        );
        self::assertTrue(
            str_contains($this->script, '    maxsize 64M'),
            'the policy must bound worst-case growth between runs (maxsize, not size)'
        );
    }

    public function testTheDropInIsAppliedOnFreshInstallAndUpdate(): void
    {
        // One top-level call (fresh-install leg) + one indented call (inside
        // do_update) — exactly two invocations, matching how the sysctl/PAM
        // tuning reaches both paths.
        self::assertSame(
            1,
            preg_match_all('/^phlix_apply_workerman_logrotate$/m', $this->script),
            'the fresh-install leg must apply the logrotate drop-in at top level'
        );
        self::assertSame(
            1,
            preg_match_all('/^[ \t]+phlix_apply_workerman_logrotate$/m', $this->script),
            'do_update must re-apply the drop-in so pre-existing installs pick it up'
        );
    }

    public function testUninstallRemovesTheDropIn(): void
    {
        self::assertTrue(
            str_contains(
                $this->script,
                'for f in "$PHLIX_SYSCTL_FILE" "$PHLIX_LIMITS_FILE" "$PHLIX_LOGROTATE_FILE"; do'
            ),
            'uninstall must remove the logrotate drop-in alongside the other two'
            . ' owned files — leaving it behind orphaned a root-owned policy forever'
        );
        self::assertGreaterThanOrEqual(
            4,
            substr_count($this->script, '$PHLIX_LOGROTATE_FILE'),
            'the const must be referenced at its write sites (definition, mkdir/cat/chmod)'
        );
    }
    /**
     * HUB-S21: rotated copies (*.log.N[.gz]) of dated Monolog files are
     * orphaned once Monolog deletes the base file, so the drop-in carries a
     * lastaction sweep. Pinned in text AND exercised for real: the very find
     * command the stanza ships is run against a scratch .logs/ dir.
     */
    public function testDropInSweepsOrphanedRotatedCopies(): void
    {
        self::assertTrue(
            str_contains($this->script, '    sharedscripts'),
            'lastaction needs sharedscripts (one sweep per run, not per file)'
        );
        self::assertTrue(str_contains($this->script, '    lastaction'));
        $find = '/usr/bin/find ${INSTALL_PATH}/.logs -maxdepth 1 -type f '
            . "-name '*.log.[0-9]*' -mtime +60 -delete";
        self::assertTrue(
            str_contains($this->script, '        ' . $find . "\n"),
            'the sweep must be the pinned find: rotated copies only, depth 1, older than 60 days'
        );
        self::assertTrue(str_contains($this->script, '    endscript'));

        $dir = sys_get_temp_dir() . '/phlix-hub-s21-' . uniqid('', true);
        mkdir($dir, 0700, true);
        try {
            $old = time() - 70 * 86400;
            $young = time() - 10 * 86400;
            $files = [
                'app-2026-01-01.log.3.gz' => $old,
                'app-2026-01-01.log.1' => $old,
                'workerman.log.2.gz' => $young,
                // Live files are NEVER swept, however old (not *.log.N).
                'app-2026-01-02.log' => $old,
            ];
            foreach ($files as $name => $mtime) {
                touch($dir . '/' . $name, $mtime);
            }
            $out = [];
            exec(
                'find ' . escapeshellarg($dir) . " -maxdepth 1 -type f -name '*.log.[0-9]*' -mtime +60 -delete 2>&1",
                $out,
                $code,
            );
            self::assertSame(0, $code, implode("\n", $out));
            $left = array_map('basename', glob($dir . '/*') ?: []);
            sort($left);
            self::assertSame(['app-2026-01-02.log', 'workerman.log.2.gz'], $left);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /**
     * HUB-S17 behaviour of the connection-ceiling drop-in helpers, run for
     * real in bash against scratch dirs with a stubbed systemctl: layout
     * discovery (RHEL/MariaDB), no-layout warning, idempotence (no restart on
     * an unchanged file) and uninstall removal.
     */
    public function testMysqlCeilingDropInLayoutIdempotenceAndRemoval(): void
    {
        $start = strpos($this->script, 'PHLIX_MYSQL_CEILING_FILE_NAME=');
        $end = strpos($this->script, '# Parse the User= line from a systemd unit.');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $snippet = substr($this->script, $start, $end - $start);

        $root = sys_get_temp_dir() . '/phlix-hub-s17-' . uniqid('', true);
        mkdir($root . '/bin', 0700, true);
        mkdir($root . '/etc/my.cnf.d', 0700, true); // RHEL layout ONLY: no Debian dirs exist
        $restarts = $root . '/restarts';
        file_put_contents(
            $root . '/bin/systemctl',
            "#!/bin/sh\necho \"$@\" >> " . escapeshellarg($restarts) . "\nexit 0\n"
        );
        chmod($root . '/bin/systemctl', 0755);
        $dirs = $root . '/etc/mysql/mysql.conf.d ' . $root . '/etc/mysql/mariadb.conf.d ' . $root . '/etc/my.cnf.d';
        file_put_contents(
            $root . '/harness.sh',
            "log(){ :; }\ninfo(){ echo \"info: \$*\"; }\nwarn(){ echo \"warn: \$*\"; }\n"
            . $snippet
            . 'PHLIX_MYSQL_CONF_DIRS="' . $dirs . "\"\n"
            . "\$1\n"
        );

        $run = static function (string $fn) use ($root): string {
            return (string) shell_exec(
                'PATH=' . escapeshellarg($root . '/bin') . ':$PATH bash '
                . escapeshellarg($root . '/harness.sh') . ' ' . $fn . ' 2>&1'
            );
        };
        $drop = $root . '/etc/my.cnf.d/zz-phlix-hub.cnf';
        $restartCount = static fn (): int => substr_count((string) file_get_contents($restarts), 'restart');

        try {
            $run('phlix_apply_mysql_ceiling');
            self::assertFileExists($drop, 'RHEL/MariaDB /etc/my.cnf.d layout must get the drop-in');
            self::assertSame("[mysqld]\nmax_connections = 300\n", file_get_contents($drop));
            self::assertSame(1, $restartCount(), 'first write restarts once');

            $out = $run('phlix_apply_mysql_ceiling');
            self::assertStringContainsString('already current', $out);
            self::assertSame(1, $restartCount(), 'an unchanged drop-in must NOT restart the server again');

            $run('phlix_remove_mysql_ceiling');
            self::assertFileDoesNotExist($drop, 'uninstall must remove the drop-in');

            rmdir($root . '/etc/my.cnf.d');
            $out = $run('phlix_apply_mysql_ceiling');
            self::assertStringContainsString(
                'warn: No MySQL/MariaDB config directory found',
                $out,
                'an unknown layout must warn, not stay silent'
            );
        } finally {
            foreach ([$drop, $restarts, $root . '/harness.sh', $root . '/bin/systemctl'] as $f) {
                @unlink($f);
            }
            @rmdir($root . '/bin');
            @rmdir($root . '/etc/my.cnf.d');
            @rmdir($root . '/etc');
            @rmdir($root);
        }
    }
}
