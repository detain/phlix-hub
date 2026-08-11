<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\Container\MissingJwtSecretException;

/**
 * Unit tests for {@see MissingJwtSecretException}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container
 */
final class MissingJwtSecretExceptionTest extends TestCase
{
    public function testExceptionIsRuntimeException(): void
    {
        $ex = new MissingJwtSecretException();

        self::assertInstanceOf(\RuntimeException::class, $ex);
    }

    public function testExceptionCanBeThrownAndCaught(): void
    {
        $this->expectException(MissingJwtSecretException::class);

        throw new MissingJwtSecretException();
    }

    public function testExceptionCanHaveAMessage(): void
    {
        $ex = new MissingJwtSecretException('JWT secret is not configured');

        self::assertSame('JWT secret is not configured', $ex->getMessage());
    }

    public function testExceptionCanHaveACode(): void
    {
        $ex = new MissingJwtSecretException('secret missing', 42);

        self::assertSame(42, $ex->getCode());
    }

    public function testExceptionCanHavePreviousException(): void
    {
        $previous = new \RuntimeException('Original error');
        $ex = new MissingJwtSecretException('JWT secret missing', 0, $previous);

        self::assertSame($previous, $ex->getPrevious());
    }
}
