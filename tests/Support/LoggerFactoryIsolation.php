<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use ReflectionProperty;

/**
 * Keep {@see LoggerFactory}'s process-global statics inside the test that set
 * them.
 *
 * ## Why this exists
 *
 * `LoggerFactory` memoises loggers in `private static array $loggers` and
 * resolves them by reading `private static string $configPath` through
 * `loadConfig()` (`LoggerFactory.php:37`, `:39`, `:94`). Both survive for
 * the life of the process. A dozen test classes call
 * `LoggerFactory::init()` with a config file they write into a temp
 * directory and then DELETE in `tearDown()`.
 *
 * The raw-include failure modes that originally made this hazardous —
 * `$configPath` left as `''` (⇒ `ValueError: Path cannot be empty`) or as
 * a dangling pointer (⇒ `include` of a missing file warns, which
 * `failOnWarning="true"` turns into a failure) — were closed at the source
 * by the A-17 guard port: the factory now falls back to an empty config,
 * and `reset()` clears the path as well as the memo. The trait remains
 * load-bearing regardless: with `executionOrder="random"`, a suite that
 * skips the reset still RESOLVES against whichever earlier suite's
 * memoised instances the process happens to hold — a logger bound to a
 * foreign config's handlers is as silent as it is wrong. Same class of
 * defect as the `Worker::$workers` latch documented on
 * {@see WorkermanTimerRuntimeControl}, and it is fixed the same way.
 *
 * ## Contract
 *
 * `use` this trait and carry on calling `LoggerFactory::init()` however the test
 * already does. The `#[Before]` hook snapshots both statics BEFORE `setUp()`
 * runs and the `#[After]` hook puts them back AFTER `tearDown()` — an ordering
 * verified against PHPUnit 10.5 rather than assumed, and one that also holds when
 * the test fails, errors, or throws out of `setUp()` itself.
 *
 * The trait deliberately does NOT call `LoggerFactory::init()` for you: what a
 * given suite wants to point the factory at is that suite's business.
 */
trait LoggerFactoryIsolation
{
    /** Snapshot of `LoggerFactory::$configPath` taken before the current test. */
    private string $loggerFactoryConfigPathSnapshot = '';

    /**
     * Snapshot of `LoggerFactory::$loggers` taken before the current test.
     *
     * @var array<string, StructuredLogger>
     */
    private array $loggerFactoryCacheSnapshot = [];

    /** Guards the restore against a snapshot that was never taken. */
    private bool $loggerFactoryCaptured = false;

    #[Before]
    protected function captureLoggerFactory(): void
    {
        $this->loggerFactoryConfigPathSnapshot = self::readLoggerFactoryConfigPath();
        $this->loggerFactoryCacheSnapshot = self::readLoggerFactoryCache();
        $this->loggerFactoryCaptured = true;
    }

    #[After]
    protected function restoreLoggerFactory(): void
    {
        if (!$this->loggerFactoryCaptured) {
            return;
        }

        self::writeLoggerFactoryConfigPath($this->loggerFactoryConfigPathSnapshot);
        self::writeLoggerFactoryCache($this->loggerFactoryCacheSnapshot);
        $this->loggerFactoryCaptured = false;
    }

    private static function readLoggerFactoryConfigPath(): string
    {
        /** @var string $value */
        $value = (new ReflectionProperty(LoggerFactory::class, 'configPath'))->getValue();

        return $value;
    }

    private static function writeLoggerFactoryConfigPath(string $path): void
    {
        (new ReflectionProperty(LoggerFactory::class, 'configPath'))->setValue(null, $path);
    }

    /**
     * @return array<string, StructuredLogger>
     */
    private static function readLoggerFactoryCache(): array
    {
        /** @var array<string, StructuredLogger> $value */
        $value = (new ReflectionProperty(LoggerFactory::class, 'loggers'))->getValue();

        return $value;
    }

    /**
     * @param array<string, StructuredLogger> $loggers
     */
    private static function writeLoggerFactoryCache(array $loggers): void
    {
        (new ReflectionProperty(LoggerFactory::class, 'loggers'))->setValue(null, $loggers);
    }
}
