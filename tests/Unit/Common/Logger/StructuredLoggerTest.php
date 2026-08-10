<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Logger;

use Monolog\Logger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for {@see StructuredLogger}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Logger
 *
 * @covers \Phlix\Hub\Common\Logger\StructuredLogger
 */
final class StructuredLoggerTest extends TestCase
{
    public function testMonologLoopDetectionIsDisabledForCoroutineSafety(): void
    {
        // Monolog's infinite-loop guard keys recursion depth on the current PHP
        // Fiber, but Swoole coroutines are not Fibers — so under SWOOLE_HOOK_ALL a
        // handler's file write yields the coroutine mid-addRecord and a concurrent
        // coroutine re-enters this shared (singleton) logger, tripping the guard
        // into a false positive that DROPS the record ("A possible infinite logging
        // loop was detected and aborted"). StructuredLogger disables the guard.
        $structured = new StructuredLogger('relay', ['handlers' => []]);

        $monolog = (new ReflectionProperty(StructuredLogger::class, 'logger'))->getValue($structured);
        self::assertInstanceOf(Logger::class, $monolog);

        $detectCycles = (new ReflectionProperty(Logger::class, 'detectCycles'))->getValue($monolog);
        self::assertFalse(
            $detectCycles,
            'Monolog loop detection must be disabled so concurrent Swoole-coroutine logging is not dropped',
        );
    }
}
