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
 * @since 0.3.0
 *
 * @covers \Phlix\Hub\Http\Controllers\ServerClaimController
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
            'publicKeysJwk' => ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='],
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
}
