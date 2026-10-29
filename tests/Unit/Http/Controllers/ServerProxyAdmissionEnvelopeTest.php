<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Relay\RelayProxyBridge;
use PHPUnit\Framework\TestCase;

/**
 * M-HUB-16 — the hub's own error-envelope law applied to relay-proxy
 * admission refusals (review hub__gameux M1).
 *
 * openapi.yaml states the contract: "Errors are a flat JSON envelope:
 * {\"error\": \"<human text>\", \"code\": \"<machine code>\"}". The quota and
 * concurrent-stream refusals used to nest the discriminator INSIDE `error`
 * and ride 503 — the two most likely "can't play" answers a living-room
 * screen has were the least machine-readable responses on the wire, and a
 * player that obeys 503-retry semantics spins forever on a condition that
 * cannot recover before the monthly period roll.
 *
 * These pins drive the REAL controller to a REAL {@see Response} (status,
 * headers, body — the exact triplet ConnectionResponseSink writes) and
 * assert: flat top-level `code` (the discriminator clients branch on), honest
 * 429 status, and recovery time shipped where it is derivable — the period
 * roll surfaces as both a `Retry-After` header (seconds, never negative —
 * Application::rateLimitResponse()'s shipped shape) and an absolute
 * `retry_at` epoch in the body; the concurrent-stream cap, whose next free
 * slot is NOT derivable, ships NEITHER (an honest absence pinned as loudly as
 * the positive).
 *
 * Sibling refusal shapes in this controller family that were already
 * canonical — 503 `server.relay_unavailable` / `server.offline` (genuine
 * transient availability: 503 is honest, flat is law) — are pinned alongside
 * so the whole admission ladder is covered by one file, and the 409
 * `quota.exceeded` sites on the claim/share/invite REST family (already
 * canonical flat; pinned by QuotaExceededWireTest) are cited there, not
 * duplicated.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ServerProxyAdmissionEnvelopeTest extends TestCase
{
    /**
     * @param array{allowed: bool, reason: string|null, maxConcurrentStreams: int, retryAt: int|null} $quota
     */
    private function controller(array $quota, ?int $activeStreams, bool &$forwarded): ServerProxyController
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true,
        ]);

        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('checkUserQuota')->willReturn($quota);
        if ($activeStreams !== null) {
            $sessions->method('activeUserStreams')->willReturn($activeStreams);
        }

        $bridge = new RelayProxyBridge(
            $this->createMock(StructuredLogger::class),
            static function (string $event, array $data) use (&$forwarded): void {
                $forwarded = true;
            },
        );

        return new ServerProxyController(
            $info,
            $bridge,
            $this->createMock(StructuredLogger::class),
            $sessions,
            new RateLimiter(60, 600),
        );
    }

    private function request(): Request
    {
        $req = new Request();
        $req->method = 'GET';
        $req->path = '/api/v1/servers/srv-1/proxy/api/v1/libraries';
        $req->userId = 'user-1';
        $req->headers = ['Accept' => 'application/json'];
        return $req;
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    public function testBandwidthQuotaRefusalIsFlatRightStatusAndCarriesRecoveryTime(): void
    {
        $roll = (new \DateTimeImmutable('first day of next month 00:00:00'))->getTimestamp();
        $forwarded = false;
        $controller = $this->controller([
            'allowed' => false,
            'reason' => 'User has reached their monthly download bandwidth quota.',
            'maxConcurrentStreams' => 0,
            'retryAt' => $roll,
        ], null, $forwarded);

        $response = $controller->proxy($this->request(), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);

        self::assertFalse($forwarded, 'an over-quota request must never reach the relay bridge');
        // Honest status: a cap, not an outage.
        self::assertSame(429, $response->statusCode);

        $body = $this->bodyOf($response);
        // The law: discriminator rides the FLAT top-level `code`…
        self::assertSame('quota.exceeded', $body['code'] ?? null);
        // …and `error` is the human TEXT, never a nested object.
        self::assertIsString($body['error'] ?? null);
        self::assertSame('User has reached their monthly download bandwidth quota.', $body['error']);
        // Legacy mirror kept, matching every sibling refusal in this family.
        self::assertSame($body['error'], $body['message'] ?? null);
        // Recovery time, derivable: absolute epoch in the body…
        self::assertSame($roll, $body['retry_at'] ?? null);
        // …and seconds in the header (the Application::rateLimitResponse shape).
        $retryAfter = (int) ($response->headers['Retry-After'] ?? -1);
        self::assertGreaterThanOrEqual(0, $retryAfter);
        self::assertLessThanOrEqual(31 * 86400, $retryAfter);
        $expected = max(0, (int) $body['retry_at'] - time());
        self::assertLessThanOrEqual(
            2,
            abs($retryAfter - $expected),
            'Retry-After must name the same instant as retry_at',
        );
    }

    public function testBandwidthQuotaRefusalWithoutDerivableRollFabricatesNothing(): void
    {
        // A verdict without a derivable instant (e.g. a future non-calendar cap)
        // must ship the flat envelope with NO Retry-After and NO retry_at —
        // recovery time is only ever shipped when known.
        $forwarded = false;
        $controller = $this->controller([
            'allowed' => false,
            'reason' => 'User has reached their monthly download bandwidth quota.',
            'maxConcurrentStreams' => 0,
            'retryAt' => null,
        ], null, $forwarded);

        $response = $controller->proxy($this->request(), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);

        self::assertSame(429, $response->statusCode);
        $body = $this->bodyOf($response);
        self::assertSame('quota.exceeded', $body['code'] ?? null);
        self::assertArrayNotHasKey('retry_at', $body);
        self::assertArrayNotHasKey('Retry-After', $response->headers);
    }

    public function testConcurrentStreamCapRefusalIsFlatRightStatusAndPinsHonestAbsence(): void
    {
        $forwarded = false;
        $controller = $this->controller([
            'allowed' => true,
            'reason' => null,
            'maxConcurrentStreams' => 1,
            'retryAt' => null,
        ], 1, $forwarded);

        $response = $controller->proxy(
            $this->request(),
            ['id' => 'srv-1', 'path' => 'hls/job-abc/seg-00007.ts'],
        );

        self::assertFalse($forwarded, 'a capped stream must never reach the relay bridge');
        self::assertNull($response->streamProducer, 'a capped stream must not return a streaming producer');
        self::assertSame(429, $response->statusCode);

        $body = $this->bodyOf($response);
        self::assertSame('stream.limit', $body['code'] ?? null);
        self::assertIsString($body['error'] ?? null);
        // Honest absence pinned: the next free slot is not derivable.
        self::assertArrayNotHasKey('retry_at', $body);
        self::assertArrayNotHasKey('Retry-After', $response->headers);
    }

    public function testAvailabilityRefusalsStayCanonicalAndHonest(): void
    {
        // Census siblings already on the canonical path — pinned so a future
        // edit cannot silently nest them or status-wash them to 429: a server
        // that is offline/tunnel-less is GENUINELY unavailable, so 503 stays
        // the honest status there (players retrying is correct behavior).

        // Online server, tunnel down → 503 server.relay_unavailable.
        $forwarded = false;
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => false,
        ]);
        $bridge = new RelayProxyBridge(
            $this->createMock(StructuredLogger::class),
            static function (string $event, array $data) use (&$forwarded): void {
                $forwarded = true;
            },
        );
        $sessions = $this->createMock(RelaySessionManager::class);

        $controller = new ServerProxyController(
            $info,
            $bridge,
            $this->createMock(StructuredLogger::class),
            $sessions,
            new RateLimiter(60, 600),
        );

        $response = $controller->proxy($this->request(), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);
        self::assertSame(503, $response->statusCode);
        $body = $this->bodyOf($response);
        self::assertSame('server.relay_unavailable', $body['code'] ?? null);
        self::assertIsString($body['error'] ?? null);
        self::assertFalse($forwarded);

        // Offline server → 503 server.offline, same flat law.
        $forwardedOffline = false;
        $infoOffline = $this->createMock(ServerInfoHandler::class);
        $infoOffline->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'offline',
            'relayActive' => false,
        ]);
        $bridgeOffline = new RelayProxyBridge(
            $this->createMock(StructuredLogger::class),
            static function () use (&$forwardedOffline): void {
                $forwardedOffline = true;
            },
        );
        $controllerOffline = new ServerProxyController(
            $infoOffline,
            $bridgeOffline,
            $this->createMock(StructuredLogger::class),
            $this->createMock(RelaySessionManager::class),
            new RateLimiter(60, 600),
        );

        $response = $controllerOffline->proxy($this->request(), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);
        self::assertSame(503, $response->statusCode);
        $body = $this->bodyOf($response);
        self::assertSame('server.offline', $body['code'] ?? null);
        self::assertIsString($body['error'] ?? null);
    }
}
