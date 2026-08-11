<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Relay\InvalidFrameTypeException;

/**
 * Unit tests for {@see InvalidFrameTypeException}.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class InvalidFrameTypeExceptionTest extends TestCase
{
    public function testExceptionHasCode1011(): void
    {
        $ex = new InvalidFrameTypeException(0x05);

        self::assertSame(1011, $ex->getCode());
    }

    public function testMessageContainsFrameTypeInHex(): void
    {
        $ex = new InvalidFrameTypeException(0x0f);

        // dechex() does not zero-pad, so 0x0f becomes 'f'
        self::assertStringContainsString('0xf', $ex->getMessage());
    }

    public function testMessageIncludesReasonWhenProvided(): void
    {
        $ex = new InvalidFrameTypeException(0x10, 'unexpected continuation byte');

        self::assertStringContainsString('unexpected continuation byte', $ex->getMessage());
        self::assertStringContainsString('0x10', $ex->getMessage());
    }

    public function testMessageWithoutReasonContainsOnlyHex(): void
    {
        $ex = new InvalidFrameTypeException(0x07);

        // dechex() does not zero-pad, so 0x07 becomes '7'
        self::assertSame('Invalid frame type 0x7', $ex->getMessage());
    }

    public function testMessageWithReasonContainsHexAndReason(): void
    {
        $ex = new InvalidFrameTypeException(0x08, 'reserved bit set');

        self::assertSame('Invalid frame type 0x8: reserved bit set', $ex->getMessage());
    }

    public function testFormatMessageIsProtectedAndCanBeOverridden(): void
    {
        // Verify the formatMessage method exists and is protected
        $reflection = new \ReflectionMethod(InvalidFrameTypeException::class, 'formatMessage');
        self::assertTrue($reflection->isProtected());
    }
}
