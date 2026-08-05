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
 * resolves them by `include`-ing `private static string $configPath`
 * (`LoggerFactory.php:26`, `:28`, `:54`). Both survive for the life of the
 * process. A dozen test classes call `LoggerFactory::init()` with a config file
 * they write into a temp directory and then DELETE in `tearDown()` — and while
 * they do call `LoggerFactory::reset()` (which clears only the memo), none of
 * them ever restores `$configPath`. It is left pointing at a directory that no
 * longer exists.
 *
 * With `executionOrder="random"` that made a later test's behaviour depend on
 * which class happened to run before it: `$configPath` could be `''` (⇒
 * `include ''` raises `ValueError: Path cannot be empty`), a live path, or a
 * dangling one (⇒ `include` of a missing file warns, which `failOnWarning="true"`
 * turns into a failure). Same class of defect as the `Worker::$workers` latch
 * documented on {@see WorkermanTimerRuntimeControl}, and it is fixed the same
 * way.
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
