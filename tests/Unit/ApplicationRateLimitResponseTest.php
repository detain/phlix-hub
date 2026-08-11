<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\RateLimitException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Application::rateLimitResponse()} — the central HTTP
 * 429 mapping invoked by the outer request catch when a limiter trip bubbles
 * out of dispatch (proxy/jwks throw rather than mapping locally, so they land
 * here). Proves the 429 + `Retry-After` + `code:'rate_limited'` envelope,
 * i.e. the surfaces that rely on the central catch return 429, NOT 500.
 *
 * @package Phlix\Hub\Tests\Unit
 */
final class ApplicationRateLimitResponseTest extends TestCase
{
    public function testRateLimitResponseMapsExceptionTo429Envelope(): void
    {
        $response = Application::rateLimitResponse(
            new RateLimitException(resetAt: time() + 30, remaining: 0),
        );

        self::assertSame(429, $response->statusCode);
        self::assertArrayHasKey('Retry-After', $response->headers);
        $retryAfter = (int) $response->headers['Retry-After'];
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(30, $retryAfter);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('Too Many Requests', $decoded['error']);
        self::assertSame('rate_limited', $decoded['code']);
    }

    public function testRateLimitResponseRetryAfterNeverNegative(): void
    {
        // A window already in the past yields a non-negative Retry-After.
        $response = Application::rateLimitResponse(
            new RateLimitException(resetAt: 1, remaining: 0),
        );

        self::assertSame(429, $response->statusCode);
        self::assertSame('0', $response->headers['Retry-After']);
    }
}
