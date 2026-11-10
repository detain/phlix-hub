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
use function strpos;

/**
 * Guards the HUB_WORKERMAN_LOG seam (SH-059, review hub__devops-code).
 *
 * config/server.php has advertised `workerman_log` (env HUB_WORKERMAN_LOG,
 * README row included) since long before this lane, and start.php pinned
 * `Worker::$logFile` to a hardcoded literal beside it — the key had ZERO
 * consumers, so setting the documented env var moved nothing while the docs
 * claimed otherwise. A documented-but-dead config seam is worse than an
 * undocumented one: operators set it believing the log moved.
 *
 * The cure wired start.php to include config/server.php BEFORE the pin and
 * honour the key (falling back to the same .logs/workerman.log default). This
 * static source-pin — the repo's tripwire idiom, cf. StopTimeoutBudgetTest —
 * keeps the seam from silently dying again: the daemon never boots under
 * PHPUnit, but the law is precisely about the shipped TEXT of the two files.
 *
 * @package Phlix\Hub\Tests\Unit\Bootstrap
 */
final class WorkermanLogSeamPinTest extends TestCase
{
    private const START_PHP = __DIR__ . '/../../../start.php';
    private const SERVER_PHP = __DIR__ . '/../../../config/server.php';

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "{$path} must be readable by the pin");

        return $source;
    }

    public function testConfigStillCarriesTheWorkermanLogKeyOnItsEnvSeam(): void
    {
        self::assertMatchesRegularExpression(
            '/\'workerman_log\'\s*=>\s*\$envStr\(\'HUB_WORKERMAN_LOG\',/',
            $this->source(self::SERVER_PHP),
            'config/server.php must keep the workerman_log key driven by HUB_WORKERMAN_LOG',
        );
    }

    public function testStartPhpPinsWorkerLogFileFromTheServerConfigNotALiteral(): void
    {
        $source = $this->source(self::START_PHP);

        // The pin must read the resolved config value, not a path literal.
        self::assertMatchesRegularExpression(
            '/\$workerLogFile\s*=\s*is_string\(\s*\$serverConfig\[\'workerman_log\'\]/',
            $source,
            'start.php must derive the worker log path from the server config array',
        );
        self::assertMatchesRegularExpression(
            '/^Worker::\$logFile = \$workerLogFile;$/m',
            $source,
            'Worker::$logFile must be assigned the config-derived variable',
        );

        // Order law: the include that populates $serverConfig must precede the
        // pin, or the read is against an undefined variable (the dead-seam
        // failure mode in a new costume).
        $includePos = strpos($source, "include \$configDir . '/server.php'");
        $pinPos = strpos($source, 'Worker::$logFile = $workerLogFile;');
        self::assertNotFalse($includePos, 'start.php must include config/server.php');
        self::assertNotFalse($pinPos, 'start.php must pin Worker::$logFile');
        self::assertLessThan(
            $pinPos,
            $includePos,
            'the server-config include must run BEFORE Worker::$logFile is pinned (SH-059)',
        );
    }

    public function testTheIncludeIsNotDuplicatedDownstream(): void
    {
        $source = $this->source(self::START_PHP);

        self::assertSame(
            1,
            preg_match_all('/include \$configDir \. \'\/server\.php\'/', $source),
            'server.php is read once; a second include re-runs the env closures '
                . 'and lets the two pins drift apart mid-boot',
        );
    }

    public function testTheDefaultPathIsRetainedAsTheFallback(): void
    {
        // systemd posture: the hardened unit grants ReadWritePaths for the
        // install's .logs/ only, so removing the literal fallback would make
        // a malformed/blank config value boot into an unwritable root.
        self::assertMatchesRegularExpression(
            '/:\s*__DIR__ \. \'\/\.logs\/workerman\.log\';/',
            $this->source(self::START_PHP),
            'the .logs/workerman.log default must stay as the belt-and-braces fallback',
        );
    }
}
