<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ClientRelayTokenController;
use Phlix\Hub\Http\Request;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function json_decode;
use function time;

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
     * @param ServerInfoHandler       $info    Ownership resolver.
     * @param ClientRelayTokenService $tokens  Token service (real, mocked DB).
     * @param AuditLogger|null        $audit   Audit sink (defaults to a real
     *                                         one over a mocked channel — the
     *                                         pre-M-HUB-22 posture; tests that
     *                                         assert audit calls pass a mock).
     * @param RateLimiterInterface|null $limiter M-HUB-22 mint budget limiter.
     */
    private function controller(
        ServerInfoHandler $info,
        ClientRelayTokenService $tokens,
        ?AuditLogger $audit = null,
        ?RateLimiterInterface $limiter = null,
    ): ClientRelayTokenController {
        $audit ??= new AuditLogger($this->createMock(\Phlix\Hub\Common\Logger\StructuredLogger::class));
        return new ClientRelayTokenController($tokens, $info, $audit, $limiter);
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

    /**
     * M-HUB-22: the mint budget is keyed on the AUTHENTICATED MEMBER —
     * `client_relay_token:<userId>` — and is spent BEFORE any ownership
     * lookup (over-budget traffic must not cost the DB a server read).
     */
    public function testMintHitsMemberKeyedBudgetBeforeOwnershipLookup(): void
    {
        /** @var list<string> $order */
        $order = [];
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')
            ->willReturnCallback(function (string $id) use (&$order): ?ServerInfoDto {
                $order[] = 'lookup';
                return null;
            });

        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())
            ->method('hit')
            ->with('client_relay_token:user-1')
            ->willReturnCallback(function (string $key) use (&$order): RateLimitState {
                $order[] = 'budget';
                return new RateLimitState(count: 4, remaining: 6, resetAt: time() + 40, limited: false, limit: 10);
            });

        $controller = $this->controller($info, $this->tokenService(), null, $limiter);

        $response = $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
        // Never limited => flow continues to the ownership lookup, which is
        // mocked to say 'unknown server' => 404. The budget was spent FIRST.
        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['budget', 'lookup'], $order);
    }

    /**
     * M-HUB-22: over-budget throws the canonical RateLimitException so the
     * central dispatch maps the 429 + Retry-After envelope — the controller
     * never mints locally on the way.
     */
    public function testOverBudgetMintThrowsRateLimitBeforeMinting(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->expects($this->never())->method('getServerInfo');

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('hit')->willReturn(
            new RateLimitState(count: 11, remaining: 0, resetAt: time() + 20, limited: true, limit: 10),
        );

        $controller = $this->controller($info, new ClientRelayTokenService($db), null, $limiter);

        $this->expectException(RateLimitException::class);
        $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
    }

    /**
     * M-HUB-22: the transition request (count lands exactly ON the limit)
     * records ONE logFailedAuth — the failed-auth audit law of every
     * member-keyed HTTP limiter here — and later over-limit hits inside the
     * same window do not spam it.
     */
    public function testBoundaryRejectionAuditsFailedAuthExactlyOnce(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())
            ->method('logFailedAuth')
            ->with('relay_token_mint_rate_limited', ['user_id' => 'user-1']);

        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('hit')->willReturn(
            new RateLimitState(count: 10, remaining: 0, resetAt: time() + 30, limited: true, limit: 10),
        );

        $controller = $this->controller($info, $this->tokenService(), $audit, $limiter);

        try {
            $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
            $this->fail('expected RateLimitException');
        } catch (RateLimitException) {
            // expected — central dispatch turns this into the 429 envelope.
        }
    }

    /**
     * M-HUB-22: un-wired posture (null limiter, e.g. a venue without the
     * profile binding) stays PRE-CURE rather than failing closed — the
     * invite-redeem precedent; minting proceeds.
     */
    public function testNullLimiterProceedsPreCure(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1'));

        $controller = $this->controller($info, $this->tokenService(), null, null);

        $response = $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
        $this->assertSame(201, $response->statusCode);
    }

    /**
     * M-HUB-22: when the service's scope cap evicted older tokens, the
     * controller surfaces it in the audit trail as relay_token.prune_overflow.
     */
    public function testPrunedOverflowIsAudited(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1'));

        // The service is final -> drive its cap through a fake DB: the cap
        // SELECT reports 3 overflow rows and the revoke UPDATE reports 3.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) {
                if (str_contains($sql, 'SELECT id FROM client_relay_tokens')) {
                    return [['id' => 'old-1'], ['id' => 'old-2'], ['id' => 'old-3']];
                }
                if (str_contains($sql, 'UPDATE client_relay_tokens SET revoked_at = NOW()')) {
                    return 3;
                }
                return [];
            },
        );
        $tokens = new ClientRelayTokenService($db);

        /** @var list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}> $rows */
        $rows = [];
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logAdminAction')->willReturnCallback(
            function (string $userId, string $action, string $target, array $meta = []) use (&$rows): void {
                $rows[] = [$userId, $action, $target, $meta];
            },
        );

        $controller = $this->controller($info, $tokens, $audit);

        $response = $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
        $this->assertSame(201, $response->statusCode);
        $this->assertSame(
            ['user-1', 'relay_token.prune_overflow', 'srv-1', ['pruned' => 3]],
            $rows[1] ?? null,
            'overflow evictions must be audited right after the mint row',
        );
    }

    /**
     * The ordinary (no-overflow) mint audits EXACTLY one row — the mint
     * itself; the prune_overflow row only appears when the cap evicted.
     */
    public function testOrdinaryMintAuditsOnlyTheMintRow(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1'));

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())->method('logAdminAction'); // the mint row only

        $controller = $this->controller($info, $this->tokenService(), $audit);

        $response = $controller->mint($this->request('user-1'), ['id' => 'srv-1']);
        $this->assertSame(201, $response->statusCode);
    }
}
