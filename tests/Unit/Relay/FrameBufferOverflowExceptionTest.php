<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Relay\FrameBufferOverflowException;
use Phlix\Hub\Relay\InvalidFrameTypeException;

/**
 * Unit tests for {@see FrameBufferOverflowException}.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 *
 * @covers \Phlix\Hub\Relay\FrameBufferOverflowException
 */
final class FrameBufferOverflowExceptionTest extends TestCase
{
    public function testExceptionHasCorrectBufferSizes(): void
    {
        $ex = new FrameBufferOverflowException(65536, 65536 * 2);

        self::assertSame(65536, $ex->bufferSize);
        self::assertSame(65536 * 2, $ex->maxBufferSize);
    }

    public function testExceptionMessageDescribesOverflow(): void
    {
        $ex = new FrameBufferOverflowException(100000, 65536);

        self::assertStringContainsString('100000', $ex->getMessage());
        self::assertStringContainsString('65536', $ex->getMessage());
        self::assertStringContainsString('bytes buffered', $ex->getMessage());
    }

    public function testExceptionHasCode1011(): void
    {
        $ex = new FrameBufferOverflowException(1000, 512);

        self::assertSame(1011, $ex->getCode());
    }

    public function testMessageIsPrefixedWithRelayFrameBufferOverflow(): void
    {
        $ex = new FrameBufferOverflowException(1024, 512);

        self::assertStringStartsWith('Relay frame buffer overflow: ', $ex->getMessage());
    }

    public function testExtendsInvalidFrameTypeException(): void
    {
        $ex = new FrameBufferOverflowException(100, 50);

        self::assertInstanceOf(\Phlix\Hub\Relay\InvalidFrameTypeException::class, $ex);
    }

    public function testOriginalExceptionMessageIsIncluded(): void
    {
        $ex = new FrameBufferOverflowException(100000, 65536);

        // The parent message format: "{bufferSize} bytes buffered without a complete frame (max {maxBufferSize})"
        self::assertStringContainsString('100000 bytes buffered without a complete frame (max 65536)', $ex->getMessage());
    }
}
