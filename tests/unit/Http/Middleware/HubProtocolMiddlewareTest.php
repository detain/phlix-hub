<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\unit\Http\Middleware;

use Phlix\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see HubProtocolMiddleware}.
 *
 * @package Phlix\Hub\Tests\unit\Http\Middleware
 * @since 0.3.0
 *
 * @covers \Phlix\Hub\Http\Middleware\HubProtocolMiddleware
 */
final class HubProtocolMiddlewareTest extends TestCase
{
    public function testValidHeaderPasses(): void
    {
        $middleware = new HubProtocolMiddleware();

        $request = new Request();
        $request->headers['Accept-Phlix-Protocol'] = 'v1';

        $result = $middleware($request);

        self::assertNull($result);
    }

    public function testMissingHeaderReturns400(): void
    {
        $middleware = new HubProtocolMiddleware();

        $request = new Request();

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(400, $result->statusCode);
        self::assertStringContainsString('HUB_PROTOCOL_UNSUPPORTED', $result->body);
    }

    public function testWrongVersionReturns400(): void
    {
        $middleware = new HubProtocolMiddleware();

        $request = new Request();
        $request->headers['Accept-Phlix-Protocol'] = 'v2';

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(400, $result->statusCode);
        self::assertStringContainsString('HUB_PROTOCOL_UNSUPPORTED', $result->body);
    }

    public function testEmptyHeaderReturns400(): void
    {
        $middleware = new HubProtocolMiddleware();

        $request = new Request();
        $request->headers['Accept-Phlix-Protocol'] = '';

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(400, $result->statusCode);
    }
}
