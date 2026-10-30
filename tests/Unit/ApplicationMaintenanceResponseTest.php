<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use Phlix\Hub\Application;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Application::maintenanceResponse()} — the dispatch-gate
 * 503 envelope (M-HUB-32). Mirrors the law already pinned for the 429 in
 * {@see ApplicationRateLimitResponseTest}: a "come back later" answer must
 * carry WHEN, as a `Retry-After` header, not just hope in prose.
 *
 * @package Phlix\Hub\Tests\Unit
 */
final class ApplicationMaintenanceResponseTest extends TestCase
{
    public function testMaintenanceResponseCarriesRetryAfterAndUnchangedBody(): void
    {
        $response = Application::maintenanceResponse();

        self::assertSame(503, $response->statusCode);
        // The body the gate shipped before M-HUB-32, byte-identical: the cure
        // is additive (one header), never a re-wording of the promise.
        self::assertSame(
            '{"error":"This hub is under maintenance. Try again shortly.","code":"provider_unavailable"}',
            $response->body,
        );
        self::assertSame('60', $response->headers['Retry-After']);
    }

    public function testMaintenanceResponseSurvivesWorkermanConversion(): void
    {
        // The dispatch gate hands toWorkermanResponse() straight to the socket;
        // the header must make that trip (the 429's does — same Response
        // builder — but this envelope is its own wire and gets its own pin).
        $workerman = Application::maintenanceResponse()->toWorkermanResponse();

        self::assertSame(503, $workerman->getStatusCode());
        self::assertSame('60', $workerman->getHeader('Retry-After'));
        self::assertStringContainsString('provider_unavailable', (string) $workerman->rawBody());
    }
}
