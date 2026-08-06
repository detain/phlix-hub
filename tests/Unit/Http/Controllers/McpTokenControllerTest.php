<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Controllers\McpTokenController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpTokenService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function is_array;
use function json_decode;
use function str_contains;

/**
 * Unit tests for {@see McpTokenController} — minting, listing and revoking MCP
 * personal access tokens (S62).
 *
 * The service is the REAL {@see McpTokenService} over a doubled
 * {@see Connection}, so the SQL and the hashing stay under test.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\McpTokenController
 */
final class McpTokenControllerTest extends TestCase
{
    private const string USER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /**
     * @dataProvider unauthenticatedCallProvider
     */
    public function test_every_route_refuses_an_unauthenticated_caller(string $method): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');

        $controller = new McpTokenController(new McpTokenService($db), $this->createMock(AuditLogger::class));

        $response = $controller->{$method}(new Request(), ['id' => 'row-1']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(401, $response->statusCode);
        self::assertSame('auth.required', self::body($response)['code'] ?? null);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unauthenticatedCallProvider(): array
    {
        return [['index'], ['create'], ['revoke']];
    }

    public function test_create_returns_the_plaintext_exactly_once(): void
    {
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $response = $this->controller($db)->create($this->request([
            'name' => 'Claude Desktop',
            'scopes' => [McpScopes::SERVERS_READ, McpScopes::LIBRARY_READ],
        ]));

        self::assertSame(201, $response->statusCode);
        $body = self::body($response);
        self::assertIsString($body['token']);
        self::assertStringStartsWith(McpTokenService::TOKEN_PREFIX, $body['token']);
        self::assertSame([McpScopes::SERVERS_READ, McpScopes::LIBRARY_READ], $body['scopes']);
        // The row is bound to the AUTHENTICATED user, never to a body field.
        self::assertSame(self::USER_A, $captured['user_id']);
    }

    /**
     * A body naming somebody else's user id changes nothing: the row is bound
     * to `$request->userId`.
     */
    public function test_a_user_id_in_the_body_is_ignored(): void
    {
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $this->controller($db)->create($this->request([
            'name' => 'x',
            'user_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ]));

        self::assertSame(self::USER_A, $captured['user_id']);
    }

    public function test_a_scope_list_with_nothing_known_in_it_is_refused(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');

        $response = $this->controller($db)->create($this->request(['scopes' => ['admin:*', 'root']]));

        self::assertSame(400, $response->statusCode);
        self::assertSame('mcp_token.no_valid_scopes', self::body($response)['code'] ?? null);
    }

    /**
     * Control for the refusal above: omitting `scopes` entirely grants the full
     * read set rather than failing, so the 400 is about UNRECOGNISED scopes and
     * not about "any request without an explicit list".
     */
    public function test_omitting_scopes_grants_the_full_read_set(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $response = $this->controller($db)->create($this->request(['name' => 'x']));

        self::assertSame(201, $response->statusCode);
        self::assertSame(McpScopes::all(), self::body($response)['scopes']);
    }

    public function test_index_lists_the_callers_tokens_and_the_available_scopes(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'FROM mcp_tokens')) {
                    return [];
                }
                self::assertSame(self::USER_A, $params['user_id']);

                return [];
            },
        );

        $response = $this->controller($db)->index($this->request());

        self::assertSame(200, $response->statusCode);
        $body = self::body($response);
        self::assertSame([], $body['tokens']);
        self::assertSame(McpScopes::all(), $body['available_scopes']);
    }

    public function test_revoking_a_row_that_is_not_yours_is_an_indistinguishable_404(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(0);

        $response = $this->controller($db)->revoke($this->request(), ['id' => 'someone-elses-row']);

        self::assertSame(404, $response->statusCode);
        self::assertSame('mcp_token.not_found', self::body($response)['code'] ?? null);
    }

    public function test_revoking_your_own_row_succeeds_and_is_audited(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(1);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logAdminAction')
            ->with(self::USER_A, 'mcp_token.revoke', 'row-1');

        $controller = new McpTokenController(new McpTokenService($db), $audit);
        $response = $controller->revoke($this->request(), ['id' => 'row-1']);

        self::assertSame(200, $response->statusCode);
        self::assertTrue(self::body($response)['revoked']);
    }

    // ------------------------------------------------------------------

    private function controller(Connection $db): McpTokenController
    {
        return new McpTokenController(new McpTokenService($db), $this->createMock(AuditLogger::class));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body = []): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/me/mcp-tokens';
        $request->userId = self::USER_A;
        $request->body = $body;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertTrue(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
