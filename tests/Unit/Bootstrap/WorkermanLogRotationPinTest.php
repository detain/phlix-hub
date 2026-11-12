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
}
