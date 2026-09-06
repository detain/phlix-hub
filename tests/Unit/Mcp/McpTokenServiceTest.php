<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpTokenService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function hash;
use function str_contains;
use function strlen;
use function time;

/**
 * Unit tests for {@see McpTokenService}.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 */
final class McpTokenServiceTest extends TestCase
{
    public function testMintPersistsOnlyAHashNeverThePlaintext(): void
    {
        $db = $this->createMock(Connection::class);

        /** @var array<string, mixed> $captured */
        $captured = [];
        $db->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): array {
                self::assertStringContainsString('INSERT INTO mcp_tokens', $sql);
                $captured = $params;

                return [];
            });

        $minted = (new McpTokenService($db))->mint('user-1', 'Claude Desktop', McpScopes::all());

        self::assertSame(hash('sha256', $minted['token']), $captured['token_hash']);
        self::assertNotSame($minted['token'], $captured['token_hash']);
        self::assertSame('user-1', $captured['user_id']);
        self::assertSame('Claude Desktop', $captured['name']);

        // Nothing resembling the plaintext reaches the database.
        foreach ($captured as $value) {
            self::assertNotSame($minted['token'], $value);
        }
    }

    public function testAMintedTokenCarriesThePrefixAndEnoughEntropy(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $service = new McpTokenService($db);

        $a = $service->mint('user-1', '', McpScopes::all())['token'];
        $b = $service->mint('user-1', '', McpScopes::all())['token'];

        self::assertStringStartsWith(McpTokenService::TOKEN_PREFIX, $a);
        self::assertNotSame($a, $b);
        // Prefix + 64 hex chars = 256 bits of CSPRNG material.
        self::assertSame(strlen(McpTokenService::TOKEN_PREFIX) + 64, strlen($a));
        self::assertTrue(McpTokenService::looksLikeMcpToken($a));
        self::assertFalse(McpTokenService::looksLikeMcpToken('eyJhbGciOiJIUzI1NiJ9.x'));
    }

    public function testMintDropsUnknownScopesRatherThanStoringThem(): void
    {
        $db = $this->createMock(Connection::class);
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $minted = (new McpTokenService($db))->mint('user-1', '', [McpScopes::SERVERS_READ, 'admin:*']);

        self::assertSame([McpScopes::SERVERS_READ], $minted['scopes']);
        self::assertSame(McpScopes::SERVERS_READ, $captured['scopes']);
    }

    public function testTheDefaultTtlIsLongButFinite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $before = time();
        $minted = (new McpTokenService($db))->mint('user-1', '', McpScopes::all());

        self::assertGreaterThan($before, $minted['expires_at']);
        self::assertSame($before + McpTokenService::DEFAULT_TTL_SECONDS, $minted['expires_at']);
        // "Long" means months, not forever: a perpetual token is not on offer.
        self::assertGreaterThan(30 * 86400, McpTokenService::DEFAULT_TTL_SECONDS);
        self::assertLessThan(400 * 86400, McpTokenService::DEFAULT_TTL_SECONDS);
    }

    public function testANonPositiveTtlFallsBackToTheDefault(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $before = time();
        $minted = (new McpTokenService($db, 0))->mint('user-1', '', McpScopes::all());

        self::assertSame($before + McpTokenService::DEFAULT_TTL_SECONDS, $minted['expires_at']);
    }

    public function testValidateRequiresAnActiveUnexpiredUnrevokedRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): array {
                self::assertStringContainsString('FROM mcp_tokens', $sql);
                self::assertStringContainsString('revoked_at IS NULL', $sql);
                self::assertStringContainsString('expires_at > NOW()', $sql);
                self::assertSame(hash('sha256', 'phlix-mcp-abc'), $params['token_hash']);

                return [[
                    'id' => 'row-1',
                    'user_id' => 'user-1',
                    'scopes' => McpScopes::SERVERS_READ . ' ' . McpScopes::LIBRARY_READ,
                ]];
            });

        $token = (new McpTokenService($db))->validate('phlix-mcp-abc');

        self::assertNotNull($token);
        self::assertSame('row-1', $token->id);
        self::assertSame('user-1', $token->userId);
        self::assertTrue($token->hasScope(McpScopes::LIBRARY_READ));
        self::assertFalse($token->hasScope(McpScopes::PLAYBACK_READ));
    }

    /**
     * @dataProvider unusableRowProvider
     */
    public function testValidateFailsClosed(mixed $rows): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);

        self::assertNull((new McpTokenService($db))->validate('phlix-mcp-abc'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableRowProvider(): array
    {
        return [
            'no rows' => [[]],
            'not an array' => [false],
            'row is not an array' => [['nonsense']],
            'missing user_id' => [[['id' => 'row-1', 'scopes' => '']]],
            'empty user_id' => [[['id' => 'row-1', 'user_id' => '', 'scopes' => '']]],
            'non-string id' => [[['id' => 7, 'user_id' => 'user-1', 'scopes' => '']]],
        ];
    }

    public function testAnEmptyTokenIsRejectedWithoutAQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');

        self::assertNull((new McpTokenService($db))->validate(''));
    }

    /**
     * The `user_id` predicate on revoke is load-bearing: without it, learning a
     * token id would let anyone cut somebody else's credential.
     */
    public function testRevokeIsScopedToTheOwningUser(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): int {
                self::assertStringContainsString('UPDATE mcp_tokens', $sql);
                self::assertStringContainsString('user_id = :user_id', $sql);
                self::assertSame('user-1', $params['user_id']);
                self::assertSame('row-1', $params['id']);

                return 1;
            });

        self::assertTrue((new McpTokenService($db))->revokeForUser('user-1', 'row-1'));
    }

    public function testRevokingNothingReportsFalse(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(0);

        self::assertFalse((new McpTokenService($db))->revokeForUser('user-1', 'row-1'));
    }

    public function testRevokeShortCircuitsOnEmptyIdentifiers(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');

        $service = new McpTokenService($db);
        self::assertFalse($service->revokeForUser('', 'row-1'));
        self::assertFalse($service->revokeForUser('user-1', ''));
    }

    public function testListForUserReturnsMetadataAndNeverTheHash(): void
    {
        $now = time();
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($now): array {
                if (!str_contains($sql, 'FROM mcp_tokens')) {
                    return [];
                }

                return [
                    [
                        'id' => 'row-1',
                        'name' => 'Claude Desktop',
                        'scopes' => McpScopes::SERVERS_READ,
                        'created_ts' => $now - 100,
                        'expires_ts' => $now + 1000,
                        'last_used_ts' => null,
                        'revoked_at' => null,
                    ],
                    [
                        'id' => 'row-2',
                        'name' => 'Old',
                        'scopes' => '',
                        'created_ts' => (string) ($now - 100000),
                        'expires_ts' => (string) ($now - 10),
                        'last_used_ts' => (string) ($now - 50),
                        'revoked_at' => '2026-01-01 00:00:00',
                    ],
                    'not a row',
                    ['id' => 42],
                ];
            },
        );

        $rows = (new McpTokenService($db))->listForUser('user-1');

        self::assertCount(2, $rows, 'malformed rows must be skipped, not half-read.');
        self::assertSame('row-1', $rows[0]['id']);
        self::assertFalse($rows[0]['revoked']);
        self::assertFalse($rows[0]['expired']);
        self::assertNull($rows[0]['last_used_at']);
        self::assertTrue($rows[1]['revoked']);
        self::assertTrue($rows[1]['expired']);
        self::assertSame($now - 50, $rows[1]['last_used_at']);

        foreach ($rows as $row) {
            self::assertArrayNotHasKey('token_hash', $row);
            self::assertArrayNotHasKey('token', $row);
        }
    }

    /**
     * The sweep must be an OR, not an AND: with AND the common
     * expired-never-revoked row would accumulate forever.
     */
    public function testPruneRemovesLongExpiredOrRevokedRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql): int {
                self::assertStringContainsString('DELETE FROM mcp_tokens', $sql);
                self::assertStringContainsString(' OR revoked_at IS NOT NULL', $sql);
                self::assertStringNotContainsString(' AND revoked_at IS NOT NULL', $sql);

                return 3;
            });

        self::assertSame(3, (new McpTokenService($db))->pruneExpiredTokens());
    }

    public function testTouchUpdatesLastUsedAndIgnoresAnEmptyId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): int {
                self::assertStringContainsString('last_used_at = NOW()', $sql);
                self::assertSame('row-1', $params['id']);

                return 1;
            });

        $service = new McpTokenService($db);
        $service->touch('');
        $service->touch('row-1');
    }
}
