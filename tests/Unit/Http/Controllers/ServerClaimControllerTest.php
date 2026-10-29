<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ServerClaimController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ServerClaimControllerTest extends TestCase
{
    public function testNewClaimRejectsWrongProtocolHeader(): void
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->headers['Accept-Phlix-Protocol'] = 'v2';
        $request->body = [
            'serverName' => 'Test',
            'version' => '0.11.0',
            'publicKeysJwk' => [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
            ],
            'hostnameCandidates' => [],
            'protocolVersion' => 'v1',
        ];

        $response = $controller->newClaim($request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('HUB_PROTOCOL_UNSUPPORTED', $response->body);
    }

    public function testClaimRequiresUserAuth(): void
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/claim';

        $response = $controller->claim($request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('UNAUTHENTICATED', $response->body);
    }

    public function testClaimRequiresClaimCode(): void
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/claim';
        $request->userId = 'user-1';

        $response = $controller->claim($request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('claim_code is required', $response->body);
    }

    public function testNewClaimReturns400OnMalformedRequest(): void
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->body = [];

        $response = $controller->newClaim($request);

        self::assertSame(400, $response->statusCode);
    }

    // ------------------------------------------------------------------
    // M-HUB-13 body cap (review hub__exploiter M2): the claim surface refuses
    // oversized raw bodies BEFORE parse, budget, and DB — frame is this
    // endpoint's registered invalid_payload shape, zero new wire codes.
    // Red on the parent tree (no cap existed: the request fell through to
    // fromPayload/handler).
    // ------------------------------------------------------------------

    /**
     * A claim payload that is well-formed for fromPayload but padded past
     * the cap with a large (also unbounded per M2, cured handler-side)
     * hostname array.
     *
     * @return array{payload: array<string,mixed>, raw: string}
     */
    private function oversizedClaimPayload(int $overBy = 1): array
    {
        $payload = [
            'serverName' => 'Flood Box',
            'version' => '0.12.0',
            'publicKeysJwk' => [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            ],
            'protocolVersion' => 'v1',
            'hostnameCandidates' => [],
        ];
        $targetBytes = 16384 + $overBy;
        while (strlen((string) json_encode($payload)) < $targetBytes) {
            $payload['hostnameCandidates'][] = 'f' . count($payload['hostnameCandidates'])
                . '.flood.example.com';
        }
        return ['payload' => $payload, 'raw' => (string) json_encode($payload)];
    }

    public function testNewClaimRejectsOverCapBodyBeforeAnyParseOrHandler(): void
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->expects(self::never())->method('handleNewClaim');
        $controller = new ServerClaimController($handler);

        ['payload' => $payload, 'raw' => $raw] = $this->oversizedClaimPayload();

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->rawBody = $raw;
        $request->body = $payload;

        $response = $controller->newClaim($request);

        self::assertSame(400, $response->statusCode);
        $frame = json_decode($response->body, true);
        self::assertIsArray($frame);
        self::assertSame('invalid_payload', $frame['code']);
        self::assertStringContainsString('exceeds 16384 bytes', $frame['message']);
    }

    public function testBodyCapBoundaryIsInclusiveAtTheMax(): void
    {
        // Exactly-at-cap must NOT be the cap arm (off-by-one pin): the
        // request proceeds to the parse boundary, whose fromPayload catch
        // answers with the SAME registered frame family but its own message.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->expects(self::never())->method('handleNewClaim');
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->rawBody = str_repeat('x', 16384);
        $request->body = [];

        $response = $controller->newClaim($request);

        self::assertSame(400, $response->statusCode);
        $frame = json_decode($response->body, true);
        self::assertIsArray($frame);
        self::assertSame('invalid_payload', $frame['code']);
        self::assertStringNotContainsString('exceeds', (string) $frame['message']);
    }

    public function testProtocolGuardStillPrecedesTheBodyCap(): void
    {
        // Free header guard first, size guard second: a wrong-protocol flood
        // with a huge body is answered by the protocol frame, and the cap
        // message must not leak into it.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $controller = new ServerClaimController($handler);

        ['raw' => $raw] = $this->oversizedClaimPayload();

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->headers['Accept-Phlix-Protocol'] = 'v0';
        $request->rawBody = $raw;
        $request->body = [];

        $response = $controller->newClaim($request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('HUB_PROTOCOL_UNSUPPORTED', $response->body);
        self::assertStringNotContainsString('exceeds', $response->body);
    }

    public function testAtOrUnderCapLegitBodyReachesTheHandler(): void
    {
        // The cap bites floods, never legal pairing material: a compact real
        // payload flows through to the handler untouched.
        $claimResponse = new \Phlix\Shared\Hub\ClaimResponse(
            claimCode: 'ABCD-1234',
            expiresIn: 600,
            claimId: 'claim-1',
            hubBaseUrl: 'https://hub.example.com',
        );
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->expects(self::once())->method('handleNewClaim')->willReturn($claimResponse);
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $body = [
            'serverName' => 'My NAS',
            'version' => '0.12.0',
            'publicKeysJwk' => [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            ],
            'hostnameCandidates' => ['nas.lan', '192.168.1.10'],
            'protocolVersion' => 'v1',
        ];
        $request->rawBody = (string) json_encode($body);
        $request->body = $body;

        $response = $controller->newClaim($request);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('ABCD-1234', $response->body);
    }

    public function testNewClaimMapsHostnameRejectionToInvalidPayloadFrame(): void
    {
        // M-HUB-13 wire pin: the handler's HOSTNAME_CANDIDATES_INVALID
        // marker must answer through the mapError arm added with it — 400
        // invalid_payload (registered code, endpoint's shape-reject family) —
        // never folded into the 500 default. Red if the arm is stripped.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleNewClaim')
            ->willThrowException(new \InvalidArgumentException('HOSTNAME_CANDIDATES_INVALID'));
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->body = [
            'serverName' => 'Big Hosts',
            'version' => '0.12.0',
            'publicKeysJwk' => [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            ],
            'hostnameCandidates' => array_fill(0, 17, 'h.lan'),
            'protocolVersion' => 'v1',
        ];

        $response = $controller->newClaim($request);

        self::assertSame(400, $response->statusCode);
        $frame = json_decode($response->body, true);
        self::assertIsArray($frame);
        self::assertSame('invalid_payload', $frame['code']);
        self::assertSame('Bad Request', $frame['error']);
        self::assertStringContainsString('hostnameCandidates', $frame['message']);
    }

    public function testAggregateFloorRateLimitBubblesToCentralMappingUntouched(): void
    {
        // M-HUB-13 pass-through pin: the floor's RateLimitException must NOT
        // be caught by the controller's InvalidArgumentException arms — it
        // escapes to Application's central 429 mapping (429 + Retry-After +
        // rate_limited, pinned there by the M12 family). Red if someone
        // broadens the catch to Throwable or wraps the floor throw.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleNewClaim')
            ->willThrowException(new \Phlix\Hub\Auth\RateLimitException(
                resetAt: time() + 600,
                remaining: 0,
            ));
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->body = [
            'serverName' => 'Floody',
            'version' => '0.12.0',
            'publicKeysJwk' => [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            ],
            'hostnameCandidates' => [],
            'protocolVersion' => 'v1',
        ];

        $this->expectException(\Phlix\Hub\Auth\RateLimitException::class);
        $controller->newClaim($request);
    }
}
