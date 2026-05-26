<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Http\Controllers\RelayController;
use Phlix\Hub\Http\Request;

/**
 * Unit tests for {@see RelayController}.
 *
 * The relay tunnel is established over the dedicated WS worker
 * (ws://…:8802), not over this HTTP endpoint. The controller is therefore
 * expected to return HTTP 501 (Not Implemented Via HTTP) for the post-auth,
 * post-upgrade-required path, with a body that points callers at the WS
 * endpoint. These tests pin that contract along with the surrounding auth
 * gates (401 / 426 / 400).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\RelayController
 */
final class RelayControllerTest extends TestCase
{
    private string $tmpDir;
    private Ed25519KeyManager $keyManager;
    private EnrollmentJwtService $jwtService;
    private RelayController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-relay-controller-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->keyManager = new Ed25519KeyManager($this->tmpDir . '/signing-key.pem');
        $this->jwtService = new EnrollmentJwtService($this->keyManager, 'https://hub.example.com');
        $this->controller = new RelayController($this->jwtService);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testReturns400WhenServerIdMissing(): void
    {
        $request = new Request();
        $response = $this->controller->handle($request, []);

        self::assertSame(400, $response->statusCode);
        $body = $this->decodeJsonBody($response->body);
        self::assertSame('MISSING_SERVER_ID', $body['error']);
    }

    public function testReturns401WhenAuthorizationHeaderMissing(): void
    {
        $request = new Request();
        $response = $this->controller->handle($request, ['id' => 'server-1']);

        self::assertSame(401, $response->statusCode);
        $body = $this->decodeJsonBody($response->body);
        self::assertSame('UNAUTHORIZED', $body['error']);
    }

    public function testReturns401WhenJwtInvalid(): void
    {
        $request = new Request();
        $request->headers['Authorization'] = 'Bearer not.a.jwt';
        $response = $this->controller->handle($request, ['id' => 'server-1']);

        self::assertSame(401, $response->statusCode);
    }

    public function testReturns426WhenUpgradeHeaderMissingButAuthValid(): void
    {
        $serverId = 'server-uuid-aaa';
        $token = $this->jwtService->createEnrollmentJwt($serverId);

        $request = new Request();
        $request->headers['Authorization'] = 'Bearer ' . $token;
        $response = $this->controller->handle($request, ['id' => $serverId]);

        self::assertSame(426, $response->statusCode);
        $body = $this->decodeJsonBody($response->body);
        self::assertSame('UPGRADE_REQUIRED', $body['error']);
    }

    public function testReturns501WhenValidJwtAndWebSocketUpgradeRequested(): void
    {
        $serverId = 'server-uuid-bbb';
        $token = $this->jwtService->createEnrollmentJwt($serverId);

        $request = new Request();
        $request->headers['Authorization'] = 'Bearer ' . $token;
        $request->headers['Upgrade'] = 'websocket';

        $response = $this->controller->handle($request, ['id' => $serverId]);

        // The post-auth, post-upgrade-required path must surface as 501
        // (Not Implemented) — the canonical status for "this server doesn't
        // know how to fulfill the request method" — not 500.
        self::assertSame(501, $response->statusCode);

        // The HTTP relay endpoint is intentionally HTTP-only: the tunnel
        // is established over the dedicated WS worker (ws://…:8802), so the
        // 501 body steers callers there rather than claiming the feature
        // is missing entirely.
        $body = $this->decodeJsonBody($response->body);
        self::assertSame('NOT_IMPLEMENTED_VIA_HTTP', $body['error']);
        self::assertSame('relay.ws_http_endpoint', $body['code']);
        self::assertIsString($body['message']);
        self::assertStringContainsString('WebSocket', $body['message']);
        self::assertIsString($body['ws_endpoint']);
        self::assertStringContainsString(':8802', $body['ws_endpoint']);
        self::assertSame(
            'https://detain.github.io/phlix-docs/dev/relay-protocol',
            $body['docs'],
        );

        // The WS endpoint is also advertised in a response header so a
        // client can discover it without parsing the body.
        self::assertArrayHasKey('X-WS-Endpoint', $response->headers);
        self::assertStringContainsString(':8802', $response->headers['X-WS-Endpoint']);

        // Sanity-check the docs Link header is present so clients can
        // discover the protocol spec without parsing the body.
        self::assertArrayHasKey('Link', $response->headers);
        self::assertStringContainsString('rel="help"', $response->headers['Link']);
    }

    public function testReturns401WhenServerIdMismatchesJwtSubject(): void
    {
        $token = $this->jwtService->createEnrollmentJwt('server-uuid-ccc');

        $request = new Request();
        $request->headers['Authorization'] = 'Bearer ' . $token;
        $request->headers['Upgrade'] = 'websocket';

        $response = $this->controller->handle($request, ['id' => 'a-different-server']);

        self::assertSame(401, $response->statusCode);
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
