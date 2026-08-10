<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ClientRelayTokenController;
use Phlix\Hub\Http\Request;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function json_decode;

/**
 * @covers \Phlix\Hub\Http\Controllers\ClientRelayTokenController
 */
final class ClientRelayTokenControllerTest extends TestCase
{
    private function dto(string $userId): ServerInfoDto
    {
        return new ServerInfoDto(
            'srv-1',
            $userId,
            'My Server',
            '1.0.0',
            null,
            ServerInfoDto::STATUS_ONLINE,
            [],
            true,
        );
    }

    private function request(?string $userId): Request
    {
        $req = new Request();
        $req->method = 'POST';
        $req->path = '/api/v1/me/servers/srv-1/relay-token';
        $req->userId = $userId;
        return $req;
    }

    /**
     * @param ServerInfoHandler         $info  Ownership resolver.
     * @param ClientRelayTokenService   $tokens Token service (real, mocked DB).
     */
    private function controller(
        ServerInfoHandler $info,
        ClientRelayTokenService $tokens,
    ): ClientRelayTokenController {
        $audit = new AuditLogger($this->createMock(\Phlix\Hub\Common\Logger\StructuredLogger::class));
        return new ClientRelayTokenController($tokens, $info, $audit);
    }

    private function tokenService(): ClientRelayTokenService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        return new ClientRelayTokenService($db);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $controller = $this->controller($info, $this->tokenService());

        $response = $controller->mint($this->request(null), ['id' => 'srv-1']);
        $this->assertSame(401, $response->statusCode);
    }

    public function testUnknownServerReturns404(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn(null);
        $controller = $this->controller($info, $this->tokenService());

        $response = $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
        $this->assertSame(404, $response->statusCode);
    }

    public function testNotOwnedReturns403AndDoesNotMint(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('someone-else'));

        // A token service whose DB MUST NOT be hit when ownership fails.
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $tokens = new ClientRelayTokenService($db);

        $controller = $this->controller($info, $tokens);

        $response = $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
        $this->assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.not_owned', $body['code'] ?? null);
    }

    public function testOwnedServerMintsTokenAndReturns201(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1'));

        $controller = $this->controller($info, $this->tokenService());

        $response = $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
        $this->assertSame(201, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertIsString($body['token'] ?? null);
        $this->assertNotSame('', $body['token']);
        $this->assertIsInt($body['expires_at'] ?? null);
        $this->assertSame('srv-1', $body['server_id'] ?? null);
    }
}
