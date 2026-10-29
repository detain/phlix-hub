<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Relay\ClientRelayWorker;

/**
 * Unit tests for {@see ClientMountController}'s plain-HTTP entry point.
 *
 * The client relay tunnel is established over the dedicated client WS worker
 * (`ws://…:8803`, {@see ClientRelayWorker}), not over this HTTP route. Hitting
 * `GET /client/{server_id}` with plain HTTP must therefore steer the caller to
 * the WS endpoint — 426 when no upgrade was requested, 501 otherwise — and
 * never the old hardcoded 401 "not yet implemented" stub. These tests pin that
 * contract. The WS-upgrade handlers (onWebSocketConnect et al.) are covered by
 * {@see \Phlix\Hub\Tests\Unit\Relay\ClientRelayWorkerTest}.
 *
 * ⚠ S205 — these tests assign `$request->headers['Upgrade']` on a bare
 * `new Request()`, which SKIPS the case normalisation every real request goes
 * through, so they can never detect a header read that misses in production.
 * They stayed green while the 501 branch below was unreachable for every real
 * caller. The header plumbing is pinned at the boundary instead, by
 * {@see \Phlix\Hub\Tests\Unit\Http\Controllers\WorkermanHeaderBoundaryTest};
 * do not add coverage of a header READ here.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ClientMountControllerTest extends TestCase
{
    private ClientMountController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Stateless since M2 (review hub__asphp): neither the HTTP signpost nor
        // the declared-unavailable WS refusal resolves anything from a container
        // (see the controller class docblock). HB-4.6f still holds: the real
        // client-mount rate limit lives on the :8803 WS worker.
        $this->controller = new ClientMountController();
    }

    public function testReturns400WhenServerIdMissing(): void
    {
        $response = $this->controller->handle(new Request(), []);

        self::assertSame(400, $response->statusCode);
        $body = $this->decodeJsonBody($response->body);
        self::assertSame('MISSING_SERVER_ID', $body['error']);
    }

    public function testReturns426WhenNoWebSocketUpgrade(): void
    {
        $response = $this->controller->handle(new Request(), ['server_id' => 'server-uuid-aaa']);

        self::assertSame(426, $response->statusCode);
        $body = $this->decodeJsonBody($response->body);
        self::assertSame('UPGRADE_REQUIRED', $body['error']);
        self::assertSame('relay.client_ws_endpoint', $body['code']);
        self::assertIsString($body['ws_endpoint']);
        self::assertStringContainsString(':' . ClientRelayWorker::DEFAULT_PORT, $body['ws_endpoint']);
        self::assertStringContainsString('/client/server-uuid-aaa', $body['ws_endpoint']);
        self::assertArrayHasKey('X-WS-Endpoint', $response->headers);
        self::assertStringContainsString(':' . ClientRelayWorker::DEFAULT_PORT, $response->headers['X-WS-Endpoint']);
        // M2 (review hub__asphp): the steer must not promise a working mount —
        // the WS surface declares itself unavailable in this topology.
        self::assertFalse($body['available']);
        self::assertIsString($body['message']);
        self::assertStringContainsString('not available', $body['message']);
    }

    public function testReturns501WhenWebSocketUpgradeRequested(): void
    {
        $request = new Request();
        $request->headers['Upgrade'] = 'websocket';

        $response = $this->controller->handle($request, ['server_id' => 'server-uuid-bbb']);

        self::assertSame(501, $response->statusCode);
        $body = $this->decodeJsonBody($response->body);
        self::assertSame('NOT_IMPLEMENTED_VIA_HTTP', $body['error']);
        self::assertSame('relay.client_ws_endpoint', $body['code']);
        self::assertIsString($body['ws_endpoint']);
        self::assertStringContainsString(':' . ClientRelayWorker::DEFAULT_PORT, $body['ws_endpoint']);
        self::assertArrayHasKey('X-WS-Endpoint', $response->headers);
        // M2 (review hub__asphp): see the 426 pin — the WS mount it steers to
        // refuses structurally; this body must not promise it works.
        self::assertFalse($body['available']);
        self::assertIsString($body['message']);
        self::assertStringContainsString('not available', $body['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
