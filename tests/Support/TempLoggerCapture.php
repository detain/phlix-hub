<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Common\Logger\LoggerFactory;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Point {@see LoggerFactory} at one temp stream file and read back what a test
 * under it actually wrote.
 *
 * ## Why this exists
 *
 * The estate logging convention is `LoggerFactory::get(LogChannels::*)` (hub
 * AGENTS.md), and a logger line is an ARTEFACT: a test that changes what a
 * controller logs has to assert the line the process really emits, not the
 * source text it hopes produces it (S345 rule 2). Before the 2026-11-03 review
 * cure, {@see \Phlix\Hub\Http\Controllers\SharedUiController} emitted its 503
 * diagnostic through bare `error_log()`, which the suite could capture with a
 * one-line `ini_set('error_log', …)`. A Monolog handler cannot be redirected
 * that way — it resolves its path from the config file the factory was
 * initialised with — so every test that reaches a logging path now needs a
 * factory pointed at scratch space.
 *
 * ## Contract
 *
 *  - call {@see startLoggerCapture()} from `setUp()` BEFORE the code under test
 *    runs, {@see stopLoggerCapture()} from `tearDown()`;
 *  - {@see capturedLoggerOutput()} returns everything written so far in this
 *    test, and {@see clearLoggerCapture()} empties it when one test drives
 *    several logging paths and each must be judged on its own lines;
 *  - the factory's process-global statics are restored exactly as found. The
 *    capture drives {@see LoggerFactoryIsolation}'s own capture/restore methods
 *    explicitly rather than relying on its `#[Before]`/`#[After]` hooks, so the
 *    ordering is this file's contract and not an attribute-collection detail —
 *    and `executionOrder="random"` still cannot leak a dangling `configPath`
 *    into a sibling suite, the defect that trait was written to close.
 *
 * Level `debug` on the single handler is deliberate: the capture must see every
 * level the code under test can reach, whatever the shipped `HUB_LOG_LEVEL`
 * default is.
 *
 * @package Phlix\Hub\Tests\Support
 */
trait TempLoggerCapture
{
    use LoggerFactoryIsolation;

    /** Scratch dir holding the generated config; empty while no capture runs. */
    private string $loggerCaptureDir = '';

    /** The stream file every handler in the capture writes to. */
    private string $loggerCaptureFile = '';

    /**
     * Initialise the factory against a throwaway single-stream config.
     */
    protected function startLoggerCapture(): void
    {
        $this->captureLoggerFactory();

        $dir = sys_get_temp_dir() . '/phlix-hub-logcap-' . bin2hex(random_bytes(6));
        self::assertTrue(is_dir($dir) || mkdir($dir, 0700, true), 'the capture scratch dir must be creatable');
        $resolved = realpath($dir);
        self::assertIsString($resolved, 'the capture scratch dir must resolve');
        $this->loggerCaptureDir = $resolved;

        $file = tempnam($this->loggerCaptureDir, 'capture-');
        self::assertIsString($file, 'the capture log file must be creatable');
        $this->loggerCaptureFile = $file;

        $config = $this->loggerCaptureDir . '/logger.php';
        self::assertIsInt(file_put_contents(
            $config,
            "<?php return ['default' => 'capture', 'handlers' => ['capture' => "
            . "['type' => 'stream', 'path' => '" . $this->loggerCaptureFile . "', 'level' => 'debug']]];",
        ));

        LoggerFactory::reset();
        LoggerFactory::init($config);
    }

    /**
     * Everything the captured handlers have written for the current test.
     */
    protected function capturedLoggerOutput(): string
    {
        self::assertNotSame('', $this->loggerCaptureFile, 'startLoggerCapture() must run before reading');

        return is_file($this->loggerCaptureFile)
            ? (string) file_get_contents($this->loggerCaptureFile)
            : '';
    }

    /**
     * Empty the capture, so the next assertion judges only newer lines.
     */
    protected function clearLoggerCapture(): void
    {
        self::assertNotSame('', $this->loggerCaptureFile, 'startLoggerCapture() must run before clearing');
        self::assertIsInt(file_put_contents($this->loggerCaptureFile, ''));
    }

    /**
     * Drop the memoised loggers, remove the scratch tree, and put the factory's
     * statics back exactly as {@see startLoggerCapture()} found them.
     */
    protected function stopLoggerCapture(): void
    {
        // The reset frees the open stream on a file this method is about to delete.
        LoggerFactory::reset();

        foreach ([$this->loggerCaptureFile, $this->loggerCaptureDir . '/logger.php'] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }

        if ($this->loggerCaptureDir !== '' && is_dir($this->loggerCaptureDir)) {
            @rmdir($this->loggerCaptureDir);
        }

        $this->loggerCaptureDir = '';
        $this->loggerCaptureFile = '';

        $this->restoreLoggerFactory();
    }
}
